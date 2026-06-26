<?php
/**
 * cadastro-unico--banner — init.php (PLACEHOLDER).
 *
 * CONTRATO DE RESPONSABILIDADES (Fatia D — T19/T20):
 *
 *   - ESTE init.php: **NÃO** popula o payload da tarja. Permanece vazio.
 *
 *   - Quem popula `$this->jsObject['cadastroUnicoBanner']` é o HOOK
 *     `template(<<*>>.body):before` no `Plugin::_init()` (task T20,
 *     Full-stack Developer). O hook é o ponto correto porque:
 *       1. Roda em TODAS as páginas (não apenas onde o componente é
 *          importado — init.php só roda quando $this->import() acontece).
 *       2. Calcula a condição prioritária uma única vez por request.
 *       3. Usa cache APCu (DT-11, chave `cu:status:{userId}`, TTL 60s)
 *          para evitar recomputar a cada page load.
 *       4. Filtra guest (sem usuário = sem tarja) e admin/avaliador (Q6).
 *
 *   - O COMPONENTE Vue (template.php + script.js) apenas LÊ o payload
 *     via `$MAPAS.cadastroUnicoBanner` em `setup()` e monta a mensagem
 *     i18n no frontend (DT-10: backend envia enum + detalhes, frontend
 *     produz a string traduzida).
 *
 * POR QUE init.php existe mesmo vazio:
 *   O módulo Components do MapasCulturais (ver .architecture/themes/
 *   component-creation.md) espera a estrutura padrão de 5 arquivos
 *   (template.php + script.js + style.css + init.php + texts.php).
 *   Manter o init.php garante aderência ao padrão e fornece um local
 *   documentado para futura lógica específica do componente (ex.: se
 *   futuramente precisar expor uma flag de feature toggle específica
 *   do banner, ela seria populada aqui — distinta do payload global).
 *
 * @package CadastroUnico2
 *
 * @var \MapasCulturais\App                                           $app
 * @var \MapasCulturais\Themes\BaseV2\Theme                           $this
 */

// Intencionalmente vazio.
// Ver comentário acima para o contrato de responsabilidades T19/T20.
