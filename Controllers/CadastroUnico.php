<?php
namespace CadastroUnico\Controllers;

use CadastroUnico\Entities\CadastroUnicoService;
use CadastroUnico\Setup;
use MapasCulturais\App;
use MapasCulturais\Entities\Registration;
use MapasCulturais\i;

class CadastroUnico extends \MapasCulturais\Controller
{
    use \MapasCulturais\Traits\ControllerAPI;

    public function __construct()
    {
        $this->layout = 'default';
    }

    public function GET_single()
    {
        $app = App::i();

        $this->requireAuthentication();

        $breadcrumb = [
            [
                'label' => i::__('Início'),
                'url'   => $app->createUrl('site', 'index'),
            ],
            [
                'label' => i::__('Painel'),
                'url'   => $app->createUrl('panel', 'index'),
            ],
            [
                'label' => i::__('Cadastro único'),
                'url'   => $app->createUrl('cadastroUnico', 'single'),
            ],
        ];

        $this->render('single', [
            'breadcrumb' => $breadcrumb,
        ]);
    }


    public function API_status()
    {
        $app = App::i();

        if ($app->user->is('guest')) {
            $this->errorJson(
                ['error' => i::__('Autenticação necessária para consultar o status do Cadastro Único.')],
                401
            );
            return;
        }

        $agent_id = isset($this->data['agent']) ? (int) $this->data['agent'] : null;
        $target_agent = null;
        if ($agent_id === null) {
            $target_agent = $app->user->profile ?: null;
        } elseif ($agent_id > 0) {
            $target_agent = $app->repo('Agent')->find($agent_id) ?: null;
        }

        if ($target_agent === null) {
            $this->errorJson(
                ['error' => i::__('Agente alvo não encontrado.')],
                404
            );
            return;
        }

        $is_own_profile = $app->user->profile && $app->user->profile->id === $target_agent->id;
        $is_admin = $app->user->is('admin');
        if (!$is_own_profile && !$is_admin) {
            $this->errorJson(
                ['error' => i::__('Você não tem permissão para visualizar o status do Cadastro Único deste agente.')],
                403
            );
            return;
        }

        $payload = CadastroUnicoService::buildStatusPayload($app, $target_agent);
        if ($payload === null) {
            $this->errorJson(
                ['error' => i::__('O Cadastro Único não está configurado nesta instalação. Execute o db-update do plugin CadastroUnico.')],
                404
            );
            return;
        }

        $this->json($payload);
    }

    public function GET_createRegistration()
    {
        $app = App::i();

        $this->requireAuthentication();

        try {
            $category_slug = $this->getData['category'] ?? null;
            if (!is_string($category_slug) || $category_slug === '' || !isset(Setup::CATEGORIES[$category_slug])) {
                $app->halt(400, i::__('Categoria inválida.'));
                return;
            }
            $category_label = Setup::CATEGORIES[$category_slug];

            $opportunity = CadastroUnicoService::findCadastroUnicoOpportunity($app);
            if (!$opportunity) {
                $app->halt(404, i::__('O Cadastro Único não está configurado nesta instalação.'));
                return;
            }

            if (!$opportunity->canUser('register', $app->user)) {
                $app->halt(403, i::__('Você não tem permissão para se inscrever nesta oportunidade.'));
                return;
            }

            $owner = $app->user->profile;
            if (!$owner || !$owner->id) {
                $app->halt(400, i::__('Usuário sem perfil válido.'));
                return;
            }

            $existing_registrations = $app->repo('Registration')->findBy([
                'opportunity' => $opportunity,
                'owner'       => $owner,
                'category'    => $category_label,
            ]);

            foreach ($existing_registrations as $existing) {
                if ($existing->status >= 0) {
                    $app->redirect($existing->editUrl);
                    return;
                }
            }

            $registration = new Registration();
            $registration->opportunity = $opportunity;
            $registration->owner = $owner;
            $registration->category = $category_label;
            $registration->proponentType = 'default';
            $registration->range = 'default';

            $set_status = \Closure::bind(function (int $status) {
                $this->status = $status;
            }, $registration, Registration::class);
            $set_status(Registration::STATUS_DRAFT);

            $registration->save(true);

            $app->redirect($registration->editUrl);
        } catch (\MapasCulturais\Exceptions\Halt $e) {
            throw $e;
        } catch (\Throwable $e) {
            $app->log->error(sprintf(
                '[CadastroUnico] GET_createRegistration falhou: %s | %s:%d',
                $e->getMessage(),
                $e->getFile(),
                $e->getLine()
            ));

            $app->halt(500, i::__('Erro ao criar a inscrição. Tente novamente mais tarde.'));
        }
    }
}
