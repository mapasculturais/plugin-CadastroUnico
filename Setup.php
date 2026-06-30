<?php
namespace CadastroUnico;

use MapasCulturais\App;
use MapasCulturais\Entities\Agent;
use MapasCulturais\Entities\AgentOpportunity;
use MapasCulturais\Entities\Opportunity;
use MapasCulturais\Entities\Seal;
use MapasCulturais\i;

class Setup
{
    public const CATEGORY_CERTIDOES = 'certidoes';
    public const CATEGORY_DOCUMENTOS = 'documentos';
    public const CATEGORY_AUTODECLARACOES = 'autodeclaracoes';

    public const CATEGORIES = [
        self::CATEGORY_CERTIDOES => 'Certidões',
        self::CATEGORY_DOCUMENTOS => 'Documentos obrigatórios',
        self::CATEGORY_AUTODECLARACOES => 'Autodeclarações',
    ];

    /**
     * Seed determinístico do Cadastro Único.
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
             WHERE om.key = 'isCadastroUnico' AND om.value = '1'
             LIMIT 1"
        );

        if ($existing_id) {
            return $app->repo('Opportunity')->find($existing_id) ?: null;
        }

        // ============================================================
        // Resolver agente admin
        // ============================================================
        if (!$admin_agent) {
            $plugin = $app->plugins['CadastroUnico'] ?? null;
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
                '[CadastroUnico] Nenhum agente administrador encontrado para ser owner da oportunidade. ' .
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
                     WHERE sm.key = 'isCadastroUnicoCategory' AND sm.value = " . $conn->quote($slug) . "
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
                    i::__('Selo automático da categoria "%s" do Cadastro Único.'),
                    $label
                );
                $seal->validPeriod = 0;
                $seal->lockedFieldsConfig = [];
                $seal->isCadastroUnicoCategory = $slug;
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
            $opportunity->isCadastroUnico = true;
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
