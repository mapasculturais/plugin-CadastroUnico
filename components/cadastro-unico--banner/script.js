/**
 * cadastro-unico--banner — Lógica Vue 3 (Options API).
 *
 * Tarja amarela persistente do Cadastro Único 2.0 (task T19, Fatia D).
 *
 * DT-14 — CRÍTICO: ESTADO EM MEMÓRIA, ZERO STORAGE.
 *   - data().visible inicia true
 *   - Botão fechar (dismiss()) seta visible=false
 *   - PROIBIDO localStorage, sessionStorage, cookie
 *   - Ao recarregar/navegar, o componente é remontado e volta a visible=true
 *   - MapasCulturais NÃO é SPA — cada navegação é GET tradicional →
 *     remontagem é natural e gratuita.
 *
 * Payload consumido de $MAPAS.cadastroUnicoBanner, populado pelo hook
 * template(<<*>>.body):before no Plugin.php (T20, Full-stack). O hook
 * também é responsável por:
 *   - Filtrar guest (não mostra para deslogado)
 *   - Filtrar admin/avaliador (Q6 — não mostra para gestores)
 *   - Calcular a condição prioritária (Q8 — selos vencidos em obrigatórias
 *     primeiro)
 *   - Popular `detalhes` com dados para placeholders
 *
 * O frontend apenas MONTA a mensagem i18n (não decide prioridade).
 *
 * @package CadastroUnico2
 */

app.component('cadastro-unico--banner', {
    template: $TEMPLATES['cadastro-unico--banner'],

    setup() {
        // Strings i18n do texts.php (padrão MapasCulturais).
        const text = Utils.getTexts('cadastro-unico--banner');

        // Payload populado pelo hook template(<<*>>.body):before (T20).
        // Defensive access (?.) — se o hook não rodou (plugin desativado,
        // erro no cache APCu, etc.), o objeto é {visivel:false} e o
        // banner simplesmente não renderiza (sem quebrar a página).
        const banner = $MAPAS?.cadastroUnicoBanner || { visivel: false };

        return { text, banner };
    },

    data() {
        return {
            // ============================================================
            // DT-14 — ESTADO EM MEMÓRIA (ZERO STORAGE)
            // ============================================================
            // visible inicia true. Botão fechar chama dismiss() que seta false.
            //
            // PROIBIDO (verificação QA AC-6.20):
            //   - localStorage.getItem/setItem
            //   - sessionStorage.getItem/setItem
            //   - document.cookie
            //
            // Ao recarregar a página (F5) ou navegar para outra URL, o
            // MapasCulturais faz um GET tradicional → todo o HTML é
            // re-renderizado → o Vue monta uma nova instância deste
            // componente → visible volta a true (reaparece). Isto é o
            // comportamento exigido pelo briefing:
            //   "Dismissable, mas reaparece ao trocar de página ou
            //    recarregar. Pode fechar temporariamente, mas não há
            //    persistência definitiva da dispensa."
            visible: true,
        };
    },

    computed: {
        /**
         * Renderiza o banner APENAS quando:
         *   1. Backend determinou visibilidade (banner.visivel === true) —
         *      já filtrou guest e admin (Q6) no hook T20.
         *   2. Usuário não dispensou nesta visualização atual (visible).
         *
         * Usado no v-if do elemento raiz <aside> — quando false, NADA é
         * renderizado no DOM (sem placeholder, sem comentário).
         */
        shouldRender() {
            return this.banner?.visivel === true && this.visible === true;
        },

        /**
         * Ícone de atenção para todas as condições. Sempre ícone + texto
         * (A11y: nunca só cor para daltônicos).
         *
         * O ícone `exclamation` é o mesmo usado por mc-alert type="warning".
         */
        iconName() {
            return 'exclamation';
        },

        /**
         * Mensagem i18n montada dinamicamente no frontend.
         *
         * Backend envia:
         *   banner.condicao (enum string, já priorizado conforme Q8)
         *   banner.detalhes (objeto com dados para placeholders)
         *
         * Frontend escolhe o template i18n (texts.php) e aplica placeholders
         * nomeados (%1$s, %2$s, %3$s — DT-17: permitem reordenação em
         * traduções, ex.: japonês pode querer ordem diferente).
         *
         * Condições (Q8 — prioridade garantida pelo backend):
         *   1. selo_vencido_obrigatorio (mais urgente)
         *   2. selo_vencido_opcional
         *   3. categoria_obrigatoria_faltante
         *   4. sem_cadastro (menos urgente)
         */
        message() {
            const condicao = this.banner?.condicao;
            const detalhes = this.banner?.detalhes || {};

            switch (condicao) {
                case 'sem_cadastro':
                    return this.text('msg_sem_cadastro');

                case 'categoria_obrigatoria_faltante':
                    // %1$s = nome da categoria obrigatória faltante
                    return this.text('msg_categoria_obrigatoria_faltante')
                        .replace('%1$s', detalhes.categoria || '');

                case 'selo_vencido_opcional':
                    // %1$s = nome da categoria opcional
                    return this.text('msg_selo_vencido_opcional')
                        .replace('%1$s', detalhes.categoria || '');

                case 'selo_vencido_obrigatorio':
                    // %1$s = nome do campo expirando
                    // %2$s = nome da categoria
                    // %3$s = data de expiração (DD/MM/AAAA)
                    return this.text('msg_selo_vencido_obrigatorio')
                        .replace('%1$s', detalhes.campo || '')
                        .replace('%2$s', detalhes.categoria || '')
                        .replace('%3$s', detalhes.data || '');

                default:
                    // Condição desconhecida — não exibir mensagem
                    // (degrade gracefully; banner.some via shouldRender
                    // se message vazia)
                    return '';
            }
        },

        /**
         * Rótulo do CTA (link para /cadastro-unico) conforme a condição.
         * Mesmo destino (/cadastro-unico/single) para todas — apenas o
         * verbo muda para refletir a ação esperada do usuário.
         */
        ctaLabel() {
            const condicao = this.banner?.condicao;
            switch (condicao) {
                case 'sem_cadastro':
                    return this.text('cta_iniciar');
                case 'categoria_obrigatoria_faltante':
                    return this.text('cta_enviar');
                case 'selo_vencido_opcional':
                    return this.text('cta_ver');
                case 'selo_vencido_obrigatorio':
                    return this.text('cta_atualizar');
                default:
                    return this.text('cta_ver');
            }
        },

        /**
         * aria-label do botão fechar. Explícito porque o botão contém
         * apenas um ícone (X) — sem texto visível, leitores de tela
         * precisam do nome acessível.
         */
        closeAriaLabel() {
            return this.text('close_aria');
        },

        /**
         * aria-label composto do <aside>: prefixo "Aviso" + mensagem.
         * Leitores de tela anunciam o banner de forma descritiva ao
         * montar/focar. Em conjunto com role="status" + aria-live=polite,
         * garante anúncio não-intrusivo quando o banner aparece.
         */
        bannerAriaLabel() {
            const prefix = this.text('banner_aria_prefix');
            const msg = this.message;
            return msg ? `${prefix}: ${msg}` : prefix;
        },
    },

    methods: {
        /**
         * Dispensa o banner APENAS nesta visualização.
         *
         * DT-14 (CRÍTICO): esta função apenas seta this.visible = false.
         * Nenhuma persistência é realizada. Ao recarregar a página ou
         * navegar, o componente é remontado e visible volta a true,
         * fazendo o banner reaparecer.
         *
         * Verificação QA (Suíte 8, AC-6.20): output HTML/JS deste
         * componente NÃO deve conter chamadas a localStorage,
         * sessionStorage ou document.cookie.
         */
        dismiss() {
            this.visible = false;
        },
    },
});
