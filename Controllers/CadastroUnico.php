<?php
/**
 *
 * @see        \MapasCulturais\App::registerController()
 * @see        config/routes.php (shortcut 'cadastro-unico')
 * @see        CadastroUnico2\Services\CadastroUnicoService
 *
 * @package    CadastroUnico2
 * @subpackage Controllers
 */

namespace CadastroUnico2\Controllers;

use CadastroUnico2\Services\CadastroUnicoService;
use CadastroUnico2\Setup;
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

    /**
     * Fluxo:
     *   1. requireAuthentication() — redireciona guest para login
     *      (e de volta para /cadastro-unico após autenticar).
     *   2. Pré-computa o breadcrumb [Início → Painel → Cadastro único]
     *      no controller para que a view seja "burra" quanto a URLs e
     *      para permitir teste unitário da lógica de navegação.
     *   3. Renderiza a view 'single' (resolvida para
     *      views/cadastrounico/single.php neste plugin via addPath() do
     *      Module base).
     *
     * As 3 seções colapsáveis com formulário dinâmico são implementadas
     * pela view (Fatia B — T14, Frontend). Este controller entrega apenas
     * o esqueleto navegável.
     *
     * @return void
     */
    public function GET_single()
    {
        $app = App::i();

        // (1) Usuário deve estar autenticado. O MapasCulturais redireciona
        // guest para a página de login e retorna para cá após sucesso.
        $this->requireAuthentication();

        // (2) Breadcrumb pré-computado no controller (Task T12 exige que
        // o controller defina o breadcrumb). A view consome via $breadcrumb.
        // Usamos createUrl() para respeitar subsites e base-path.
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

        // (3) Renderiza a view do plugin. render() resolve
        // "{templatePrefix}/single" → "cadastrounico/single" nos paths
        // de template registrados (plugins têm prioridade 50).
        $this->render('single', [
            'breadcrumb' => $breadcrumb,
        ]);
    }


    /**
     * Retorna o status consolidado do Cadastro Único do agente alvo.
     * Toda a lógica delegada a CadastroUnicoService::buildStatusPayload().
     *
     * Códigos HTTP:
     *   401 — guest (autenticação necessária)
     *   403 — sem permissão canUser('view') no agente (proteção IDOR)
     *   404 — agente inexistente OU plugin não instalado
     *   200 — sucesso (payload DT-10)
     *
     * @return void
     */
    public function API_status()
    {
        $app = App::i();

        // (1) Autenticação — 401 explícito se guest.
        if ($app->user->is('guest')) {
            $this->errorJson(
                ['error' => i::__('Autenticação necessária para consultar o status do Cadastro Único.')],
                401
            );
            return;
        }

        // (2) Resolve agente alvo: ?agent={id} opcional, default profile.
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

        // (3) Permissão: o usuário só pode consultar seu próprio status
        // ou o de um agente que ele controla. Admins podem consultar
        // qualquer agente.
        $is_own_profile = $app->user->profile && $app->user->profile->id === $target_agent->id;
        $is_admin = $app->user->is('admin');
        if (!$is_own_profile && !$is_admin) {
            $this->errorJson(
                ['error' => i::__('Você não tem permissão para visualizar o status do Cadastro Único deste agente.')],
                403
            );
            return;
        }

        // (4) Delegação ao Service. Retorna null se plugin não instalado.
        $payload = CadastroUnicoService::buildStatusPayload($app, $target_agent);
        if ($payload === null) {
            $this->errorJson(
                ['error' => i::__('O Cadastro Único não está configurado nesta instalação. Execute o db-update do plugin CadastroUnico2.')],
                404
            );
            return;
        }

        // (5) Sucesso — payload.
        $this->json($payload);
    }

    /**
     * Cria uma inscrição em rascunho para o usuário logado na categoria
     * informada (query string ?category={slug}). Se já existir inscrição
     * ativa (status >= 0) para o par (oportunidade, owner, categoria),
     * redireciona para a edição da inscrição existente (idempotência).
     *
     * Fluxo:
     *   1. requireAuthentication() — guest vai para login.
     *   2. Resolve o slug da categoria e valida contra Setup::CATEGORIES.
     *   3. Obtém a oportunidade do Cadastro Único via Service.
     *   4. Verifica permissão canUser('register') na oportunidade.
     *   5. Usa $app->user->profile como owner.
     *   6. Verifica inscrição ativa existente; se houver, redirect editUrl.
     *   7. Cria nova Registration com status STATUS_DRAFT e salva.
     *   8. Redireciona para $registration->editUrl.
     *
     * Códigos HTTP:
     *   302 — sucesso (redirect para editUrl da inscrição nova/existente)
     *   400 — slug de categoria inválido ou usuário sem profile
     *   403 — usuário não pode se inscrever na oportunidade
     *   404 — oportunidade do Cadastro Único não configurada
     *   500 — erro inesperado na criação (logado)
     *
     * @return void
     */
    public function GET_createRegistration()
    {
        $app = App::i();

        // (1) Apenas usuários autenticados.
        $this->requireAuthentication();

        try {
            // (2) Slug da categoria via query string.
            $category_slug = $this->getData['category'] ?? null;
            if (!is_string($category_slug) || $category_slug === '' || !isset(Setup::CATEGORIES[$category_slug])) {
                $app->halt(400, i::__('Categoria inválida.'));
                return;
            }
            $category_label = Setup::CATEGORIES[$category_slug];

            // (3) Oportunidade do Cadastro Único.
            $opportunity = CadastroUnicoService::findCadastroUnicoOpportunity($app);
            if (!$opportunity) {
                $app->halt(404, i::__('O Cadastro Único não está configurado nesta instalação.'));
                return;
            }

            // (4) Permissão de inscrição.
            if (!$opportunity->canUser('register', $app->user)) {
                $app->halt(403, i::__('Você não tem permissão para se inscrever nesta oportunidade.'));
                return;
            }

            // (5) Owner = profile do usuário logado.
            $owner = $app->user->profile;
            if (!$owner || !$owner->id) {
                $app->halt(400, i::__('Usuário sem perfil válido.'));
                return;
            }

            // (6) Verifica inscrição ativa existente (idempotência).
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

            // (7) Cria nova inscrição em rascunho.
            $registration = new Registration();
            $registration->opportunity = $opportunity;
            $registration->owner = $owner;
            $registration->category = $category_label;
            $registration->proponentType = 'default';
            $registration->range = 'default';

            // Define status DRAFT sem disparar o setter setStatusToDraft(),
            // que exigiria permissão 'changeStatus' (usuário comum não tem).
            // O default da entidade já é DRAFT, mas reafirmamos explicitamente
            // por atribuição direta via binding (mesmo padrão de
            // SealRegistrationSync::createApprovedRegistration).
            $set_status = \Closure::bind(function (int $status) {
                $this->status = $status;
            }, $registration, Registration::class);
            $set_status(Registration::STATUS_DRAFT);

            $registration->save(true);

            // (8) Redireciona para edição.
            $app->redirect($registration->editUrl);
        } catch (\MapasCulturais\Exceptions\Halt $e) {
            // Halt já ajustou a resposta; deixa subir.
            throw $e;
        } catch (\Throwable $e) {
            // Erro inesperado: loga e retorna 500 com mensagem amigável.
            $app->log->error(sprintf(
                '[CadastroUnico2] GET_createRegistration falhou: %s | %s:%d',
                $e->getMessage(),
                $e->getFile(),
                $e->getLine()
            ));

            $app->halt(500, i::__('Erro ao criar a inscrição. Tente novamente mais tarde.'));
        }
    }
}
