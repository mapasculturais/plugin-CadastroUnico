<?php
/**
 * @package CadastroUnico
 *
 * @var \MapasCulturais\App                                           $app
 * @var \MapasCulturais\Themes\BaseV2\Theme                           $this
 */

use MapasCulturais\i;

$this->import('
    mc-card
    mc-icon
    mc-link
    mc-loading
');
?>
<div class="panel--cadastro-unico">
    <?php $this->applyComponentHook('begin') ?>

    <mc-loading :condition="!loaded"></mc-loading>

    <mc-card
        v-if="loaded && !hasAnyInscricao"
        class="panel--cadastro-unico__cta"
    >
        <template #content>
            <div class="panel--cadastro-unico__cta-body">
                <div class="panel--cadastro-unico__cta-icon" aria-hidden="true">
                    <mc-icon name="info-full"></mc-icon>
                </div>

                <div class="panel--cadastro-unico__cta-text">
                    <h2 class="panel--cadastro-unico__cta-title">
                        <?= i::__('Complete seu cadastro único') ?>
                    </h2>
                    <p class="panel--cadastro-unico__cta-description">
                        <?= i::__('Centralize e valide seus documentos — certidões, documentos obrigatórios e autodeclarações — em um só lugar.') ?>
                    </p>

                    <mc-link
                        route="cadastroUnico/single"
                        class="button button--primary button--icon panel--cadastro-unico__cta-button"
                    >
                        <mc-icon name="add" aria-hidden="true"></mc-icon>
                        <?= i::__('Iniciar meu cadastro') ?>
                    </mc-link>
                </div>
            </div>
        </template>
    </mc-card>

    <section
        v-if="loaded && hasAnyInscricao"
        class="panel--cadastro-unico__grid"
        :aria-label="<?= i::esc_attr_e('Status do seu cadastro único por categoria') ?>"
    >
        <header class="panel--cadastro-unico__grid-header">
            <h2 class="panel--cadastro-unico__grid-title">
                <?= i::__('Cadastro único') ?>
            </h2>
            <mc-link
                route="cadastroUnico/single"
                class="panel--cadastro-unico__grid-link"
            >
                <?= i::__('Ver detalhes') ?>
                <mc-icon name="arrowPoint-right" aria-hidden="true"></mc-icon>
            </mc-link>
        </header>

        <ul class="panel--cadastro-unico__categories">
            <li
                v-for="cat in categorias"
                :key="cat.slug"
                class="panel--cadastro-unico__category"
                :class="[
                    'panel--cadastro-unico__category--' + cat.slug,
                    'panel--cadastro-unico__category--status-' + cat.derivedStatus,
                    { 'panel--cadastro-unico__category--optional-empty': !cat.obrigatoria && !cat.inscricao }
                ]"
            >
                <article>
                    <header class="panel--cadastro-unico__category-header">
                        <h3 class="panel--cadastro-unico__category-name">
                            {{ cat.nome }}
                        </h3>

                        <span
                            v-if="!cat.obrigatoria"
                            class="panel--cadastro-unico__category-optional-tag"
                            :aria-label="<?= i::esc_attr_e('Categoria opcional') ?>"
                        >
                            <?= i::__('Opcional') ?>
                        </span>
                    </header>

                    <span
                        class="panel--cadastro-unico__badge"
                        :class="'panel--cadastro-unico__badge--' + cat.derivedStatus"
                        :aria-label="badgeAriaLabel(cat)"
                        role="status"
                    >
                        <mc-icon
                            :name="statusIcon(cat.derivedStatus)"
                            class="panel--cadastro-unico__badge-icon"
                            aria-hidden="true"
                        ></mc-icon>
                        <span class="panel--cadastro-unico__badge-text">
                            {{ statusLabel(cat.derivedStatus) }}
                        </span>
                    </span>

                    <dl v-if="cat.inscricao" class="panel--cadastro-unico__category-meta">
                        <dt class="panel--cadastro-unico__category-meta-term">
                            <?= i::__('Enviado em') ?>
                        </dt>
                        <dd class="panel--cadastro-unico__category-meta-data">
                            <template v-if="cat.inscricao.sentDate">{{ cat.inscricao.sentDate }}</template>
                            <template v-else><?= i::__('Sem data de envio') ?></template>
                        </dd>
                    </dl>
                </article>
            </li>
        </ul>
    </section>

    <?php $this->applyComponentHook('end') ?>
</div>
