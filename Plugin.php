<?php
namespace CadastroUnico;

use CadastroUnico\Controllers\CadastroUnico;
use CadastroUnico\Entities\CadastroUnicoService;
use CadastroUnico\Entities\OpportunityVisibilityFilter;
use CadastroUnico\Entities\SealRegistrationSync;
use MapasCulturais\App;
use MapasCulturais\i;

class Plugin extends \MapasCulturais\Plugin
{
    public function __construct(array $config = [])
    {
        $config += [
            'ownerAgentId' => null,
        ];

        parent::__construct($config);
    }

    public function _init()
    {
        $app = App::i();

        $app->hook('GET(opportunity.single):before', function () use ($app) {
            /** @var \MapasCulturais\Controllers\Opportunity $this */

            $entity = $this->requestedEntity;

            if (!$entity) {
                $app->pass();
            }

            if (!$entity->isCadastroUnico) {
                return;
            }

            if ($entity->canUser('@control', $app->user)) {
                return;
            }

            $destination = $app->createUrl('cadastroUnico', 'single');

            $query_string = $_SERVER['QUERY_STRING'] ?? '';
            if ($query_string !== '') {
                $destination .= '?' . $query_string;
            }

            $app->redirect($destination);
        }, 0);

        $app->hook('template(panel.index.panel-home-main):begin', function () {
            /** @var \MapasCulturais\Themes\BaseV2\Theme $this */
            $this->import('panel--cadastro-unico');
            echo "<template is='vue:panel--cadastro-unico'></template>";
        });

        $app->hook('view.render(cadastrounico/single):before', function () use ($app) {
            /** @var \MapasCulturais\Themes\BaseV2\Theme $this */
            $css_file = $this->resolveFilename('assets', 'css/cadastro-unico-single.css');
            if ($css_file) {
                $this->enqueueStyle('app-v2', 'cadastro-unico-single', 'css/cadastro-unico-single.css');
            }
        });

        $app->hook('entity(Registration).setAgentsSealRelation:before', function (&$opportunityMetadataSeals) use ($app) {
            /** @var \MapasCulturais\Entities\Registration $this */
            SealRegistrationSync::hookSetAgentsSealRelation($this, $app, $opportunityMetadataSeals);
        });
        $app->hook('entity(Registration).unsetAgentsSealRelation:before', function (&$opportunityMetadataSeals) use ($app) {
            /** @var \MapasCulturais\Entities\Registration $this */
            SealRegistrationSync::hookUnsetAgentsSealRelation($this, $app, $opportunityMetadataSeals);
        });

        $app->hook('entity(AgentSealRelation).insert:finish', function ($flush = null) use ($app) {
            /** @var \MapasCulturais\Entities\AgentSealRelation $this */
            SealRegistrationSync::onSealApplied($this, $app);
        });

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

        $app->hook('entity(Registration).insert:before', function () use ($app) {
            /** @var \MapasCulturais\Entities\Registration $this */
            SealRegistrationSync::validateUniqueCategory($this, $app);
        });

        // DT-07: imutabilidade do owner da inscrição.
        $app->hook('entity(Registration).update:before', function () use ($app) {
            /** @var \MapasCulturais\Entities\Registration $this */
            SealRegistrationSync::validateOwnerImmutability($this, $app);
        });

        $app->hook('mapas.printJsObject:before', function () use ($app) {
            /** @var \MapasCulturais\Themes\BaseV2\Theme $this */

            if ($app->user->is('guest')) {
                return;
            }

            $opportunity = CadastroUnicoService::findCadastroUnicoOpportunity($app);
            if (!$opportunity) {
                return;
            }

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

          
            $payload = CadastroUnicoService::cacheFetch($app, $user_id);
            if ($payload === null) {
                try {
                    $payload = CadastroUnicoService::buildStatusPayload($app, $agent);
                } catch (\Throwable $e) {
                    $app->log->error(sprintf(
                        '[CadastroUnico] tarja buildStatusPayload falhou: %s | %s:%d',
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

            $tarja = $payload['tarja'] ?? null;

            if (!$tarja || $tarja['visivel'] !== true || !empty($tarja['suppressForRole'])) {
                return;
            }

            $this->jsObject['cadastroUnicoBanner'] = [
                'visivel'  => true,
                'condicao' => $tarja['condicao'],
                'detalhes' => $tarja['detalhes'] ?? [],
            ];
        });

        $app->hook('template(<<*>>.body):begin', function () {
            /** @var \MapasCulturais\Themes\BaseV2\Theme $this */
            $this->import('cadastro-unico--banner');
            echo "<template is='vue:cadastro-unico--banner'></template>";
        });

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
                    '[CadastroUnico] cache invalidation (Registration) falhou: %s',
                    $e->getMessage()
                ));
            }
        };
        $app->hook('entity(Registration).insert:finish', $invalidate_registration);
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
                    '[CadastroUnico] cache invalidation (AgentSealRelation) falhou: %s',
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

        $app->hook('entity(RegistrationMeta).save:before', function () use ($app) {
            /** @var \MapasCulturais\Entities\RegistrationMeta $this */

            $decision = CadastroUnicoService::isFieldEditable($app, $this);

            if ($decision['editable']) {
                return;
            }


            $uow = $app->em->getUnitOfWork();
            $original_data = $uow->getOriginalEntityData($this);
            if (isset($original_data['value']) && $original_data['value'] == $this->value) {
                return;
            }

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

        $app->hook('ApiQuery(Opportunity).where', function (&$where) use ($app) {
            /** @var string $where */
            OpportunityVisibilityFilter::applyFilter($app, $where);
        });
    }

    public function register()
    {
        $app = App::i();

        $app->registerController('cadastroUnico', CadastroUnico::class);

        $this->registerOpportunityMetadata('isCadastroUnico', [
            'label' => i::__('É Cadastro Único'),
            'type' => 'boolean',
            'default' => false,
            'validations' => [
                'v::optional(v::boolType())' => i::__('O valor de isCadastroUnico deve ser booleano'),
            ],
        ]);

        $this->registerOpportunityMetadata('cadastroUnicoCategorySeals', [
            'label' => i::__('Mapa categoria → selo (Cadastro Único)'),
            'type' => 'json',
            'default' => '{}',
        ]);

        $this->registerSealMetadata('isCadastroUnicoCategory', [
            'label' => i::__('Categoria do Cadastro Único'),
            'type' => 'select',
            'options' => [
                '' => i::__('Não pertence ao Cadastro Único'),
                Setup::CATEGORY_CERTIDOES => i::__('Certidões'),
                Setup::CATEGORY_DOCUMENTOS => i::__('Documentos obrigatórios'),
                Setup::CATEGORY_AUTODECLARACOES => i::__('Autodeclarações'),
            ],
        ]);
    }
}
