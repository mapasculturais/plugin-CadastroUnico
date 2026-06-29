<?php
/**
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
