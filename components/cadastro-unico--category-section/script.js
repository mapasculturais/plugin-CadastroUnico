app.component('cadastro-unico--category-section', {
    template: $TEMPLATES['cadastro-unico--category-section'],

    props: {
        categorySlug: {
            type: String,
            required: true,
        },

        categoryName: {
            type: String,
            required: true,
        },

        status: {
            type: String,
            default: 'pending',
            validator: (value) => [
                'fully_valid',
                'partially_valid',
                'invalid',
                'about_to_expire',
                'expired',
                'no_expiration',
                'pending',
            ].includes(value),
        },

        sentDate: {
            type: String,
            default: null,
        },

        partialInvalidDate: {
            type: String,
            default: null,
        },

        partialInvalidField: {
            type: String,
            default: null,
        },

        invalidDate: {
            type: String,
            default: null,
        },

        invalidField: {
            type: String,
            default: null,
        },

        initiallyOpen: {
            type: Boolean,
            default: false,
        },
    },

    setup(props, { slots }) {
        const text = Utils.getTexts('cadastro-unico--category-section');
        const hasSlot = (name) => !!slots[name];
        return { text, hasSlot };
    },

    computed: {
        statusLabel() {
            return this.text(this.status);
        },

        statusIconName() {
            const map = {
                fully_valid: 'circle-checked',     // verde (sucesso)
                no_expiration: 'circle-checked',   // verde (sem expiração = válido perpétuo)
                partially_valid: 'exclamation',    // amarelo (atenção)
                about_to_expire: 'exclamation',    // amarelo (atenção)
                invalid: 'exclamation',            // vermelho (erro)
                expired: 'exclamation',            // vermelho (erro)
                pending: 'info-full',              // cinza (info neutro)
            };
            return map[this.status] || 'info-full';
        },

        ariaLabel() {
            return `${this.categoryName}: ${this.statusLabel}`;
        },

        hasAnyMeta() {
            return Boolean(
                this.sentDate ||
                this.partialInvalidDate ||
                this.invalidDate
            );
        },
    },

    mounted() {
        if (this.initiallyOpen && this.$el && this.$el.tagName === 'DETAILS') {
            this.$el.open = true;
        }
    },
});
