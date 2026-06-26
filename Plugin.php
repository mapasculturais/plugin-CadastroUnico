<?php
/**
 * Cadastro Único 2.0 — Plugin principal.
 *
 * Centraliza o registro de metadados do plugin e (nas fatias B-G) os hooks
 * de domínio. O seed determinístico é feito por Setup::install() via
 * db-updates.php (ver DT-03 da especificação).
 *
 * @package CadastroUnico2
 */

namespace CadastroUnico2;

use CadastroUnico2\Controllers\CadastroUnico;
use CadastroUnico2\Services\CadastroUnicoService;
use CadastroUnico2\Services\OpportunityVisibilityFilter;
use CadastroUnico2\Services\SealRegistrationSync;
use MapasCulturais\App;
use MapasCulturais\i;

class Plugin extends \MapasCulturais\Plugin
{
    /**
     * Configurações padrão do plugin. Podem ser sobrescritas no plugins.php
     * da instância, seguindo o padrão plugin-Kobo.
     */
    public function __construct(array $config = [])
    {
        $config += [
            'ownerAgentId' => null,
        ];

        parent::__construct($config);
    }

    /**
     * Inicialização do plugin — registro de hooks.
     *
     * Hooks implementados:
     *   - Fatia B (T13): GET(opportunity.single):before → redirect 302 (DT-08)
     *   - Fatia C (T17): template(panel.index.panel-home-main):begin → widget dashboard
     *   - Fatia D (T20/T21):
     *       * mapas.printJsObject:before → popula jsObject da tarja (cache APCu)
     *       * template(<<*>>.body):begin → emite componente cadastro-unico--banner
     *       * entity(Registration).insert/status(*) :after → invalida cache
     *       * entity(AgentSealRelation).insert/remove/update :after → invalida cache
     *   - Fatia E (T23): entity(RegistrationMeta).save:before → anti-bypass
     *       server-side de editabilidade de campos @ (DT-13)
     *   - Fatia F (DT-09): sincronização bidirecional selo ↔ inscrição
     *       * entity(Registration).setAgentsSealRelation:before (DT-02)
     *       * entity(Registration).unsetAgentsSealRelation:before (DT-02 simétrico)
     *       * entity(AgentSealRelation).insert:after (F3)
     *       * entity(AgentSealRelation).remove:after (F4)
     *   - Fatia A (DT-06/DT-07): unicidade e imutabilidade de owner de inscrição
     *
     * Hooks a implementar nas fatias G conforme a especificação:
     *   - DT-04 (filtro ApiQuery)
     */
    public function _init()
    {
        $app = App::i();

        // ============================================================
        // Fatia B — T13: Redirect `/oportunidade/{id}` → `/cadastro-unico`
        // ============================================================
        // DT-08: acesso por usuário comum à single da oportunidade do
        // Cadastro Único 2.0 é redirecionado para a UX customizada
        // `/cadastro-unico`. Admins/gestores/avaliadores com `@control`
        // caem na single NATIVA (para gestão — designar avaliadores,
        // publicar resultados, etc.).
        //
        // Por que `GET(opportunity.single):before` e não `ALL(...)` ou
        // `controller(opportunity).single:before`:
        //   - `GET(...)` é específico para método HTTP — NÃO intercepta
        //     POST/PUT/DELETE (regressão QA Suite 6: mutações devem passar).
        //   - API usa hooks `API.*` distintos + RoutesManager separa via
        //     prefixo `/api/`, então `/api/opportunity/{id}` nunca cai aqui.
        //   - `$this` dentro do callback é o Controller Opportunity;
        //     `$this->requestedEntity` é a Opportunity (null se ID inválido).
        $app->hook('GET(opportunity.single):before', function () use ($app) {
            /** @var \MapasCulturais\Controllers\Opportunity $this */

            $entity = $this->requestedEntity;

            // Guarda 1: entidade inexistente (ID inválido/inexistente).
            // Chamar $app->pass() aqui (em vez de apenas retornar) é
            // necessário porque o módulo OpportunityPhases registra um hook
            // de mesma fase que redireciona para a primeira fase quando
            // $entity->isFirstPhase é falso. Para IDs inexistentes, entity é
            // null e esse redirect do core produziria 302 em vez de 404.
            // Com prioridade alta (0), nosso hook é executado antes e garante
            // o comportamento 404 especificado em DT-08.
            if (!$entity) {
                $app->pass();
            }

            // Guarda 2: não é uma oportunidade do Cadastro Único 2.0.
            // Não interferir com outras oportunidades (regressão QA AC-5.3).
            if (!$entity->isCadastroUnico2) {
                return;
            }

            // Guarda 3: usuário com permissão de gestão (`@control`) vê a
            // single NATIVA. Isto cobre admin, saasAdmin, saasSuperAdmin,
            // superAdmin, avaliadores designados e qualquer gestor da
            // oportunidade — todos têm @control pela modelagem do core.
            // Usar canUser('@control') é mais robusto que enumerar roles
            // e alinhado ao modelo de permissões do MapasCulturais.
            if ($entity->canUser('@control', $app->user)) {
                return;
            }

            // Usuário comum (incluindo guest): redirecionar para a UX
            // customizada. Status 302 (default de $app->redirect) — não
            // cacheável permanentemente, permite mudança de comportamento
            // sem invalidar bookmarks.
            $destination = $app->createUrl('cadastroUnico', 'single');

            // Preservar query string do request original (ex: ?tab=foo,
            // ?anchor=bar). âncora (#...) é client-side e não sobrevive a
            // redirect HTTP — perda aceitável, documentada em DT-08.
            $query_string = $_SERVER['QUERY_STRING'] ?? '';
            if ($query_string !== '') {
                $destination .= '?' . $query_string;
            }

            $app->redirect($destination);
        }, 0);

        // ============================================================
        // Hooks das fatias subsequentes (TODO documental)
        // ============================================================

        // Fatia C — T17/T18: Widget do dashboard (painel do usuário).
        // ------------------------------------------------------------
        // Injeta o componente <panel--cadastro-unico> DENTRO do
        // <mc-tab slug="main"> do painel (patch T18 em
        // src/modules/Panel/views/panel/index.php adicionou os hooks
        // 'panel-home-main:before/begin/end/after' exatamente para isto).
        //
        // O hook callback:
        //   1. Importa o componente Vue (registra no escopo Vue da página).
        //   2. Emite <template is='vue:panel--cadastro-unico'> — forma
        //      idiomática do MapasCulturais de montar componentes Vue
        //      injetados via hook PHP (ver .architecture/plugins/examples.md
        //      Exemplo 4).
        //
        // Apenas usuários autenticados veem o widget (init.php retorna
        // payload vazio para guests, e o componente Vue faz v-if).
        $app->hook('template(panel.index.panel-home-main):begin', function () {
            /** @var \MapasCulturais\Themes\BaseV2\Theme $this */
            $this->import('panel--cadastro-unico');
            echo "<template is='vue:panel--cadastro-unico'></template>";
        });

        // Fatia F — Sincronização bidirecional selo ↔ inscrição (DT-09).
        // ------------------------------------------------------------
        // DT-02: casamento dinâmico categoria ↔ selo. Quando o core chama
        // setAgentsSealRelation/unsetAgentsSealRelation (ex.: inscrição
        // aprovada), substituímos registrationSeals->owner pelo selo correto
        // da categoria da inscrição.
        $app->hook('entity(Registration).setAgentsSealRelation:before', function (&$opportunityMetadataSeals) use ($app) {
            /** @var \MapasCulturais\Entities\Registration $this */
            SealRegistrationSync::hookSetAgentsSealRelation($this, $app, $opportunityMetadataSeals);
        });
        $app->hook('entity(Registration).unsetAgentsSealRelation:before', function (&$opportunityMetadataSeals) use ($app) {
            /** @var \MapasCulturais\Entities\Registration $this */
            SealRegistrationSync::hookUnsetAgentsSealRelation($this, $app, $opportunityMetadataSeals);
        });

        // F3: selo aplicado manualmente → cria/promove inscrição aprovada.
        // Usa insert:finish (e não insert:after), pois AgentSealRelation não
        // tem @ORM\HasLifecycleCallbacks, então os callbacks Doctrine
        // postPersist não são disparados. O hook :finish é aplicado
        // explicitamente por Entity::save() após o flush.
        $app->hook('entity(AgentSealRelation).insert:finish', function ($flush = null) use ($app) {
            /** @var \MapasCulturais\Entities\AgentSealRelation $this */
            SealRegistrationSync::onSealApplied($this, $app);
        });

        // F4: selo removido manualmente → inscrição torna-se não selecionada.
        // AgentSealRelation também não dispara remove:after (sem lifecycle
        // callbacks). Registramos um listener Doctrine preRemove para capturar
        // a remoção antes do flush, quando seal/owner ainda estão carregados.
        $app->em->getEventManager()->addEventListener(\Doctrine\ORM\Events::preRemove, new class($app) {
            private $app;

            public function __construct($app)
            {
                $this->app = $app;
            }

            public function preRemove($args): void
            {
                $entity = $args->getObject();
                if (!$entity instanceof \MapasCulturais\Entities\AgentSealRelation) {
                    return;
                }

                SealRegistrationSync::onSealRemoved($entity, $this->app);
            }
        });

        // DT-06: unicidade de inscrição ativa por categoria.
        $app->hook('entity(Registration).insert:before', function () use ($app) {
            /** @var \MapasCulturais\Entities\Registration $this */
            SealRegistrationSync::validateUniqueCategory($this, $app);
        });

        // DT-07: imutabilidade do owner da inscrição.
        $app->hook('entity(Registration).update:before', function () use ($app) {
            /** @var \MapasCulturais\Entities\Registration $this */
            SealRegistrationSync::validateOwnerImmutability($this, $app);
        });

        // ============================================================
        // Fatia D — T20/T21: Tarja amarela persistente (DT-11, DT-14)
        // ============================================================
        // A tarja aparece abaixo do header em TODAS as páginas para
        // usuários comuns (Q6: admins/gestores NÃO veem). Populada via
        // jsObject (embed server-side) + cache APCu 60s para evitar
        // queries em toda página. Lógica 100% delegada a
        // CadastroUnicoService::buildStatusPayload() (single source of
        // truth compartilhada com o endpoint API e o dashboard widget).
        //
        // Dois hooks coordenados:
        //   (a) mapas.printJsObject:before → popula jsObject (antes da
        //       serialização para HTML). Aqui roda o cache + early
        //       returns para guest/admin.
        //   (b) template(<<*>>.body):begin  → emite o componente Vue no
        //       DOM, na posição correta (logo abaixo do header). O
        //       componente lê o jsObject já serializado no client-side.
        //
        // Por que dois hooks? O jsObject é serializado para <script>
        // DURANTE mapas.printJsObject:after, ANTES de template(body):begin.
        // Popular o jsObject dentro de template(body):begin seria tarde
        // demais (não seria serializado). Padrão confirmado em
        // src/modules/Components/Module.php (linhas 59-126).
        $app->hook('mapas.printJsObject:before', function () use ($app) {
            /** @var \MapasCulturais\Themes\BaseV2\Theme $this */

            // Early return 1: visitante não vê tarja (não há agente profile).
            if ($app->user->is('guest')) {
                return;
            }

            // Early return 2: plugin não instalado (sem Opportunity do
            // Cadastro Único). Evita queries desnecessárias em ambientes
            // onde o plugin está registrado mas o db-update não rodou.
            //
            // NOTA: usa o helper do Service (query em opportunity_meta),
            // NÃO findOneBy no repo Opportunity com chave de metadata —
            // isso geraria UnrecognizedField porque isCadastroUnico2 é
            // metadata, não coluna Doctrine.
            $opportunity = CadastroUnicoService::findCadastroUnicoOpportunity($app);
            if (!$opportunity) {
                return;
            }

            // Early return 3 (Q6): admins e gestores/avaliadores com
            // @control na oportunidade NÃO veem a tarja. is('admin')
            // cobre superAdmin/saasAdmin/saasSuperAdmin/admin.
            if ($app->user->is('admin')) {
                return;
            }
            if ($opportunity->canUser('@control', $app->user)) {
                return;
            }

            // Agente profile do usuário logado. Sem profile → sem tarja.
            $agent = $app->user->profile;
            if (!$agent || !$agent->id) {
                return;
            }

            $user_id = (int) $app->user->id;

            // Cache APCu (DT-11): chave por user_id, TTL 60s. A tarja roda
            // em TODA página — o cache evita recomputar (3 categorias ×
            // queries) a cada page load. Invalidado pelos hooks de
            // lifecycle abaixo (Registration/AgentSealRelation).
            $payload = CadastroUnicoService::cacheFetch($app, $user_id);
            if ($payload === null) {
                try {
                    $payload = CadastroUnicoService::buildStatusPayload($app, $agent);
                } catch (\Throwable $e) {
                    // Falha isolada: tarja simplesmente não aparece.
                    // Loga para diagnóstico; o usuário não é impactado.
                    $app->log->error(sprintf(
                        '[CadastroUnico2] tarja buildStatusPayload falhou: %s | %s:%d',
                        $e->getMessage(),
                        $e->getFile(),
                        $e->getLine()
                    ));
                    return;
                }
                if ($payload !== null) {
                    CadastroUnicoService::cacheStore($app, $user_id, $payload);
                }
            }

            if ($payload === null) {
                return;
            }

            // Extrai APENAS a parte da tarja do payload completo. O
            // banner global não precisa das categorias/inscrições —
            // apenas da condição priorizada (Q8) + detalhes (T21) para
            // montar a mensagem. O dashboard widget consome o payload
            // completo via init.php próprio (outro canal).
            $tarja = $payload['tarja'] ?? null;

            // Dupla checagem suppressForRole (Q6): o Service já computa,
            // mas o early return 3 acima já filtrou. Mantemos a checagem
            // para robustez (cache pode conter payload de sessão anterior
            // com papel diferente — improvável mas defensivo).
            if (!$tarja || $tarja['visivel'] !== true || !empty($tarja['suppressForRole'])) {
                return;
            }

            $this->jsObject['cadastroUnicoBanner'] = [
                'visivel'  => true,
                'condicao' => $tarja['condicao'],
                'detalhes' => $tarja['detalhes'] ?? [],
            ];
        });

        // Hook (b): emite o componente Vue no DOM (logo abaixo do header).
        // O componente cadastro-unico--banner (criado pelo Frontend — T19)
        // lê $MAPAS.cadastroUnicoBanner (populado no hook acima) e renderiza
        // a tarja com dismiss em memória (DT-14, zero storage).
        $app->hook('template(<<*>>.body):begin', function () {
            /** @var \MapasCulturais\Themes\BaseV2\Theme $this */
            $this->import('cadastro-unico--banner');
            echo "<template is='vue:cadastro-unico--banner'></template>";
        });

        // ============================================================
        // Fatia D — T20: Invalidação do cache APCu da tarja (DT-11)
        // ============================================================
        // Quando uma entidade relevante muda, o cache do user afetado é
        // invalidado para que a tarja reflita o novo estado no próximo
        // page load (em vez de esperar o TTL de 60s).
        //
        // Resolução do user_id a partir da entidade:
        //   Registration → owner (Agent) → user (User) → id
        //   AgentSealRelation → owner (Agent) → user (User) → id
        //
        // Cada hook é idempotente (apcu_delete em chave inexistente é
        // no-op) e defensivo (try/catch — falha na invalidação não pode
        // quebrar a mutação da entidade).

        $invalidate_registration = function ($flush = null) use ($app) {
            /** @var \MapasCulturais\Entities\Registration $this */
            try {
                $owner = $this->owner ?? null;
                if (!$owner) {
                    return;
                }
                $user_id = CadastroUnicoService::resolveUserIdForAgent($app, $owner);
                if ($user_id) {
                    CadastroUnicoService::cacheDelete($app, $user_id);
                }
            } catch (\Throwable $e) {
                $app->log->debug(sprintf(
                    '[CadastroUnico2] cache invalidation (Registration) falhou: %s',
                    $e->getMessage()
                ));
            }
        };
        $app->hook('entity(Registration).insert:finish', $invalidate_registration);
        // status(<<*>>) captura TODAS as transições de status (draft, sent,
        // approved, notapproved, waitlist, invalid). Sintaxe de wildcard
        // confirmada em src/modules/Opportunities/Module.php:376. O hook é
        // disparado pelo core em cada setStatusTo{Status}() (Registration.php).
        $app->hook('entity(Registration).status(<<*>>)', $invalidate_registration);

        $invalidate_seal_relation = function ($flush = null) use ($app) {
            /** @var \MapasCulturais\Entities\AgentSealRelation $this */
            try {
                $owner = $this->owner ?? null;
                if (!$owner) {
                    return;
                }
                $user_id = CadastroUnicoService::resolveUserIdForAgent($app, $owner);
                if ($user_id) {
                    CadastroUnicoService::cacheDelete($app, $user_id);
                }
            } catch (\Throwable $e) {
                $app->log->debug(sprintf(
                    '[CadastroUnico2] cache invalidation (AgentSealRelation) falhou: %s',
                    $e->getMessage()
                ));
            }
        };
        $app->hook('entity(AgentSealRelation).save:finish', $invalidate_seal_relation);

        $app->em->getEventManager()->addEventListener(\Doctrine\ORM\Events::preRemove, new class($app, $invalidate_seal_relation) {
            private $app;
            private $callback;

            public function __construct($app, callable $callback)
            {
                $this->app = $app;
                $this->callback = $callback;
            }

            public function preRemove($args): void
            {
                $entity = $args->getObject();
                if (!$entity instanceof \MapasCulturais\Entities\AgentSealRelation) {
                    return;
                }

                $bound = \Closure::bind($this->callback, $entity, $entity);
                $bound();
            }
        });

        // ============================================================
        // Fatia E — T23: Anti-bypass server-side de editabilidade (DT-13)
        // ============================================================
        // Garante que campos @ em estado 'valid'/'no_expiration' não podem
        // ser sobrescritos via API. O single template (T22, frontend)
        // desabilita visualmente o input, mas sem este hook qualquer
        // usuário poderia burlar via POST /api/registration/{id}.
        //
        // Delega a decisão para CadastroUnicoService::isFieldEditable()
        // (single source of truth) — o frontend (T22) consulta o mesmo
        // método para setar :disabled no entity-field, garantindo paridade.
        //
        // Bypass para admin (DT-13 + Q3): NÃO há. Bloqueio absoluto.
        // Para correções administrativas excepcionais documentadas, o
        // admin pode usar $app->disableAccessControl() programaticamente
        // em script ad-hoc — NÃO exposto na API pública.
        $app->hook('entity(RegistrationMeta).save:before', function () use ($app) {
            /** @var \MapasCulturais\Entities\RegistrationMeta $this */

            $decision = CadastroUnicoService::isFieldEditable($app, $this);

            if ($decision['editable']) {
                return;
            }

            // Se o valor não mudou (re-save idempotente durante operações
            // como mudança de status ou sincronização F3/F4), não bloquear.
            // Evita falsos positivos quando o Doctrine re-persiste metadados
            // cujo conteúdo é idêntico ao já armazenado.
            $uow = $app->em->getUnitOfWork();
            $original_data = $uow->getOriginalEntityData($this);
            if (isset($original_data['value']) && $original_data['value'] == $this->value) {
                return;
            }

            // Campo está validado (valid / no_expiration) e não pode ser
            // editado. Lançar PermissionDenied com mensagem i18n
            // contextualizada — mesma exceção usada pelo core em casos
            // análogos (Module.php:600 do RegistrationFieldTypes).
            $field_title = $decision['fieldTitle'] ?? '';
            $message = sprintf(
                i::__('O campo "%s" está validado e não pode ser editado. Aguarde a expiração para atualizá-lo.'),
                $field_title
            );

            throw new \MapasCulturais\Exceptions\PermissionDenied(
                $app->user,
                $this,
                'modify',
                $message
            );
        });

        // ============================================================
        // Hooks das fatias subsequentes (TODO documental)
        // ============================================================

        // ============================================================
        // Fatia G — Item no menu do usuário (DT-16)
        // ============================================================
        // Adiciona o link "Cadastro único" ao menu dropdown do usuário
        // logado. Apenas usuários autenticados veem o item; guests não
        // têm acesso ao menu. O item é injetado no desktop (dentro da
        // <ul> do slot #begin do panel--nav) e no mobile (slot #end),
        // garantindo visibilidade em ambos os viewports.
        $app->hook('template(header-menu-user--itens):end', function () use ($app) {
            /** @var \MapasCulturais\Themes\BaseV2\Theme $this */
            if ($app->user->is('guest')) {
                return;
            }

            $this->import('mc-link');
            echo '<li><mc-link route="cadastroUnico/single" icon="file">' . i::__('Cadastro único') . '</mc-link></li>';
        });

        $app->hook('template(header-menu-user--mobile):end', function () use ($app) {
            /** @var \MapasCulturais\Themes\BaseV2\Theme $this */
            if ($app->user->is('guest')) {
                return;
            }

            $this->import('mc-link');
            echo '<mc-link route="cadastroUnico/single" icon="file">' . i::__('Cadastro único') . '</mc-link>';
        });

        // ============================================================
        // Fatia G — Não-listagem pública (DT-04)
        // ============================================================
        // Oculta a oportunidade do Cadastro Único das listagens PÚBLICAS
        // de oportunidades (ex.: home, /oportunidades, embeds, API pública
        // sem autenticação). NÃO afeta admins, gestores/avaliadores com
        // @control, nem páginas privadas (/painel, /oportunidade/{id},
        // /cadastro-unico, /inscricao, /minhas-inscricoes).
        //
        // Decisão por papel + REQUEST_URI — NÃO usa Referer (spoofable).
        // O filtro é UX: o gate real de segurança continua sendo o modelo
        // de permissões do core.
        $app->hook('ApiQuery(Opportunity).where', function (&$where) use ($app) {
            /** @var string $where */
            OpportunityVisibilityFilter::applyFilter($app, $where);
        });
    }

    /**
     * Registro de metadados do plugin.
     *
     * Os metadados abaixo são o "contrato de identificação" usado por todos
     * os hooks subsequentes para reconhecer entidades pertencentes ao
     * Cadastro Único 2.0.
     */
    public function register()
    {
        $app = App::i();

        // ============ Controller da rota /cadastro-unico (Fatia B — T12) ============

        // Registra o controller com id 'cadastroUnico'. O App lowercases o id
        // para 'cadastrounico', que passa a ser o templatePrefix e o view_dir.
        // A shortcut em config/routes.php mapeia URL '/cadastro-unico' →
        // (controller=cadastrounico, action=single). A view é resolvida pelo
        // Theme (que inclui o path deste plugin via Module::addPath()) em
        // views/cadastrounico/single.php.
        $app->registerController('cadastroUnico', CadastroUnico::class);

        // ============ Metadados em Opportunity ============

        // Identifica a oportunidade como sendo do Cadastro Único 2.0.
        // Usado por: filtro de não-listagem (DT-04), redirect (DT-08),
        // unicidade (DT-06), imutabilidade (DT-07), sincronização (DT-09).
        // Populado como true exclusivamente pelo Setup::install() no db-update.
        $this->registerOpportunityMetadata('isCadastroUnico2', [
            'label' => i::__('É Cadastro Único 2.0'),
            'type' => 'boolean',
            'default' => false,
            'validations' => [
                'v::optional(v::boolType())' => i::__('O valor de isCadastroUnico2 deve ser booleano'),
            ],
        ]);

        // Mapa {slug_categoria: sealId} populado no Setup::install() APÓS
        // a criação dos 3 selos. É o contrato crítico (DT-02) que permite ao
        // hook entity(Registration).setAgentsSealRelation:before substituir
        // dinamicamente registrationSeals->owner pelo selo correto da
        // categoria da inscrição sendo aprovada.
        //
        // Exemplo: {"certidoes": 7, "documentos": 8, "autodeclaracoes": 9}
        $this->registerOpportunityMetadata('cadastroUnicoCategorySeals', [
            'label' => i::__('Mapa categoria → selo (Cadastro Único 2.0)'),
            'type' => 'json',
            'default' => '{}',
        ]);

        // ============ Metadados em Seal ============

        // Identifica a qual categoria do Cadastro Único 2.0 um selo pertence.
        // Slug da categoria (chave do mapa cadastroUnicoCategorySeals).
        // Usado por: sincronização bidirecional (DT-09 F3/F4) para saber qual
        // categoria criar/promover a inscrição quando um selo é aplicado/removido.
        $this->registerSealMetadata('isCadastroUnico2Category', [
            'label' => i::__('Categoria do Cadastro Único 2.0'),
            'type' => 'select',
            'options' => [
                '' => i::__('Não pertence ao Cadastro Único 2.0'),
                Setup::CATEGORY_CERTIDOES => i::__('Certidões'),
                Setup::CATEGORY_DOCUMENTOS => i::__('Documentos obrigatórios'),
                Setup::CATEGORY_AUTODECLARACOES => i::__('Autodeclarações'),
            ],
        ]);
    }
}
