app.component('panel--cadastro-unico', {
    template: $TEMPLATES['panel--cadastro-unico'],

    setup() {
        const text = Utils.getTexts('panel--cadastro-unico');
        const status = $MAPAS?.config?.cadastroUnicoStatus || null;

        return { text, status };
    },

    data() {
        return {
            loaded: true,
        };
    },

    computed: {
        categorias() {
            return this.status?.categorias || [];
        },

        hasAnyInscricao() {
            return this.categorias.some((cat) => cat.inscricao !== null);
        },
    },

    methods: {
        statusLabel(status) {
            return this.text(status);
        },

        statusIcon(status) {
            const map = {
                fully_valid: 'circle-checked',     // verde (sucesso)
                no_expiration: 'circle-checked',   // verde (válido perpétuo)
                partially_valid: 'exclamation',    // amarelo (atenção)
                about_to_expire: 'exclamation',    // amarelo (atenção)
                invalid: 'exclamation',            // vermelho (erro)
                expired: 'exclamation',            // vermelho (erro)
                pending: 'info-full',              // cinza (info neutro)
            };
            return map[status] || 'info-full';
        },

        badgeAriaLabel(cat) {
            return `${cat.nome}: ${this.statusLabel(cat.derivedStatus)}`;
        },
    },
});
