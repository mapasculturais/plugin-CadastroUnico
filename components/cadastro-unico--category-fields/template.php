<?php
/**
 *
 * @package CadastroUnico2
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

    <mc-alert v-if="ready && !hasRegistration" type="helper">
        <?= i::__('Você ainda não iniciou esta categoria.') ?>
        <mc-link route="cadastroUnico/createRegistration" :get-params="{category: categorySlug}" class="cadastro-unico--category-fields__cta">
            <?= i::__('Começar agora') ?>
        </mc-link>
    </mc-alert>

    <template v-if="ready && hasRegistration">
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
