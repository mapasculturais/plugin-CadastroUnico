<?php
/**
 * cadastro-unico--banner — texts.php
 *
 * Strings traduzíveis do componente Vue da tarja amarela (task T19, Fatia D).
 * Carregadas via Utils.getTexts() no script.js e acessadas como
 * this.text('chave') no template.
 *
 * DT-17 (i18n) — PLACEHOLDERS NOMEADOS:
 *   Uso de %1$s, %2$s, %3$s (NUNCA %s posicional). Isto permite que
 *   traduções reordenem os placeholders conforme a gramática do idioma
 *   alvo. Ex.: em japonês, "campo X da categoria Y expira em Z" pode
 *   querer ordem diferente de PT-BR.
 *
 *   Aplicação no script.js (método `message()`):
 *     this.text('msg_selo_vencido_obrigatorio')
 *         .replace('%1$s', detalhes.campo)
 *         .replace('%2$s', detalhes.categoria)
 *         .replace('%3$s', detalhes.data);
 *
 * Q8 (prioridade garantida pelo backend):
 *   Apenas UMA condição chega ao frontend por vez. As mensagens abaixo
 *   são os templates por condição. Ordem de prioridade (decidida backend):
 *     1. selo_vencido_obrigatorio (mais urgente — categoria inválida)
 *     2. selo_vencido_opcional
 *     3. categoria_obrigatoria_faltante
 *     4. sem_cadastro (menos urgente)
 *
 * Q9: vocabulário canônico "Prestes a expirar" não aparece aqui (tarja
 * mostra apenas o que está vencido OU ausente, não o que está prestes).
 *
 * @package CadastroUnico2
 */

use MapasCulturais\i;

return [
    // =====================================================================
    // Mensagens dinâmicas por condição (uma por vez — Q8 prioriza backend)
    // =====================================================================

    // Condição 4 (menos urgente): usuário não iniciou nenhum cadastro.
    'msg_sem_cadastro' => i::__('Você ainda não fez seu cadastro único.'),

    // Condição 3: falta uma das 2 categorias obrigatórias.
    // %1$s = nome da categoria obrigatória faltante (ex.: "Certidões").
    'msg_categoria_obrigatoria_faltante' => i::__('Você ainda não enviou os documentos da categoria %1$s.'),

    // Condição 2: categoria OPCIONAL (Autodeclarações) com selo inválido.
    // %1$s = nome da categoria opcional.
    'msg_selo_vencido_opcional' => i::__('Sua categoria %1$s está com o selo inválido.'),

    // Condição 1 (mais urgente): categoria OBRIGATÓRIA com selo vencido.
    // %1$s = nome do campo que expirou (ex.: "comprovante de residência")
    // %2$s = nome da categoria (ex.: "Documentos obrigatórios")
    // %3$s = data de expiração no formato DD/MM/AAAA (Q10 — mais antiga)
    'msg_selo_vencido_obrigatorio' => i::__('Atenção: %1$s da categoria %2$s expirou em %3$s.'),

    // =====================================================================
    // Rótulos de CTA — verbo varia conforme condição para refletir a ação
    // =====================================================================
    'cta_iniciar'   => i::__('Iniciar agora'),  // sem_cadastro
    'cta_enviar'    => i::__('Enviar'),         // categoria_obrigatoria_faltante
    'cta_ver'       => i::__('Ver'),            // selo_vencido_opcional
    'cta_atualizar' => i::__('Atualizar'),      // selo_vencido_obrigatorio

    // =====================================================================
    // Acessibilidade — aria-labels
    // =====================================================================

    // Prefixo do aria-label do <aside> (role=status, aria-live=polite).
    // Combinado com a mensagem: "Aviso: Você ainda não fez seu cadastro único."
    'banner_aria_prefix' => i::__('Aviso'),

    // aria-label do botão fechar (apenas ícone X, sem texto visível).
    'close_aria' => i::__('Fechar aviso de cadastro único'),
];
