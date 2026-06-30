<?php
/**
 *
 * @var \MapasCulturais\App                                           $app
 * @var \MapasCulturais\Themes\BaseV2\Theme                           $this
 * @var array                                                         $breadcrumb
 */

use CadastroUnico\Entities\CadastroUnicoService;
use CadastroUnico\Setup;
use MapasCulturais\i;

$this->layout = 'default';

$this->breadcrumb = $breadcrumb;

$agent = $app->user->profile;
$status_payload = CadastroUnicoService::buildStatusPayload($app, $agent);

$categories_status_by_slug = [];
if ($status_payload && !empty($status_payload['categorias'])) {
    foreach ($status_payload['categorias'] as $cat) {
        $categories_status_by_slug[$cat['slug']] = $cat;
    }
}

$opportunity = null;
if ($status_payload && !empty($status_payload['opportunity']['id'])) {
    $opportunity = $app->repo('Opportunity')->find($status_payload['opportunity']['id']);
}

$category_seals = [];
if ($opportunity) {
    $raw = $opportunity->cadastroUnicoCategorySeals;
    $category_seals = is_object($raw)
        ? (array) $raw
        : (array) json_decode($raw ?? '{}', true);
}

$categories_data = [];

foreach (Setup::CATEGORIES as $slug => $label) {
    $cat_status = $categories_status_by_slug[$slug] ?? null;
    $selo_data = $cat_status['selo'] ?? null;

    $fields = [];
    if ($opportunity && $agent) {
        $seal_id = $category_seals[$slug] ?? null;
        $seal = $seal_id ? $app->repo('Seal')->find($seal_id) : null;
        $fields = CadastroUnicoService::buildCategoryFields(
            $app,
            $opportunity,
            $agent,
            $label,
            $seal
        );
    }

    $registration_id = null;
    $registration_raw = null;
    if ($opportunity && $agent) {
        $candidates = $app->repo('Registration')->findBy([
            'opportunity' => $opportunity,
            'owner'       => $agent,
            'category'    => $label,
        ]);
        foreach ($candidates as $reg) {
            if ($reg->status >= 0) {
                $registration_id = $reg->id;
                $registration_raw = $reg->jsonSerialize();
                break;
            }
        }
    }

    $initially_open = CadastroUnicoService::shouldSectionAutoOpen(
        $cat_status ?? [],
        $fields
    );

    $derived_status = $cat_status['derivedStatus'] ?? 'pending';

    $sent_date = $cat_status['inscricao']['sentDate'] ?? null;
    $partial_invalid_date = $selo_data['partialInvalidDate'] ?? null;
    $partial_invalid_field = $selo_data['partialInvalidField'] ?? null;
    $invalid_date = $selo_data['invalidDate'] ?? null;
    $invalid_field = $selo_data['invalidField'] ?? null;

    $categories_data[$slug] = [
        'slug'                 => $slug,
        'label'                => $label,
        'status'               => $derived_status,
        'sentDate'             => $sent_date,
        'partialInvalidDate'   => $partial_invalid_date,
        'partialInvalidField'  => $partial_invalid_field,
        'invalidDate'          => $invalid_date,
        'invalidField'         => $invalid_field,
        'initiallyOpen'        => $initially_open,
        'registrationId'       => $registration_id,
        'registrationRaw'      => $registration_raw,
        'fieldsConfig'         => $fields,
    ];
}

$this->jsObject['cadastroUnicoSingle'] = $categories_data;

$this->import('
    mc-breadcrumb
    mc-container
    cadastro-unico--category-section
    cadastro-unico--category-fields
');
?>
<div class="main-app cadastro-unico single">
    <?php $this->applyTemplateHook('cadastro-unico-single', 'before') ?>

    <mc-breadcrumb></mc-breadcrumb>

    <?php $this->applyTemplateHook('cadastro-unico-single-header', 'before') ?>
    <header class="cadastro-unico__header">
        <?php $this->applyTemplateHook('cadastro-unico-single-header', 'begin') ?>
        <div class="cadastro-unico__header-inner">
            <h1 class="cadastro-unico__title">
                <?= i::__('Cadastro único') ?>
            </h1>
            <p class="cadastro-unico__subtitle">
                <?= i::__('Centralize seus documentos por categoria. Abra cada seção para gerenciar suas certidões, documentos obrigatórios e autodeclarações.') ?>
            </p>
        </div>
        <?php $this->applyTemplateHook('cadastro-unico-single-header', 'end') ?>
    </header>
    <?php $this->applyTemplateHook('cadastro-unico-single-header', 'after') ?>

    <?php $this->applyTemplateHook('cadastro-unico-single-content', 'before') ?>
    <mc-container>
        <main class="cadastro-unico__sections">
            <?php $this->applyTemplateHook('cadastro-unico-single-sections', 'begin') ?>

            <?php foreach ($categories_data as $slug => $data) : ?>
                <?php
                ?>
                <cadastro-unico--category-section
                    category-slug="<?= htmlspecialchars($data['slug'], ENT_QUOTES) ?>"
                    category-name="<?= htmlspecialchars($data['label'], ENT_QUOTES) ?>"
                    status="<?= htmlspecialchars($data['status'], ENT_QUOTES) ?>"
                    <?php if ($data['sentDate'] !== null) : ?>
                        :sent-date="<?= htmlspecialchars(json_encode($data['sentDate']), ENT_QUOTES) ?>"
                    <?php endif; ?>
                    <?php if ($data['partialInvalidDate'] !== null) : ?>
                        :partial-invalid-date="<?= htmlspecialchars(json_encode($data['partialInvalidDate']), ENT_QUOTES) ?>"
                    <?php endif; ?>
                    <?php if ($data['partialInvalidField'] !== null) : ?>
                        :partial-invalid-field="<?= htmlspecialchars(json_encode($data['partialInvalidField']), ENT_QUOTES) ?>"
                    <?php endif; ?>
                    <?php if ($data['invalidDate'] !== null) : ?>
                        :invalid-date="<?= htmlspecialchars(json_encode($data['invalidDate']), ENT_QUOTES) ?>"
                    <?php endif; ?>
                    <?php if ($data['invalidField'] !== null) : ?>
                        :invalid-field="<?= htmlspecialchars(json_encode($data['invalidField']), ENT_QUOTES) ?>"
                    <?php endif; ?>
                    <?php if ($data['initiallyOpen']) : ?>
                        :initially-open="true"
                    <?php endif; ?>
                >
                    <cadastro-unico--category-fields
                        category-slug="<?= htmlspecialchars($data['slug'], ENT_QUOTES) ?>"
                    ></cadastro-unico--category-fields>
                </cadastro-unico--category-section>
            <?php endforeach; ?>

            <?php $this->applyTemplateHook('cadastro-unico-single-sections', 'end') ?>
        </main>
    </mc-container>
    <?php $this->applyTemplateHook('cadastro-unico-single-content', 'after') ?>

    <?php $this->applyTemplateHook('cadastro-unico-single', 'after') ?>
</div>
