<?php
namespace CadastroUnico\Entities;

use Closure;
use CadastroUnico\Setup;
use MapasCulturais\App;
use MapasCulturais\Entities\Agent;
use MapasCulturais\Entities\AgentSealRelation;
use MapasCulturais\Entities\Opportunity;
use MapasCulturais\Entities\Registration;
use MapasCulturais\Entities\RegistrationMeta;
use MapasCulturais\Entities\Seal;
use MapasCulturais\Exceptions\BadRequest;
use MapasCulturais\i;

class SealRegistrationSync
{
    /**
     * Flag de anti-reentrância. Evita loops quando a criação/promoção de inscrição dispara outros hooks de lifecycle.
     * @var bool
     */
    private static $syncing = false;

    /**
     * Antes de `Registration::setAgentsSealRelation()`. Delega para syncRegistrationToSeal(). Mantido como ponto de entrada específico do hook para clareza no Plugin.php.
     * @param Registration $registration
     * @param App          $app
     * @param object|null  $opportunityMetadataSeals Passado por referência pelo core.
     */
    public static function hookSetAgentsSealRelation(
        Registration $registration,
        App $app,
        ?object &$opportunityMetadataSeals = null
    ): void {
        self::syncRegistrationToSeal($registration, $app, $opportunityMetadataSeals);
    }

    /**
     * Substitui `registrationSeals->owner` pelo selo correspondente à categoria da inscrição (mapa `cadastroUnicoCategorySeals`).
     * @param Registration $registration
     * @param App          $app
     * @param object|null  $opportunityMetadataSeals Passado por referência pelo core.
     */
    public static function syncRegistrationToSeal(
        Registration $registration,
        App $app,
        ?object &$opportunityMetadataSeals = null
    ): void {
        $opportunity = $registration->opportunity;
        if (!$opportunity || !$opportunity->isCadastroUnico) {
            return;
        }

        $seal_id = self::resolveSealIdForRegistration($registration, $app);
        if (!$seal_id) {
            return;
        }

        if (!is_object($opportunityMetadataSeals)) {
            $opportunityMetadataSeals = new \stdClass();
        }
        $opportunityMetadataSeals->owner = $seal_id;
    }

    /**
     * Antes de `Registration::unsetAgentSealRelation()`. Delega para syncRegistrationToSeal() (simétrico a F1).
     * @param Registration $registration
     * @param App          $app
     * @param object|null  $opportunityMetadataSeals Passado por referência pelo core.
     */
    public static function hookUnsetAgentsSealRelation(
        Registration $registration,
        App $app,
        ?object &$opportunityMetadataSeals = null
    ): void {
        self::syncRegistrationToSeal($registration, $app, $opportunityMetadataSeals);
    }

    /**
     * Após inserção de AgentSealRelation. Delega para syncSealToRegistration(). Mantido como ponto de entrada específico do hook para clareza no Plugin.php.
     * @param AgentSealRelation $relation
     * @param App               $app
     */
    public static function onSealApplied(AgentSealRelation $relation, App $app): void
    {
        self::syncSealToRegistration($relation, $app);
    }

    /**
     * Selo aplicado manualmente → criar/promover inscrição. Se o selo pertence a uma categoria do Cadastro Único, cria uma nova inscrição aprovada ou promove um rascunho existente, preenchendo os campos `@` com os dados do agente.
     * @param AgentSealRelation $relation
     * @param App               $app
     */
    public static function syncSealToRegistration(AgentSealRelation $relation, App $app): void
    {
        if (self::$syncing) {
            return;
        }

        $category = self::findCategoryBySeal($app, $relation->seal);
        if (!$category) {
            return;
        }

        $agent = $relation->owner;
        if (!$agent || !$agent->id) {
            return;
        }

        $opportunity = CadastroUnicoService::findCadastroUnicoOpportunity($app);
        if (!$opportunity) {
            return;
        }

        self::$syncing = true;
        $app->disableAccessControl();

        try {
            $registration = self::findRegistrationByCategory(
                $app,
                $opportunity,
                $agent,
                $category['label']
            );

            if (!$registration) {
                $registration = self::createApprovedRegistration(
                    $app,
                    $opportunity,
                    $agent,
                    $category['label']
                );
            } elseif ($registration->status != Registration::STATUS_APPROVED) {
                $registration->setStatusToApproved(true);
            }

            self::populateAgentFieldsFromOwner($registration, $agent, $app);
        } catch (\Throwable $e) {
            $app->log->error(sprintf(
                '[CadastroUnico] F3 (syncSealToRegistration) falhou: %s | %s:%d',
                $e->getMessage(),
                $e->getFile(),
                $e->getLine()
            ));
        } finally {
            $app->enableAccessControl();
            self::$syncing = false;
        }
    }

    /**
     * Após remoção de AgentSealRelation. Delega para syncSealRemovalToRegistration(). Mantido como ponto de entrada específico do hook para clareza no Plugin.php.
     * @param AgentSealRelation $relation
     * @param App               $app
     */
    public static function onSealRemoved(AgentSealRelation $relation, App $app): void
    {
        self::syncSealRemovalToRegistration($relation, $app);
    }

    /**
     * Selo removido manualmente → inscrição não selecionada. Se o selo pertence a uma categoria do Cadastro Único, encontra a inscrição ativa correspondente e marca como não selecionada.
     * @param AgentSealRelation $relation
     * @param App               $app
     */
    public static function syncSealRemovalToRegistration(AgentSealRelation $relation, App $app): void
    {
        if (self::$syncing) {
            return;
        }

        $category = self::findCategoryBySeal($app, $relation->seal);
        if (!$category) {
            return;
        }

        $agent = $relation->owner;
        if (!$agent || !$agent->id) {
            return;
        }

        $opportunity = CadastroUnicoService::findCadastroUnicoOpportunity($app);
        if (!$opportunity) {
            return;
        }

        self::$syncing = true;
        $app->disableAccessControl();

        try {
            $registration = self::findRegistrationByCategory(
                $app,
                $opportunity,
                $agent,
                $category['label']
            );

            if ($registration && $registration->status >= 0) {
                $registration->setStatusToNotApproved(true);
            }
        } catch (\Throwable $e) {
            $app->log->error(sprintf(
                '[CadastroUnico] F4 (syncSealRemovalToRegistration) falhou: %s | %s:%d',
                $e->getMessage(),
                $e->getFile(),
                $e->getLine()
            ));
        } finally {
            $app->enableAccessControl();
            self::$syncing = false;
        }
    }

    /**
     * Valida unicidade de inscrição ativa por categoria.   
     *
     * Lança BadRequest (HTTP 400) se já existir uma inscrição ativa
     * (status >= 0) do mesmo owner na mesma categoria da oportunidade do
     * Cadastro Único.
     *
     * @param Registration $registration
     * @param App          $app
     * @throws BadRequest
     */
    public static function validateUniqueCategory(Registration $registration, App $app): void
    {
        if (self::$syncing) {
            return;
        }

        $opportunity = $registration->opportunity;
        if (!$opportunity || !$opportunity->isCadastroUnico) {
            return;
        }

        $owner = $registration->owner;
        $category = $registration->category;
        if (!$owner || !$owner->id || !$category) {
            return;
        }

        $existing = self::findRegistrationByCategory(
            $app,
            $opportunity,
            $owner,
            $category
        );

        if ($existing && $existing->id !== $registration->id) {
            throw new BadRequest(
                i::__('Já existe uma inscrição ativa para esta categoria no Cadastro Único.')
            );
        }
    }

    /**
     * Garante imutabilidade do owner da inscrição.
     *
     * Para inscrições do Cadastro Único, o owner não pode ser alterado.
     * A detecção de mudança usa o Doctrine UnitOfWork (dados originais da
     * entidade), pois a flag `_ownerChanged` nem sempre é populada pelo core.
     *
     * @param Registration $registration
     * @param App          $app
     * @throws BadRequest
     */
    public static function validateOwnerImmutability(
        Registration $registration,
        App $app
    ): void {
        $opportunity = $registration->opportunity;
        if (!$opportunity || !$opportunity->isCadastroUnico) {
            return;
        }

        if ($registration->isNew()) {
            return;
        }

        $uow = $app->em->getUnitOfWork();
        $original_data = $uow->getOriginalEntityData($registration);

        $original_owner_id = $original_data['agent_id'] ?? null;
        if ($original_owner_id === null && $registration->id) {
            $original_owner_id = $app->em->getConnection()->fetchOne(
                "SELECT agent_id FROM registration WHERE id = ?",
                [$registration->id]
            );
        }

        $current_owner_id = $registration->owner ? $registration->owner->id : null;

        if ($original_owner_id && $current_owner_id && (int) $original_owner_id !== (int) $current_owner_id) {
            throw new BadRequest(
                i::__('Não é permitido alterar o responsável de uma inscrição do Cadastro Único.')
            );
        }
    }

    /**
     * Resolve o selo correspondente à categoria de uma inscrição.
     *
     * @param Registration $registration
     * @param App          $app
     * @return int|null
     */
    public static function resolveSealIdForRegistration(Registration $registration, App $app): ?int
    {
        $opportunity = $registration->opportunity;
        if (!$opportunity || !$opportunity->isCadastroUnico) {
            return null;
        }

        $category_label = $registration->category;
        $category_slug = array_search($category_label, Setup::CATEGORIES, true);
        if ($category_slug === false) {
            return null;
        }

        $category_seals = CadastroUnicoService::parseCategorySeals($opportunity);
        $seal_id = $category_seals[$category_slug] ?? null;

        return $seal_id ? (int) $seal_id : null;
    }

    /**
     * Encontra a categoria do Cadastro Único à qual um selo pertence.
     *
     * Valida também se o selo consta no mapa `cadastroUnicoCategorySeals`
     * da oportunidade do Cadastro Único.
     *
     * @param App  $app
     * @param Seal $seal
     * @return array{slug:string,label:string}|null
     */
    public static function findCategoryBySeal(App $app, Seal $seal): ?array
    {
        $slug = $seal->isCadastroUnicoCategory ?? null;
        if (!$slug || !isset(Setup::CATEGORIES[$slug])) {
            return null;
        }

        $opportunity = CadastroUnicoService::findCadastroUnicoOpportunity($app);
        if (!$opportunity) {
            return null;
        }

        $category_seals = CadastroUnicoService::parseCategorySeals($opportunity);
        if (!isset($category_seals[$slug]) || (int) $category_seals[$slug] !== (int) $seal->id) {
            return null;
        }

        return [
            'slug'  => $slug,
            'label' => Setup::CATEGORIES[$slug],
        ];
    }

    /**
     * Busca a inscrição ativa (status >= 0) de um agente em uma categoria.
     *
     * @param App        $app
     * @param Opportunity $opportunity
     * @param Agent      $agent
     * @param string     $category Label da categoria.
     * @return Registration|null
     */
    public static function findRegistrationByCategory(
        App $app,
        Opportunity $opportunity,
        Agent $agent,
        string $category
    ): ?Registration {
        $candidates = $app->repo('Registration')->findBy([
            'opportunity' => $opportunity,
            'owner'       => $agent,
            'category'    => $category,
        ]);

        foreach ($candidates as $registration) {
            if ($registration->status >= 0) {
                return $registration;
            }
        }

        return null;
    }

    /**
     * Aplica o selo de uma categoria ao owner de uma inscrição.
     *
     * Útil quando se deseja aplicar o selo a partir de uma inscrição já
     * existente, sem depender do fluxo automático de aprovação do core.
     *
     * @param Registration $registration
     * @param string       $category Label da categoria.
     */
    public static function applySealForRegistration(Registration $registration, string $category): void
    {
        $app = App::i();
        $opportunity = $registration->opportunity;

        if (!$opportunity || !$opportunity->isCadastroUnico) {
            return;
        }

        $category_slug = array_search($category, Setup::CATEGORIES, true);
        if ($category_slug === false) {
            return;
        }

        $category_seals = CadastroUnicoService::parseCategorySeals($opportunity);
        $seal_id = $category_seals[$category_slug] ?? null;
        if (!$seal_id) {
            return;
        }

        $seal = $app->repo('Seal')->find($seal_id);
        if (!$seal) {
            return;
        }

        $agent = $registration->owner;
        if (!$agent || !$agent->id) {
            return;
        }

        $existing = $app->repo('AgentSealRelation')->findOneBy([
            'owner' => $agent,
            'seal'  => $seal,
        ]);
        if ($existing) {
            return;
        }

        $app->disableAccessControl();
        $relation = $agent->createSealRelation($seal, true, true, $opportunity->owner);
        $app->enableAccessControl();
    }

    /**
     * Remove o selo de uma categoria do owner de uma inscrição.
     *
     * @param Registration $registration
     * @param string       $category Label da categoria.
     */
    public static function removeSealForRegistration(Registration $registration, string $category): void
    {
        $app = App::i();
        $opportunity = $registration->opportunity;

        if (!$opportunity || !$opportunity->isCadastroUnico) {
            return;
        }

        $category_slug = array_search($category, Setup::CATEGORIES, true);
        if ($category_slug === false) {
            return;
        }

        $category_seals = CadastroUnicoService::parseCategorySeals($opportunity);
        $seal_id = $category_seals[$category_slug] ?? null;
        if (!$seal_id) {
            return;
        }

        $seal = $app->repo('Seal')->find($seal_id);
        if (!$seal) {
            return;
        }

        $agent = $registration->owner;
        if (!$agent || !$agent->id) {
            return;
        }

        $app->disableAccessControl();
        $agent->removeSealRelation($seal);
        $app->enableAccessControl();
    }

    /**
     * Cria e persiste uma nova inscrição aprovada.
     *
     * @param App        $app
     * @param Opportunity $opportunity
     * @param Agent      $agent
     * @param string     $category Label da categoria.
     * @return Registration
     */
    private static function createApprovedRegistration(
        App $app,
        Opportunity $opportunity,
        Agent $agent,
        string $category
    ): Registration {
        $registration = new Registration();
        $registration->opportunity = $opportunity;
        $registration->owner = $agent;
        $registration->category = $category;
        $registration->proponentType = 'default';
        $registration->range = 'default';

        $set_status = Closure::bind(function (int $status) {
            $this->status = $status;
        }, $registration, Registration::class);
        $set_status(Registration::STATUS_APPROVED);

        $registration->save(true);

        return $registration;
    }

    /**
     * Preenche os campos `@` (agent-owner-field) da inscrição com os valores
     * atuais do agente owner, usando fetchFromEntity() do módulo
     * RegistrationFieldTypes.
     *
     * @param Registration $registration
     * @param Agent        $agent
     * @param App          $app
     */
    private static function populateAgentFieldsFromOwner(
        Registration $registration,
        Agent $agent,
        App $app
    ): void {
        $opportunity = $registration->opportunity;
        if (!$opportunity) {
            return;
        }

        $opportunity->registerRegistrationMetadata();

        $module = $app->modules['RegistrationFieldTypes'] ?? null;
        if (!$module) {
            return;
        }

        $original_status = $registration->status;
        $set_status = Closure::bind(function (int $status) {
            $this->status = $status;
        }, $registration, Registration::class);
        $set_status(Registration::STATUS_DRAFT);

        foreach ($opportunity->registrationFieldConfigurations as $field_config) {
            if ($field_config->fieldType !== 'agent-owner-field') {
                continue;
            }

            $field_name = $field_config->getFieldName();
            $metadata_definition = $app->getRegisteredMetadataByMetakey(
                $field_name,
                Registration::class
            );
            if (!$metadata_definition) {
                continue;
            }

            try {
                $value = $module->fetchFromEntity(
                    $agent,
                    null,
                    $registration,
                    $metadata_definition
                );
            } catch (\Throwable $e) {
                $app->log->debug(sprintf(
                    '[CadastroUnico] fetchFromEntity falhou para %s: %s',
                    $field_name,
                    $e->getMessage()
                ));
                continue;
            }

            $stored_value = self::serializeFieldValue($value);
            self::setRegistrationMetaValue($registration, $field_name, $stored_value);
        }

        $set_status($original_status);
    }

    /**
     * Serializa um valor de campo para armazenamento em registration_meta,
     * replicando o comportamento padrão dos tipos de campo do core.
     *
     * @param mixed $value
     * @return mixed
     */
    private static function serializeFieldValue($value)
    {
        if (is_object($value) || is_array($value)) {
            return json_encode($value);
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        return $value;
    }

    /**
     * Cria ou atualiza um RegistrationMeta diretamente, sem disparar o
     * serialize dos agent-owner-field (que salvaria de volta no agente).
     *
     * @param Registration $registration
     * @param string       $key
     * @param mixed        $value
     */
    private static function setRegistrationMetaValue(
        Registration $registration,
        string $key,
        $value
    ): void {
        $app = App::i();

        $existing = $app->repo('RegistrationMeta')->findOneBy([
            'owner' => $registration,
            'key'   => $key,
        ]);

        if ($existing) {
            $existing->value = $value;
            $existing->save(true);
        } else {
            $meta = new RegistrationMeta();
            $meta->owner = $registration;
            $meta->key = $key;
            $meta->value = $value;
            $meta->save(true);
        }
    }
}
