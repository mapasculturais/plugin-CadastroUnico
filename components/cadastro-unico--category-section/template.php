<?php
/**
 * @package CadastroUnico
 *
 * @var \MapasCulturais\App $app
 * @var \MapasCulturais\Themes\BaseV2\Theme $this
 */

use MapasCulturais\i;

$this->import('
    mc-icon
');
?>
<details
    class="cadastro-unico--category-section"
    :class="[
        'cadastro-unico--category-section--' + categorySlug,
        'cadastro-unico--category-section--status-' + status
    ]"
>
    <summary class="cadastro-unico--category-section__summary">
        <header class="cadastro-unico--category-section__header">
            <h2 class="cadastro-unico--category-section__title">
                {{ categoryName }}
            </h2>

            <span
                class="cadastro-unico--category-section__badge"
                :class="'cadastro-unico--category-section__badge--' + status"
                :aria-label="ariaLabel"
                role="status"
            >
                <mc-icon :name="statusIconName" class="cadastro-unico--category-section__badge-icon"></mc-icon>
                <span class="cadastro-unico--category-section__badge-text">
                    {{ statusLabel }}
                </span>
            </span>

            <mc-icon
                name="arrowPoint-down"
                class="cadastro-unico--category-section__chevron"
                aria-hidden="true">
            </mc-icon>
        </header>
    </summary>

    <div class="cadastro-unico--category-section__content">
        <?php $this->applyComponentHook('before-content') ?>

        <dl v-if="hasAnyMeta" class="cadastro-unico--category-section__meta">
            <template v-if="sentDate">
                <dt class="cadastro-unico--category-section__meta-term">
                    <?= i::__('Enviado em') ?>
                </dt>
                <dd class="cadastro-unico--category-section__meta-data">
                    {{ sentDate }}
                </dd>
            </template>

            <template v-if="partialInvalidDate">
                <dt class="cadastro-unico--category-section__meta-term">
                    <?= i::__('Fica parcialmente inválido em') ?>
                </dt>
                <dd class="cadastro-unico--category-section__meta-data">
                    {{ partialInvalidDate }}
                    <span v-if="partialInvalidField" class="cadastro-unico--category-section__meta-detail">
                        <?= i::__('— campo:') ?> {{ partialInvalidField }}
                    </span>
                </dd>
            </template>

            <template v-if="invalidDate">
                <dt class="cadastro-unico--category-section__meta-term">
                    <?= i::__('Fica inválido em') ?>
                </dt>
                <dd class="cadastro-unico--category-section__meta-data">
                    {{ invalidDate }}
                    <span v-if="invalidField" class="cadastro-unico--category-section__meta-detail">
                        <?= i::__('— campo:') ?> {{ invalidField }}
                    </span>
                </dd>
            </template>
        </dl>

        <div class="cadastro-unico--category-section__slot">
            <slot></slot>
        </div>

        <?php $this->applyComponentHook('after-content') ?>
    </div>
</details>
