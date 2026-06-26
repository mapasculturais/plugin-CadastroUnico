app.component('cadastro-unico--category-fields', {
    template: $TEMPLATES['cadastro-unico--category-fields'],

    props: {
        categorySlug: {
            type: String,
            required: true,
        },
    },

    setup(props) {
        const text = Utils.getTexts('cadastro-unico--category-fields');

        const singles = $MAPAS?.cadastroUnicoSingle || {};
        const categoryData = singles[props.categorySlug] || null;

        return { text, categoryData };
    },

    data() {
        return {
            ready: false,
            registration: null,
        };
    },

    async created() {
        await this.loadRegistration();
        this.ready = true;
    },

    computed: {
        fieldsConfig() {
            return this.categoryData?.fieldsConfig || [];
        },

        hasRegistration() {
            return this.categoryData?.registrationId != null;
        },

        allFieldsValid() {
            if (this.fieldsConfig.length === 0) {
                return false;
            }
            return this.fieldsConfig.every((f) => !f.editable);
        },

        hasEditableFields() {
            return this.fieldsConfig.some((f) => f.editable);
        },
    },

    methods: {
        async loadRegistration() {
            if (!this.hasRegistration) {
                this.registration = null;
                return;
            }

            try {
                const api = new API('registration');
                const reg = api.getEntityInstance(this.categoryData.registrationId);
                if (this.categoryData.registrationRaw) {
                    reg.populate(this.categoryData.registrationRaw);
                }
                this.registration = reg;
            } catch (e) {
                console.error('[CadastroUnico2] Falha ao materializar inscrição:', e);
                this.registration = null;
            }
        },

        isFileField(field) {
            return field.fieldType === 'file' || !!field.groupName;
        },
    },
});
