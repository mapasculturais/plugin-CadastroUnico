app.component('cadastro-unico--banner', {
    template: $TEMPLATES['cadastro-unico--banner'],

    setup() {
        const text = Utils.getTexts('cadastro-unico--banner');
        const banner = $MAPAS?.cadastroUnicoBanner || { visivel: false };

        return { text, banner };
    },

    data() {
        return {
            visible: true,
        };
    },

    computed: {
        shouldRender() {
            return this.banner?.visivel === true && this.visible === true;
        },

        iconName() {
            return 'exclamation';
        },

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

        closeAriaLabel() {
            return this.text('close_aria');
        },

        bannerAriaLabel() {
            const prefix = this.text('banner_aria_prefix');
            const msg = this.message;
            return msg ? `${prefix}: ${msg}` : prefix;
        },
    },

    methods: {
        dismiss() {
            this.visible = false;
        },
    },
});
