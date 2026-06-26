<?php
/**
 * Cadastro Único 2.0 — Constantes canônicas e contrato crítico.
 *
 * Esta classe NÃO contém lógica de seed — TODO o código de instalação
 * vive inline em db-updates.php (padrão do MapasCulturais). A classe
 * existe apenas como repositório centralizado das constantes de
 * categoria consumidas por Plugin.php, Services/CadastroUnicoService,
 * views e components.
 *
 * CONTRATO CRÍTICO (DT-12) — não criado no seed, mas documentado aqui:
 *   Quando o admin criar os campos @ no form builder (após install) e
 *   configurar a expiração por campo na edição do selo, o sistema exige:
 *
 *     SealRelationField.fieldName === "agent." + RegistrationFieldConfiguration.config.entityField
 *
 *   Exemplo: campo @ com config.entityField = "documento"
 *            → Seal.lockedFieldsConfig deve ter a chave "agent.documento"
 *            → SealRelationField.fieldName será "agent.documento"
 *
 *   Esta correspondência é o que conecta o formulário de inscrição ao
 *   status do selo. É configurado pelo admin via UI (form builder +
 *   edição de selo), NÃO pelo seed.
 *
 * @package CadastroUnico2
 */

namespace CadastroUnico2;

use MapasCulturais\App;
use MapasCulturais\Entities\Agent;
use MapasCulturais\Entities\AgentOpportunity;
use MapasCulturais\Entities\Opportunity;
use MapasCulturais\Entities\Seal;
use MapasCulturais\i;

class Setup
{
    /**
     * Slugs canônicos das categorias (chaves de cadastroUnicoCategorySeals).
     * Também usados como valores do metadata Seal.isCadastroUnico2Category.
     */
    public const CATEGORY_CERTIDOES = 'certidoes';
    public const CATEGORY_DOCUMENTOS = 'documentos';
    public const CATEGORY_AUTODECLARACOES = 'autodeclaracoes';

    /**
     * Rótulos legíveis das categorias (também usados como nomes das
     * categorias de inscrição em Opportunity.registrationCategories e como
     * nomes dos 3 selos criados).
     *
     * NOTA: o slug interno (key) é distinto do label exibido (value) porque
     * o metadata isCadastroUnico2Category usa slug (estável a renomeações
     * de UI/i18n), enquanto registrationCategories usa o label diretamente
     * (o core do MapasCulturais casa por string exata).
     */
    public const CATEGORIES = [
        self::CATEGORY_CERTIDOES => 'Certidões',
        self::CATEGORY_DOCUMENTOS => 'Documentos obrigatórios',
        self::CATEGORY_AUTODECLARACOES => 'Autodeclarações',
    ];

    /**
     * Seed determinístico do Cadastro Único 2.0.
     *
     * Cria (de forma idempotente) a oportunidade única, os 3 selos e o mapa
     * categoria↔selo. É o single source of truth entre produção (db-update)
     * e testes (CadastroUnicoDirector).
     *
     * @param App         $app
     * @param Agent|null  $admin_agent Agente administrador owner da oportunidade
     *                                 e dos selos. Se null, resolve automaticamente.
     * @param bool        $begin_transaction Se true, inicia/commit/rollback uma
     *                                       transação Doctrine em torno do seed.
     *                                       Em testes já transacionais, passe false.
     * @return Opportunity|null A oportunidade criada/encontrada, ou null se já existir.
     * @throws \RuntimeException Se nenhum agente administrador for encontrado.
     * @throws \Throwable        Se o seed falhar (e $begin_transaction=true, rollback é feito).
     */
    public static function install(App $app, ?Agent $admin_agent = null, bool $begin_transaction = true): ?Opportunity
    {
        $em = $app->em;
        $conn = $em->getConnection();

        // ============================================================
        // Camada 2 de idempotência
        // ============================================================
        $existing_id = $conn->fetchOne(
            "SELECT o.id
             FROM opportunity o
             INNER JOIN opportunity_meta om ON om.object_id = o.id
             WHERE om.key = 'isCadastroUnico2' AND om.value = '1'
             LIMIT 1"
        );

        if ($existing_id) {
            return $app->repo('Opportunity')->find($existing_id) ?: null;
        }

        // ============================================================
        // Resolver agente admin
        // ============================================================
        if (!$admin_agent) {
            $plugin = $app->plugins['CadastroUnico2'] ?? null;
            $owner_agent_id = $plugin ? ($plugin->config['ownerAgentId'] ?? null) : null;

            if ($owner_agent_id) {
                $admin_agent = $app->repo('Agent')->find($owner_agent_id) ?: null;
            }

            if (!$admin_agent) {
                foreach (['saasSuperAdmin', 'saasAdmin', 'superAdmin', 'admin'] as $role_name) {
                    $role = $app->repo('Role')->findOneBy(
                        ['name' => $role_name],
                        ['id' => 'desc']
                    );
                    if ($role && $role->user && $role->user->profile) {
                        $admin_agent = $role->user->profile;
                        break;
                    }
                }
            }

            if (!$admin_agent && $app->user && $app->user->profile) {
                $admin_agent = $app->user->profile;
            }
        }

        if (!$admin_agent) {
            throw new \RuntimeException(
                '[CadastroUnico2] Nenhum agente administrador encontrado para ser owner da oportunidade. ' .
                'Verifique se existe ao menos um usuário com role saasSuperAdmin, saasAdmin, superAdmin ou admin.'
            );
        }

        // ============================================================
        // Transação + acesso
        // ============================================================
        $managed_transaction = false;
        if ($begin_transaction && !$conn->isTransactionActive()) {
            $em->beginTransaction();
            $managed_transaction = true;
        }

        $previous_user = $app->auth->authenticatedUser ?? null;
        $app->auth->authenticatedUser = $admin_agent->user;
        $app->disableAccessControl();

        try {
            // --------------------------------------------------------
            // Passo 1: criar os 3 selos
            // --------------------------------------------------------
            $seals_by_slug = [];

            foreach (self::CATEGORIES as $slug => $label) {
                $existing_seal_id = $conn->fetchOne(
                    "SELECT s.id
                     FROM seal s
                     INNER JOIN seal_meta sm ON sm.object_id = s.id
                     WHERE sm.key = 'isCadastroUnico2Category' AND sm.value = " . $conn->quote($slug) . "
                     LIMIT 1"
                );

                if ($existing_seal_id) {
                    $seals_by_slug[$slug] = $app->repo('Seal')->find($existing_seal_id);
                    continue;
                }

                $seal = new Seal();
                $seal->owner = $admin_agent;
                $seal->name = $label;
                $seal->shortDescription = sprintf(
                    i::__('Selo automático da categoria "%s" do Cadastro Único 2.0.'),
                    $label
                );
                $seal->validPeriod = 0;
                $seal->lockedFieldsConfig = [];
                $seal->isCadastroUnico2Category = $slug;
                $seal->save(true);

                $seals_by_slug[$slug] = $seal;
            }

            // --------------------------------------------------------
            // Passo 2: criar a oportunidade
            // --------------------------------------------------------
            /** @var AgentOpportunity $opportunity */
            $opportunity = new AgentOpportunity();
            $opportunity->ownerEntity = $admin_agent;
            $opportunity->owner = $admin_agent;
            $opportunity->type = 1;
            $opportunity->name = i::__('Cadastro único');
            $opportunity->shortDescription = i::__(
                'Cadastro único de documentos do agente — centraliza certidões, ' .
                'documentos obrigatórios e autodeclarações.'
            );

            $opportunity->registrationFrom = new \DateTime('2020-01-01 00:00:00');
            $opportunity->registrationTo = new \DateTime(Opportunity::CONTINUOUS_FLOW_DATE);

            $opportunity->publishedRegistrations = true;
            $opportunity->status = Opportunity::STATUS_ENABLED;
            $opportunity->isCadastroUnico2 = true;
            $opportunity->save(true);

            // Garante as 3 categorias de inscrição após a criação da
            // oportunidade (conforme solicitação de revisão do db-update).
            $opportunity->setRegistrationCategories(array_values(self::CATEGORIES));
            $opportunity->save(true);

            // --------------------------------------------------------
            // Passo 3: popular cadastroUnicoCategorySeals
            // --------------------------------------------------------
            $category_seals_map = [];
            foreach ($seals_by_slug as $slug => $seal) {
                $category_seals_map[$slug] = $seal->id;
            }

            $opportunity->cadastroUnicoCategorySeals = $category_seals_map;
            $opportunity->save(true);

            if ($managed_transaction) {
                $em->commit();
            }

            return $opportunity;
        } catch (\Throwable $e) {
            if ($managed_transaction) {
                $em->rollback();
            }

            throw $e;
        } finally {
            $app->enableAccessControl();
            if ($previous_user) {
                $app->auth->authenticatedUser = $previous_user;
            } else {
                $app->auth->authenticatedUser = null;
            }
        }
    }
}
