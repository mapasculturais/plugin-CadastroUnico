<?php
/**
 *
 * @package CadastroUnico2
 *
 * @var \MapasCulturais\App                                           $app
 * @var \MapasCulturais\Themes\BaseV2\Theme                           $this
 */

use CadastroUnico2\Services\CadastroUnicoService;
use MapasCulturais\App;

$app = App::i();

// Payload padrão (vazio). Em qualquer caminho de saída (sucesso, guest,
// plugin ausente, falha), este payload é publicado no jsObject para o
// componente Vue consumir defensivamente.
$empty_payload = [
    'opportunity' => [
        'id'        => null,
        'singleUrl' => $app->createUrl('cadastroUnico', 'single'),
    ],
    'agent'      => null,
    'categorias' => [],
    'tarja'      => null,
];

try {
    // Guarda 1: guest não tem agente profile → widget não aparece.
    if ($app->user->is('guest')) {
        $this->jsObject['config']['cadastroUnicoStatus'] = $empty_payload;
        return;
    }

    // Guarda 2: usuário sem agente profile (raro, conta recém-criada).
    $agent = $app->user->profile;
    if (!$agent || !$agent->id) {
        $this->jsObject['config']['cadastroUnicoStatus'] = $empty_payload;
        return;
    }

    // Retorna null se plugin não instalado (sem Opportunity isCadastroUnico2).
    $payload = CadastroUnicoService::buildStatusPayload($app, $agent);
    if ($payload === null) {
        $this->jsObject['config']['cadastroUnicoStatus'] = $empty_payload;
        return;
    }

    $this->jsObject['config']['cadastroUnicoStatus'] = $payload;
} catch (\Throwable $e) {
    // Falha isolada: painel continua funcionando, apenas sem o widget.
    // Loga para diagnóstico
    $app->log->error(sprintf(
        '[CadastroUnico2] panel--cadastro-unico init.php falhou: %s | %s:%d',
        $e->getMessage(),
        $e->getFile(),
        $e->getLine()
    ));
    $this->jsObject['config']['cadastroUnicoStatus'] = $empty_payload;
}
