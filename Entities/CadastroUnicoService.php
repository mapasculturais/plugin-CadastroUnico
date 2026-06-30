<?php
/**
 * Cadastro Único — Service canônico de status (single source of truth).
 *
 * Centraliza TODA a lógica de computação do status consolidado do Cadastro
 * Único de um agente. É consumido por 3 superfícies sem duplicação:
 *
 *   1. Endpoint    API_status()  no Controller
 *   2. Widget      init.php      do componente
 *   3. Tarja       hook printJsObject:before
 *
 * DÍVIDA TÉCNICA RESOLVIDA: antes deste Service, a lógica de
 * montagem do payload estava duplicada entre Controller e init.php. Agora
 * ambos delegam aqui.
 *
 * SHAPE DO PAYLOAD:
 *
 *   [
 *     'opportunity' => ['id' => int, 'singleUrl' => string],
 *     'agent'       => ['id' => int, 'name' => string],
 *     'categorias'  => [
 *       [
 *         'slug'           => 'certidoes',
 *         'nome'           => 'Certidões',
 *         'obrigatoria'    => true,
 *         'inscricao'      => ['id','status','sentTimestamp'(ISO),'sentDate'(d/m/Y)] | null,
 *         'selo'           => [
 *           'id','relationId','computedStatus',
 *           'validateDate','partialInvalidDate','partialInvalidField',
 *           'invalidDate','invalidField','fields[]'
 *         ] | null,
 *         'derivedStatus'  => 'pending'|'fully_valid'|'partially_valid'|'invalid',
 *       ], ...
 *     ],
 *     'tarja' => [
 *       'visivel'        => bool,           // condição disparadora real
 *       'condicao'       => string,         // enum priorizado
 *       'prioridade'     => string,         // alias de condicao
 *       'detalhes'       => array,          // placeholders para texts.php
 *       'suppressForRole'=> bool,           // admin/gestor não veem tarja
 *     ],
 *   ]
 *
 * CACHE: a tarja roda em TODA página. Para evitar N+1 queries por
 * request, há cache APCu (via wrapper $app->cache do Symfony Cache) com
 * chave por user_id, TTL 60s. Invalidado por hooks de lifecycle em
 * Plugin::_init() (Registration.status/insert, AgentSealRelation
 * insert/remove/update). O endpoint e o init.php NÃO usam cache (sempre
 * frescos); apenas a tarja lê do cache.
 *
 * @package    CadastroUnico
 * @subpackage Entities
 */

namespace CadastroUnico\Entities;

use CadastroUnico\Setup;
use MapasCulturais\App;
use MapasCulturais\Entities\Agent;
use MapasCulturais\Entities\AgentSealRelation;
use MapasCulturais\Entities\Opportunity;
use MapasCulturais\Entities\Registration;
use MapasCulturais\Entities\RegistrationFieldConfiguration;
use MapasCulturais\Entities\RegistrationMeta;
use MapasCulturais\Entities\Seal;
use MapasCulturais\Entities\SealRelationField;

class CadastroUnicoService
{
    public const OPTIONAL_CATEGORIES = [
        Setup::CATEGORY_AUTODECLARACOES,
    ];

    public const CACHE_KEY_PREFIX = 'cu-status-';

    public const CACHE_TTL = 60;

    /**
     * Constrói o payload completo do status do Cadastro Único do agente.
     *
     * Ponto único de verdade — chamado por:
     *   - Controllers\CadastroUnico::API_status() (endpoint)
     *   - components/panel--cadastro-unico/init.php (dashboard widget)
     *   - Plugin::_init() hook printJsObject:before (tarja, via cache)
     *
     * @param App         $app
     * @param Agent|null  $target_agent Agente alvo. Se null, retorna null.
     * @return array|null
     */
    public static function buildStatusPayload(App $app, ?Agent $target_agent): ?array
    {
        if (!$target_agent || !$target_agent->id) {
            return null;
        }

        $opportunity = self::findOpportunity($app);
        if (!$opportunity) {
            return null;
        }

        $category_seals_raw = $opportunity->cadastroUnicoCategorySeals;
        $category_seals = is_object($category_seals_raw)
            ? (array) $category_seals_raw
            : (array) json_decode($category_seals_raw ?? '{}', true);

        $categories_status = [];
        foreach (Setup::CATEGORIES as $slug => $label) {
            $seal_id = $category_seals[$slug] ?? null;
            $seal = $seal_id ? $app->repo('Seal')->find($seal_id) : null;

            $category = self::buildCategoryStatus(
                $app,
                $opportunity,
                $target_agent,
                $slug,
                $label,
                $seal
            );

            $category['derivedStatus'] = $category['selo']['computedStatus'] ?? 'pending';

            $categories_status[] = $category;
        }

        $banner = self::computeBannerCondition($app, $opportunity, $categories_status);

        return [
            'opportunity' => [
                'id'        => $opportunity->id,
                'singleUrl' => $app->createUrl('cadastroUnico', 'single'),
            ],
            'agent' => [
                'id'   => $target_agent->id,
                'name' => $target_agent->name,
            ],
            'categorias' => $categories_status,
            'tarja' => $banner,
        ];
    }

    /**
     * Lê o payload cacheado para o user_id. Usado pelo hook da tarja.
     *
     * @return array|null null se cache miss ou desabilitado.
     */
    public static function cacheFetch(App $app, int $user_id): ?array
    {
        if (!self::cacheAvailable($app)) {
            return null;
        }
        $value = $app->cache->fetch(self::cacheKey($user_id));
        return is_array($value) ? $value : null;
    }

    /**
     * Grava o payload no cache para o user_id. Falha silenciosa se cache
     * indisponível (a tarja recomputará no próximo request).
     */
    public static function cacheStore(App $app, int $user_id, array $payload): void
    {
        if (!self::cacheAvailable($app)) {
            return;
        }
        $app->cache->save(self::cacheKey($user_id), $payload, self::CACHE_TTL);
    }

    /**
     * Invalida o cache do user_id. Chamado pelos hooks de lifecycle em
     * Plugin::_init(). Idempotente (delete em chave inexistente é no-op).
     */
    public static function cacheDelete(App $app, int $user_id): void
    {
        if (!self::cacheAvailable($app)) {
            return;
        }
        $app->cache->delete(self::cacheKey($user_id));
    }

    /**
     * Resolve o user_id dono de um agente (para invalidação de cache).
     * Retorna null se o agente não tem user associado (edge case raro).
     */
    public static function resolveUserIdForAgent(App $app, Agent $agent): ?int
    {
        $user = $agent->user ?? null;
        return $user ? (int) $user->id : null;
    }


    /**
     * Localiza a oportunidade do Cadastro Único.
     *
     * Única por instalação (idempotência do seed — DT-03).
     *
     * Implementação: query em opportunity_meta (NÃO findOneBy no repo
     * Opportunity com chave de metadata — isso gera UnrecognizedField
     * porque isCadastroUnico é metadata, não coluna Doctrine).
     *
     * @return Opportunity|null null se plugin ainda não instalado
     *                          (db-update não rodou) ou oportunidade
     *                          removida manualmente.
     */
    public static function findCadastroUnicoOpportunity(App $app): ?Opportunity
    {
        $conn = $app->em->getConnection();
        $id = $conn->fetchOne(
            "SELECT o.id
             FROM opportunity o
             INNER JOIN opportunity_meta om ON om.object_id = o.id
             WHERE om.key = 'isCadastroUnico' AND om.value = '1'
             LIMIT 1"
        );

        if (!$id) {
            return null;
        }

        return $app->repo('Opportunity')->find($id) ?: null;
    }

    /**
     * Localiza o selo de uma categoria do Cadastro Único.
     *
     * @param App    $app
     * @param string $slug Slug da categoria (Setup::CATEGORY_*).
     * @return Seal|null null se o selo não existe (seed incompleto ou
     *                   categoria inválida).
     */
    public static function findSealByCategorySlug(App $app, string $slug): ?Seal
    {
        $conn = $app->em->getConnection();
        $id = $conn->fetchOne(
            "SELECT s.id
             FROM seal s
             INNER JOIN seal_meta sm ON sm.object_id = s.id
             WHERE sm.key = 'isCadastroUnicoCategory' AND sm.value = " . $conn->quote($slug) . "
             LIMIT 1"
        );

        if (!$id) {
            return null;
        }

        return $app->repo('Seal')->find($id) ?: null;
    }

    // ==================================================================
    // Helpers internos
    // ==================================================================

    /**
     * Localiza a oportunidade do Cadastro Único. Única por instalação
     * Delega para o helper público.
     */
    private static function findOpportunity(App $app): ?Opportunity
    {
        return self::findCadastroUnicoOpportunity($app);
    }

    /**
     * Constrói o status de uma categoria (slug) para o agente alvo.
     */
    private static function buildCategoryStatus(
        App $app,
        Opportunity $opportunity,
        Agent $agent,
        string $slug,
        string $label,
        ?Seal $seal
    ): array {
        return [
            'slug'        => $slug,
            'nome'        => $label,
            'obrigatoria' => !in_array($slug, self::OPTIONAL_CATEGORIES, true),
            'inscricao'   => self::findRegistration($app, $opportunity, $agent, $label),
            'selo'        => $seal ? self::buildSealStatus($app, $agent, $seal) : null,
        ];
    }

    /**
     * Busca a inscrição do agente nesta oportunidade e categoria.
     *
     * Considera apenas inscrições "ativas" (status >= 0) — rascunhos,
     * enviadas, aprovadas, etc. Inscrições na lixeira (status < 0) são
     * ignoradas. A constraint única garante unicidade para
     * status >= 0.
     *
     * @return array|null Shape do subobjeto `inscricao`, ou null.
     */
    private static function findRegistration(
        App $app,
        Opportunity $opportunity,
        Agent $agent,
        string $label
    ): ?array {
        $candidates = $app->repo('Registration')->findBy([
            'opportunity' => $opportunity,
            'owner'       => $agent,
            'category'    => $label,
        ]);

        foreach ($candidates as $registration) {
            if ($registration->status >= 0) {
                return [
                    'id'            => $registration->id,
                    'status'        => $registration->status,
                    'sentTimestamp' => $registration->sentTimestamp
                        ? $registration->sentTimestamp->format(\DateTimeInterface::ATOM)
                        : null,
                    'sentDate'      => $registration->sentTimestamp
                        ? $registration->sentTimestamp->format('d/m/Y')
                        : null,
                ];
            }
        }

        return null;
    }

    /**
     * Constrói o status do selo aplicado ao agente, com campos granulares
     * e datas de invalidação (formato DD/MM/AAAA).
     *
     * @return array|null null se o selo não está aplicado ao agente.
     */
    private static function buildSealStatus(App $app, Agent $agent, Seal $seal): ?array
    {
        $relation = $app->repo('AgentSealRelation')->findOneBy([
            'owner' => $agent,
            'seal'  => $seal,
        ]);

        if (!$relation) {
            return null;
        }

        $computed_status = $relation->getComputedStatus();

        $fields = $relation->getSealRelationFields();
        $fields_payload = [];
        foreach ($fields as $field) {
            $fields_payload[] = [
                'fieldName'     => $field->fieldName,
                'fieldStatus'   => $field->getFieldStatus(),
                'expiryDate'    => $field->expiryDate
                    ? $field->expiryDate->format('d/m/Y')
                    : null,
                'isInvalidator' => (bool) $field->isInvalidator,
            ];
        }

        [$invalid_date, $invalid_field] = self::earliestExpired($fields, invalidator: true);
        [$partial_invalid_date, $partial_invalid_field] = self::earliestExpired($fields, invalidator: false);

        return [
            'id'                  => $seal->id,
            'relationId'          => $relation->id,
            'computedStatus'      => $computed_status,
            'validateDate'        => $relation->validateDate
                ? $relation->validateDate->format('d/m/Y')
                : null,
            'partialInvalidDate'  => $partial_invalid_date,
            'partialInvalidField' => $partial_invalid_field,
            'invalidDate'         => $invalid_date,
            'invalidField'        => $invalid_field,
            'fields'              => $fields_payload,
        ];
    }

    /**
     * Computa a data mais antiga de expiração entre os campos do selo,
     * filtrando por `isInvalidator` e considerando apenas campos
     * efetivamente expirados (getFieldStatus() === 'expired').
     *
     * Retorna no formato DD/MM/AAAA, junto do fieldName correspondente.
     *
     * @param SealRelationField[] $fields
     * @param bool                $invalidator true para invalidadores,
     *                                         false para não-invalidadores.
     * @return array{0:string|null,1:string|null} [date DD/MM/AAAA, fieldName]
     */
    private static function earliestExpired(array $fields, bool $invalidator): array
    {
        $earliest = null;
        $earliest_field_name = null;

        foreach ($fields as $field) {
            if ((bool) $field->isInvalidator !== $invalidator) {
                continue;
            }
            if ($field->getFieldStatus() !== 'expired') {
                continue;
            }
            if (!$field->expiryDate) {
                continue;
            }
            if ($earliest === null || $field->expiryDate < $earliest) {
                $earliest = $field->expiryDate;
                $earliest_field_name = $field->fieldName;
            }
        }

        if ($earliest === null) {
            return [null, null];
        }

        return [$earliest->format('d/m/Y'), $earliest_field_name];
    }

    /**
     * Computa a condição da tarja amarela.
     *
     * Condições que tornam a tarja visível (visivel=true):
     *   1. sem_cadastro — agente sem inscrição em QUALQUER categoria.
     *   2. categoria_obrigatoria_faltante — falta inscrição em pelo menos uma das obrigatórias (certidoes, documentos). Autodeclarações (opcional) NÃO conta.
     *   3. Selo não-válido — algum selo aplicado com computedStatus 'partially_valid' ou 'invalid'.
     *
     * Prioridade: selo_vencido_obrigatorio > categoria_obrigatoria_faltante > selo_vencido_opcional > sem_cadastro.
     *
     * @param App         $app
     * @param Opportunity $opportunity       (já resolvido pelo caller).
     * @param array       $categories_status Output de buildCategoryStatus().
     * @return array{visivel:bool, condicao:string, prioridade:string, detalhes:array, suppressForRole:bool}
     */
    private static function computeBannerCondition(
        App $app,
        Opportunity $opportunity,
        array $categories_status
    ): array {
        $has_any_registration = false;
        foreach ($categories_status as $cat) {
            if ($cat['inscricao'] !== null) {
                $has_any_registration = true;
                break;
            }
        }

        $missing_required = [];
        foreach ($categories_status as $cat) {
            if ($cat['obrigatoria'] && $cat['inscricao'] === null) {
                $missing_required[] = $cat['nome'];
            }
        }

        $has_invalid_seal_required = false;
        $has_invalid_seal_optional = false;
        $first_invalid_required_cat = null;
        $first_invalid_optional_cat = null;
        foreach ($categories_status as $cat) {
            $seal_status = $cat['selo']['computedStatus'] ?? null;
            $is_invalid = in_array($seal_status, ['partially_valid', 'invalid'], true);
            if (!$is_invalid) {
                continue;
            }
            if ($cat['obrigatoria']) {
                $has_invalid_seal_required = true;
                $first_invalid_required_cat = $first_invalid_required_cat ?? $cat;
            } else {
                $has_invalid_seal_optional = true;
                $first_invalid_optional_cat = $first_invalid_optional_cat ?? $cat;
            }
        }

        $visivel = !$has_any_registration
            || !empty($missing_required)
            || $has_invalid_seal_required
            || $has_invalid_seal_optional;

        if (!$visivel) {
            $condicao = 'ok';
            $prioridade = 'nenhuma';
            $detalhes = [];
        } elseif (!$has_any_registration) {
            $condicao = 'sem_cadastro';
            $prioridade = 'sem_cadastro';
            $detalhes = [];
        } elseif ($has_invalid_seal_required) {
            $condicao = 'selo_vencido_obrigatorio';
            $prioridade = 'selo_vencido_obrigatorio';
            $detalhes = self::buildBannerDetails($first_invalid_required_cat);
        } elseif (!empty($missing_required)) {
            $condicao = 'categoria_obrigatoria_faltante';
            $prioridade = 'categoria_obrigatoria_faltante';
            $first_missing = null;
            foreach ($categories_status as $cat) {
                if ($cat['obrigatoria'] && $cat['inscricao'] === null) {
                    $first_missing = $cat;
                    break;
                }
            }
            $detalhes = $first_missing ? [
                'categoria' => $first_missing['nome'],
                'categoriaSlug' => $first_missing['slug'],
            ] : [];
            $detalhes['categoriasFaltantes'] = $missing_required;
        } elseif ($has_invalid_seal_optional) {
            $condicao = 'selo_vencido_opcional';
            $prioridade = 'selo_vencido_opcional';
            $detalhes = self::buildBannerDetails($first_invalid_optional_cat);
        } else {
            $condicao = 'sem_cadastro';
            $prioridade = 'sem_cadastro';
            $detalhes = [];
        }

        $is_admin = $app->user->is('admin');
        $is_gestor = $opportunity->canUser('@control', $app->user);

        return [
            'visivel'         => $visivel,
            'condicao'        => $condicao,
            'prioridade'      => $prioridade,
            'detalhes'        => $detalhes,
            'suppressForRole' => $is_admin || $is_gestor,
        ];
    }

    /**
     * Constrói o subobjeto `detalhes` para condições de selo vencido. Extrai categoria/Slug + campo + data da categoria afetada.
     *
     * Preferência: se há `invalidField` (invalidador expirado → selo invalid), usa este (causa mais grave). Senão usa `partialInvalidField` (não-invalidador expirado → partially_valid).
     *
     * @param array|null $cat Categoria com selo vencido.
     * @return array{categoria:string, categoriaSlug:string, campo:string|null, data:string|null}
     */
    private static function buildBannerDetails(?array $cat): array
    {
        if (!$cat || !$cat['selo']) {
            return [];
        }

        $seal = $cat['selo'];

        $campo_expirando = $seal['invalidField'] ?? $seal['partialInvalidField'] ?? null;
        $data_expiracao = $seal['invalidDate'] ?? $seal['partialInvalidDate'] ?? null;

        return [
            'categoria'  => $cat['nome'],
            'categoriaSlug'  => $cat['slug'],
            'campo' => $campo_expirando,
            'data'  => $data_expiracao,
        ];
    }

    /**
     * Verifica se o cache está disponível. Em ambientes sem APCu/Redis (ex.: alguns setups de teste), o $app->cache pode não ter adapter — este guard evita fatal.
     */
    private static function cacheAvailable(App $app): bool
    {
        try {
            return $app->cache !== null;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Monta a chave de cache para o user_id.
     */
    private static function cacheKey(int $user_id): string
    {
        return self::CACHE_KEY_PREFIX . $user_id;
    }

    /**
     * Computa se um campo @ do formulário é editável dado seu SealRelationField.
     *
     * Regra:
     *   - Sem SealRelationField (caso inicial, campo nunca preenchido OU
     *     selo ainda não aplicado) → EDITÁVEL (true).
     *   - Status `valid` (campo válido) → READ-ONLY (false).
     *   - Status `no_expiration` (campo sem expiração configurada) →
     *     tratado como válido perpétuo → READ-ONLY (false).
     *   - Status `expired` → EDITÁVEL (true) — usuário precisa reenviar.
     *   - Status `about_to_expire` (janela de 7 dias) → EDITÁVEL
     *     (true) — permite atualização preventiva.
     *
     * @param SealRelationField|null $srf Campo granular do selo. Null se
     *     não há selo aplicado OU o campo não está mapeado no selo.
     * @return bool true = editável, false = read-only.
     */
    public static function computeFieldEditable(?SealRelationField $srf): bool
    {
        if (!$srf) {
            return true;
        }

        $status = $srf->getFieldStatus();

        switch ($status) {
            case 'valid':
            case 'no_expiration':
                // Válido (com ou sem expiração configurada) → read-only.
                return false;

            case 'expired':
            case 'about_to_expire':
                // Expirado ou prestes a expirar → editável para atualização.
                return true;

            default:
                // Estado desconhecido (futuras adições no core): default
                // editável é mais seguro (não bloqueia o usuário).
                return true;
        }
    }

    /**
     * Constrói a lista de campos do formulário de uma categoria, com
     * metadata completa + flag `editable` por campo.
     *
     * Cruza 3 fontes:
     *   1. RegistrationFieldConfiguration da oportunidade filtrada por
     *      categoria (campo `categories` contém o label da categoria).
     *   2. SealRelationField[] do selo aplicado ao agente — para obter
     *      o status granular de cada campo.
     *   3. Contrato: SealRelationField.fieldName === "agent." +
     *      RegistrationFieldConfiguration.config.entityField.
     *
     * @param App         $app
     * @param Opportunity $opportunity
     * @param Agent       $agent
     * @param string      $category_label Label legível (ex.: "Certidões").
     *                                     Casa com RegistrationFieldConfiguration
     *                                     .categories e Registration.category.
     * @param Seal|null   $seal Selo da categoria (para buscar SealRelationFields).
     * @return array Lista de campos, cada um com:
     *     [
     *       'id', 'fieldName', 'fieldType', 'title', 'description',
     *       'required', 'config', 'groupName' (para entity-file),
     *       'maxSize', 'allowedFileTypes', 'editable' (bool),
     *       'sealFieldStatus' (string|null),
     *     ]
     */
    public static function buildCategoryFields(
        App $app,
        Opportunity $opportunity,
        Agent $agent,
        string $category_label,
        ?Seal $seal
    ): array {
        $all_field_configs = $app->repo('RegistrationFieldConfiguration')->findBy([
            'owner' => $opportunity,
        ]);

        $seal_fields_by_name = [];
        if ($seal) {
            $relation = $app->repo('AgentSealRelation')->findOneBy([
                'owner' => $agent,
                'seal'  => $seal,
            ]);
            if ($relation) {
                foreach ($relation->getSealRelationFields() as $srf) {
                    $seal_fields_by_name[$srf->fieldName] = $srf;
                }
            }
        }

        $result = [];
        foreach ($all_field_configs as $fc) {
            $cats = (array) ($fc->categories ?: []);
            if (!empty($cats) && !in_array($category_label, $cats, true)) {
                continue;
            }

            $config = $fc->config ?: [];
            $entity_field = is_object($config)
                ? ($config->entityField ?? null)
                : ($config['entityField'] ?? null);

            $seal_field_key = $entity_field ? ('agent.' . $entity_field) : null;
            $srf = $seal_field_key ? ($seal_fields_by_name[$seal_field_key] ?? null) : null;

            $editable = self::computeFieldEditable($srf);

            $result[] = [
                'id'              => $fc->id,
                'fieldName'       => $fc->getFieldName(),
                'fieldType'       => $fc->fieldType,
                'title'           => $fc->title,
                'description'     => $fc->description,
                'required'        => (bool) $fc->required,
                'config'          => $config,
                'groupName'       => 'rfc_' . $fc->id,
                'maxSize'         => $fc->maxSize,
                'allowedFileTypes' => is_object($config)
                    ? ($config->allowedFileTypes ?? [])
                    : ($config['allowedFileTypes'] ?? []),
                'editable'        => $editable,
                'sealFieldStatus' => $srf ? $srf->getFieldStatus() : null,
            ];
        }

        return $result;
    }

    /**
     * Decide se a seção de uma categoria deve abrir automaticamente na
     * carga da página ( Fatia E, briefing "seção expande automaticamente").
     *
     * Regras (T22 estados da seção):
     *   - Sem inscrição → NÃO abre (usuário verá CTA "Começar categoria").
     *   - Inscrição draft (status < 10) → ABRE (precisa preencher).
     *   - Tudo fully_valid → NÃO abre (nada urgente; usuário pode conferir).
     *   - Misto (alguns fully_valid, alguns editáveis) → ABRE (há ação
     *     pendente: campos a atualizar).
     *
     * @param array $category_status Saída de buildStatusPayload()['categorias'][i].
     * @param array $fields           Saída de buildCategoryFields().
     * @return bool
     */
    public static function shouldSectionAutoOpen(array $category_status, array $fields): bool
    {
        $registration_status = $category_status['inscricao']['status'] ?? null;
        if ($registration_status === null) {
            return false;
        }

        if ($registration_status < 1) {
            return true;
        }

        $seal_status = $category_status['selo']['computedStatus'] ?? null;
        if ($seal_status === 'fully_valid') {
            return false;
        }

        $has_editable = false;
        foreach ($fields as $field) {
            if (!empty($field['editable'])) {
                $has_editable = true;
                break;
            }
        }

        return $has_editable || $seal_status !== 'fully_valid';
    }

    /**
     * Verifica se um RegistrationMeta (campo @ de inscrição do Cadastro
     * Único) pode ser escrito no estado atual.
     *
     * REGRA:
     *   - Campo @ cujo SealRelationField correspondente está em estado
     *     'valid' ou 'no_expiration' → NÃO editável.
     *   - Campo @ cujo SealRelationField está 'about_to_expire' ou
     *     'expired' → editável.
     *   - Campo @ sem SealRelationField correspondente (configuração
     *     inicial, sem selo aplicado) → editável.
     *   - Campo não-@ (file, section, etc.) → editável.
     *   - Inscrição de oportunidade que NÃO é Cadastro Único → editável.
     *
     * @param App             $app
     * @param RegistrationMeta $meta  $this dentro do hook save:before.
     * @return array{editable:bool, reason:string|null, fieldTitle:string|null, fieldName:string|null}
     *     - editable: true se pode escrever; false se deve ser bloqueado.
     *     - reason: código curto para logging/debugging (não i18n).
     *     - fieldTitle: título legível do campo (para mensagem de erro).
     *     - fieldName: SealRelationField.fieldName esperado (para debug).
     */
    public static function isFieldEditable(App $app, RegistrationMeta $meta): array
    {
        $default_editable = [
            'editable'   => true,
            'reason'     => null,
            'fieldTitle' => null,
            'fieldName'  => null,
        ];

        $registration = $meta->owner;
        if (!$registration) {
            return $default_editable;
        }

        $opportunity = $registration->opportunity;
        if (!$opportunity || !$opportunity->isCadastroUnico) {
            return $default_editable;
        }

        $meta_key = (string) ($meta->key ?? '');
        if (!preg_match('/^field_(\d+)$/', $meta_key, $m)) {
            return $default_editable;
        }
        $field_config_id = (int) $m[1];

        $field_config = $app->repo('RegistrationFieldConfiguration')->find($field_config_id);
        if (!$field_config) {
            return $default_editable;
        }

        if ($field_config->fieldType !== 'agent-owner-field') {
            return $default_editable;
        }

        $field_config_array = (array) ($field_config->config ?? []);
        $entity_field = $field_config_array['entityField'] ?? null;
        if (!$entity_field) {
            return $default_editable;
        }

        $expected_srf_field_name = 'agent.' . $entity_field;

        $category_label = $registration->category;
        $category_slug = array_search($category_label, Setup::CATEGORIES, true);
        if ($category_slug === false) {
            return $default_editable;
        }

        $opportunity = self::findOpportunity($app);
        if (!$opportunity) {
            return $default_editable;
        }
        $category_seals = self::parseCategorySeals($opportunity);
        $seal_id = $category_seals[$category_slug] ?? null;
        if (!$seal_id) {
            return $default_editable;
        }

        $seal = $app->repo('Seal')->find($seal_id);
        if (!$seal) {
            return $default_editable;
        }

        $agent_owner = $registration->owner;
        if (!$agent_owner) {
            return $default_editable;
        }

        $relation = $app->repo('AgentSealRelation')->findOneBy([
            'owner' => $agent_owner,
            'seal'  => $seal,
        ]);
        if (!$relation) {
            return $default_editable;
        }

        $matching_srf = null;
        foreach ($relation->getSealRelationFields() as $srf) {
            if ($srf->fieldName === $expected_srf_field_name) {
                $matching_srf = $srf;
                break;
            }
        }

        if (!$matching_srf) {
            return $default_editable;
        }

        $status = $matching_srf->getFieldStatus();
        $is_editable = self::computeFieldEditable($matching_srf);

        $result = [
            'editable'   => $is_editable,
            'reason'     => $status,
            'fieldTitle' => $field_config->title ?: $entity_field,
            'fieldName'  => $expected_srf_field_name,
        ];

        return $result;
    }

    /**
     * Faz parse tolerante do metadata cadastroUnicoCategorySeals.
     * @return array<string,int>
     */
    public static function parseCategorySeals(Opportunity $opportunity): array
    {
        $raw = $opportunity->cadastroUnicoCategorySeals;
        $parsed = is_object($raw)
            ? (array) $raw
            : (array) json_decode($raw ?? '{}', true);

        $coerced = [];
        foreach ($parsed as $slug => $seal_id) {
            if (is_numeric($seal_id)) {
                $coerced[$slug] = (int) $seal_id;
            }
        }
        return $coerced;
    }
}
