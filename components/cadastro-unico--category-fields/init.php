<?php
/**
 * cadastro-unico--category-fields — init.php (PLACEHOLDER).
 *
 * Os dados deste componente NÃO são populados aqui — são populados pela
 * VIEW src/plugins/CadastroUnico2/views/cadastrounico/single.php, que
 * chama CadastroUnicoService::buildStatusPayload() +
 * buildCategoryFields() e injeta em:
 *
 *   $this->jsObject['cadastroUnicoSingle'][category_slug] = [
 *       'registrationId'   => int|null,
 *       'registrationRaw'  => array|null,  // jsonSerialize() da inscrição
 *       'fieldsConfig'     => array,       // saída de buildCategoryFields()
 *   ];
 *
 * POR QUE init.php existe mesmo vazio:
 *   O módulo Components espera 5 arquivos (template.php + script.js +
 *   style.css + init.php + texts.php). Manter este arquivo garante
 *   aderência ao padrão e documenta onde NÃO adicionar lógica de dados
 *   (para evitar duplicação com a view).
 *
 *   A view single.php é o local correto porque:
 *     1. Tem acesso ao Controller (que já resolveu a autenticação e o
 *        agente logado).
 *     2. Roda uma única vez por request (init.php roda sempre que o
 *        componente é importado — poderia rodar em outras páginas).
 *     3. Permite que a própria view decida o que mostrar (ex.: no futuro,
 *        um admin pode ver o cadastro único de outro agente com dados
 *        diferentes).
 *
 * @package CadastroUnico2
 *
 * @var \MapasCulturais\App                                           $app
 * @var \MapasCulturais\Themes\BaseV2\Theme                           $this
 */

// Intencionalmente vazio. Ver comentário acima.
