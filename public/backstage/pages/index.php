<?php
/**
 * Admin Dashboard — server-rendered widget grid.
 *
 * Layout resolution flow (server-side, single request):
 *   1. Pull container + tenant + session user out of $GLOBALS / $_SESSION.
 *   2. Resolve MinRole from session is_platform_admin + user_tenants.role.
 *   3. Ask GetUserLayout for the resolved layout (saved or role default,
 *      module-filtered, role-filtered).
 *   4. For each entry, look up the Widget instance in WidgetRegistry and
 *      call render() with the active Tenant + the looked-up User entity.
 *
 * Edit mode is gated by ?edit=1 — JS in dashboard-edit.js handles drag-drop
 * and posts to /api/backstage/dashboard/layout when the user makes changes.
 */

declare(strict_types=1);

use Daems\Application\Dashboard\GetUserLayout\GetUserLayout;
use Daems\Domain\Dashboard\MinRole;
use Daems\Domain\Dashboard\WidgetRegistry;
use Daems\Domain\Tenant\Tenant;
use Daems\Domain\Tenant\TenantModuleResolver;
use Daems\Domain\Tenant\UserTenantRepositoryInterface;
use Daems\Domain\Tenant\UserTenantRole;
use Daems\Domain\User\User;
use Daems\Domain\User\UserId;
use Daems\Domain\User\UserRepositoryInterface;
use Daems\Frontend\I18n;
use Daems\Infrastructure\Framework\Container\Container;

$pageTitle   = 'backstage.title.dashboard';
$activePage  = 'dashboard';
$breadcrumbs = [];

$editMode      = isset($_GET['edit']);
$layoutEntries = [];
$widgets       = [];
$role          = MinRole::Admin;

$__container = $GLOBALS['daems_backstage_container'] ?? null;
$__tenant    = $GLOBALS['daems_backstage_tenant']    ?? null;
$__session   = $_SESSION['user'] ?? null;

if (
    $__container instanceof Container
    && $__tenant instanceof Tenant
    && is_array($__session)
    && is_string($__session['id'] ?? null)
    && $__session['id'] !== ''
) {
    /** @var UserRepositoryInterface $__userRepo */
    $__userRepo  = $__container->make(UserRepositoryInterface::class);
    /** @var UserTenantRepositoryInterface $__userTenants */
    $__userTenants = $__container->make(UserTenantRepositoryInterface::class);
    /** @var TenantModuleResolver $__modules */
    $__modules   = $__container->make(TenantModuleResolver::class);
    /** @var WidgetRegistry $__registry */
    $__registry  = $__container->make(WidgetRegistry::class);
    /** @var GetUserLayout $__useCase */
    $__useCase   = $__container->make(GetUserLayout::class);

    $__userId = UserId::fromString($__session['id']);
    $__user   = $__userRepo->findById($__session['id']);

    if ($__user instanceof User) {
        $__tenantRole = $__userTenants->findRole($__userId, $__tenant->id);
        $role = match (true) {
            !empty($__session['is_platform_admin']) || $__user->isPlatformAdmin() => MinRole::Gsa,
            $__tenantRole === UserTenantRole::Admin                                => MinRole::Admin,
            $__tenantRole === UserTenantRole::Moderator                            => MinRole::Moderator,
            default                                                                => MinRole::Member,
        };

        $__output       = $__useCase->execute($__userId, $__tenant->id, $role, $__modules->enabledSlugsFor($__tenant->id));
        $layoutEntries  = $__output->layout();

        foreach ($layoutEntries as $__entry) {
            $widgets[$__entry->widgetId()] = $__registry->find($__entry->widgetId());
        }
    }
}

ob_start();
?>
<div class="page-header">
    <div>
        <h1 class="page-header__title"><?= I18n::e('backstage.dashboard.title') ?></h1>
    </div>
    <div class="page-header__actions">
        <?php if ($editMode): ?>
            <a href="?" class="btn btn--ghost"><?= I18n::e('backstage.dashboard.edit_cancel') ?></a>
            <button type="button" class="btn btn--primary" id="dashboard-save-done">
                <?= I18n::e('backstage.dashboard.edit_done') ?>
            </button>
        <?php else: ?>
            <a href="?edit=1" class="btn btn--ghost" id="dashboard-edit-toggle">
                <i class="bi bi-pencil"></i>
                <?= I18n::e('backstage.dashboard.edit_mode') ?>
            </a>
        <?php endif; ?>
    </div>
</div>

<div class="dashboard-grid <?= $editMode ? 'is-editing' : '' ?>" id="dashboard-grid">
    <?php foreach ($layoutEntries as $__entry):
        $__w = $widgets[$__entry->widgetId()] ?? null;
        if ($__w === null || !isset($__user) || !($__user instanceof User) || !isset($__tenant) || !($__tenant instanceof Tenant)) {
            continue;
        }
    ?>
    <div class="dashboard-cell"
         style="grid-column: span <?= $__entry->span()->value() ?>;"
         data-widget-id="<?= htmlspecialchars($__entry->widgetId(), ENT_QUOTES, 'UTF-8') ?>"
         data-span="<?= $__entry->span()->value() ?>">
        <?php if ($editMode): ?>
        <button type="button" class="dashboard-cell__handle"
                title="<?= I18n::e('backstage.dashboard.drag_handle') ?>"
                aria-label="<?= I18n::e('backstage.dashboard.drag_handle') ?>">⋮⋮</button>
        <button type="button" class="dashboard-cell__remove"
                data-widget-id="<?= htmlspecialchars($__entry->widgetId(), ENT_QUOTES, 'UTF-8') ?>"
                title="<?= I18n::e('backstage.dashboard.remove_widget') ?>"
                aria-label="<?= I18n::e('backstage.dashboard.remove_widget') ?>">✕</button>
        <?php endif; ?>
        <?= $__w->render($__tenant->id, $__user) ?>
    </div>
    <?php endforeach; ?>

    <?php if ($editMode): ?>
    <button type="button" class="dashboard-add-widget" id="dashboard-add-widget"
            style="grid-column: span 4;">
        <?= I18n::e('backstage.dashboard.add_widget') ?>
    </button>
    <?php endif; ?>
</div>

<?php if ($editMode): ?>
<div class="dashboard-edit-footer">
    <button type="button" class="btn btn--ghost btn--danger" id="dashboard-reset">
        <?= I18n::e('backstage.dashboard.edit_reset') ?>
    </button>
</div>
<?php endif; ?>

<script>
window.DaemsDashboard = {
    editMode: <?= $editMode ? 'true' : 'false' ?>,
    layoutEndpoint: '/api/backstage/dashboard/layout',
    catalogEndpoint: '/api/backstage/dashboard/catalog',
    i18n: {
        resetConfirm:  <?= json_encode(I18n::t('backstage.dashboard.edit_reset_confirm')) ?>,
        catalogTitle:  <?= json_encode(I18n::t('backstage.dashboard.catalog.title')) ?>,
        catalogSearch: <?= json_encode(I18n::t('backstage.dashboard.catalog.search')) ?>,
        catalogEmpty:  <?= json_encode(I18n::t('backstage.dashboard.catalog.empty')) ?>,
        inLayout:      <?= json_encode(I18n::t('backstage.dashboard.catalog.in_layout')) ?>,
        saveFailed:    <?= json_encode(I18n::t('backstage.dashboard.error.save_failed')) ?>,
        addWidget:     <?= json_encode(I18n::t('backstage.dashboard.add_widget')) ?>,
        removeWidget:  <?= json_encode(I18n::t('backstage.dashboard.remove_widget')) ?>,
        categoryAll:      <?= json_encode(I18n::t('backstage.dashboard.catalog.category.all')) ?>,
        categoryNumbers:  <?= json_encode(I18n::t('backstage.dashboard.catalog.category.numbers')) ?>,
        categoryLists:    <?= json_encode(I18n::t('backstage.dashboard.catalog.category.lists')) ?>,
        categoryCharts:   <?= json_encode(I18n::t('backstage.dashboard.catalog.category.charts')) ?>,
        categoryActions:  <?= json_encode(I18n::t('backstage.dashboard.catalog.category.actions')) ?>,
        categoryActivity: <?= json_encode(I18n::t('backstage.dashboard.catalog.category.activity')) ?>,
        categoryPlatform: <?= json_encode(I18n::t('backstage.dashboard.catalog.category.platform')) ?>,
    },
};
</script>
<?php
$pageContent = ob_get_clean();
require __DIR__ . '/layout.php';
