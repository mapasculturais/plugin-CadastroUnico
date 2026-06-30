<?php
/**
 *
 * @package CadastroUnico
 *
 * @var \MapasCulturais\App                                           $app
 * @var \MapasCulturais\Themes\BaseV2\Theme                           $this
 */

use MapasCulturais\i;

$this->import('
    entity-field
    entity-file
    mc-alert
    mc-icon
    mc-link
    mc-loading
');
?>
<div class="cadastro-unico--category-fields">
    <mc-loading :condition="!ready"></mc-loading>

    <div v-if="ready && !hasRegistration" class="cadastro-unico--category-fields__empty">
        <div class="cadastro-unico--category-fields__empty-icon">
            <mc-icon name="info-full"></mc-icon>
        </div>
        <div class="cadastro-unico--category-fields__empty-content">
            <p class="cadastro-unico--category-fields__empty-title">
                <?= i::__('Você ainda não iniciou esta categoria.') ?>
            </p>
            <p class="cadastro-unico--category-fields__empty-text">
                <?= i::__('Inicie o preenchimento para gerenciar seus documentos nesta categoria.') ?>
            </p>
            <mc-link route="cadastroUnico/createRegistration" :get-params="{category: categorySlug}" class="cadastro-unico--category-fields__cta button button--primary">
                <?= i::__('Começar agora') ?>
            </mc-link>
        </div>
    </div>

    <template v-if="ready && hasRegistration">
        <div class="cadastro-unico--category-fields__alerts">
            <mc-alert
                v-if="allFieldsValid"
                type="success"
            >
                <?= i::__('Tudo certo! Não há nada a atualizar nesta categoria.') ?>
            </mc-alert>

            <mc-alert
                v-if="hasEditableFields && !allFieldsValid"
                type="warning"
            >
                <?= i::__('Há campos a atualizar nesta categoria. Campos válidos estão bloqueados para edição.') ?>
            </mc-alert>
        </div>

        <div class="cadastro-unico--category-fields__list">
            <template v-for="field in fieldsConfig" :key="field.id">
                <entity-file
                    v-if="isFileField(field)"
                    :entity="registration"
                    :disabled="!field.editable"
                    :group-name="field.groupName"
                    :title="field.title"
                    :description="field.description"
                    :editable="field.editable"
                    :required="field.required"
                    show-empty
                ></entity-file>

                <entity-field
                    v-else
                    :entity="registration"
                    :prop="field.fieldName"
                    :disabled="!field.editable"
                    :field-description="field.description"
                    :registration-field-configuration="field"
                    description-first
                ></entity-field>
            </template>
        </div>

        <mc-alert
            v-if="registration && registration.status < 1"
            type="helper"
        >
            <?= i::__('Sua inscrição nesta categoria está em rascunho. Preencha os campos e envie quando estiver pronto.') ?>
        </mc-alert>
    </template>
</div>
