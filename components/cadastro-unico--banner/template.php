<?php
/**
 * cadastro-unico--banner — Tarja amarela persistente do Cadastro Único 2.0.
 *
 * Componente Vue 3 do plugin Cadastro Único 2.0 (task T19, Fatia D).
 * Aparece abaixo do header em TODAS as páginas (injetado via hook
 * template(<<*>>.body):begin no Plugin.php, populado por T20/Full-stack).
 *
 * COMPORTAMENTO CRÍTICO — DT-14 (estado em memória, ZERO storage):
 *   - data() visible=true inicial
 *   - Botão fechar (X) seta visible=false
 *   - PROIBIDO usar localStorage, sessionStorage ou cookies
 *   - Ao recarregar a página ou navegar para outra, o componente é
 *     REMONTADO pelo page load server-side e volta a visible=true
 *   - Isto atende literalmente o briefing: "reaparece ao trocar de página
 *     ou recarregar". MapasCulturais NÃO é SPA — cada navegação é um GET
 *     tradicional, então a remontagem é natural.
 *
 * RENDERIZAÇÃO CONDICIONAL (DT-14, Q6):
 *   - `shouldRender` = banner.visivel (backend já filtrou guest e admin
 *     via hook T20) && visible (estado em memória não dispensed)
 *   - v-if no elemento raiz <aside>: se false, NADA é renderizado
 *
 * MENSAGENS DINÂMICAS (Q8, T21):
 *   - O backend envia UM enum string `condicao` (já priorizado conforme Q8:
 *     selos vencidos em obrigatórias primeiro → obrigatórias faltantes →
 *     sem cadastro) + `detalhes` (objeto com dados para placeholders).
 *   - O frontend MONTA a mensagem i18n via texts.php + placeholders nomeados
 *     (%1$s, %2$s, %3$s — DT-17), permitindo reordenação em traduções.
 *
 * ACESSIBILIDADE (DT-15, mesmo filosofia do mc-alert):
 *   - role="status" (NÃO role="alert" — este interrompe leitura de tela)
 *   - aria-live="polite" (anúncio não-intrusivo)
 *   - aria-label composto no <aside> (anuncia "Aviso: <mensagem>")
 *   - Botão fechar com aria-label explícito + foco visível
 *   - Texto NUNCA só em cor — sempre ícone + texto
 *   - Cor amarela com texto escuro: contraste ~10:1 (muito acima AA)
 *
 * @package CadastroUnico2
 *
 * @var \MapasCulturais\App                                           $app
 * @var \MapasCulturais\Themes\BaseV2\Theme                           $this
 */

use MapasCulturais\i;

$this->import('
    mc-icon
    mc-link
');
?>
<aside
    v-if="shouldRender"
    class="cadastro-unico--banner"
    role="status"
    aria-live="polite"
    :aria-label="bannerAriaLabel"
>
    <div class="cadastro-unico--banner__content">
        <mc-icon
            :name="iconName"
            class="cadastro-unico--banner__icon"
            aria-hidden="true">
        </mc-icon>

        <p class="cadastro-unico--banner__message">
            <span class="cadastro-unico--banner__text">{{ message }}</span>
            <mc-link
                route="cadastroUnico/single"
                class="cadastro-unico--banner__cta"
            >
                {{ ctaLabel }}
            </mc-link>
        </p>

        <button
            type="button"
            class="cadastro-unico--banner__close"
            :aria-label="closeAriaLabel"
            @click="dismiss"
        >
            <mc-icon name="close" aria-hidden="true"></mc-icon>
        </button>
    </div>
</aside>
