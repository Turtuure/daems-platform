<?php

declare(strict_types=1);

use Daems\Infrastructure\Adapter\Api\Controller\AdminController;
use Daems\Infrastructure\Adapter\Api\Controller\AuthController;
use Daems\Infrastructure\Adapter\Api\Controller\Backstage\MembershipSubTiersController;
use Daems\Infrastructure\Adapter\Api\Controller\DashboardController;
use Daems\Infrastructure\Adapter\Api\Controller\UserController;
use Daems\Infrastructure\Framework\Container\Container;
use Daems\Infrastructure\Framework\Http\Middleware\AuthMiddleware;
use Daems\Infrastructure\Framework\Http\Middleware\LocaleMiddleware;
use Daems\Infrastructure\Framework\Http\Middleware\RateLimitLoginMiddleware;
use Daems\Infrastructure\Framework\Http\Middleware\TenantContextMiddleware;
use Daems\Infrastructure\Framework\Http\Request;
use Daems\Infrastructure\Framework\Http\Response;
use Daems\Infrastructure\Framework\Http\Router;

return static function (Router $router, Container $container): void {

    $router->get('/api/v1/status', static function (): Response {
        return Response::json(['data' => ['status' => 'ok', 'version' => '1.0.0']]);
    }, [TenantContextMiddleware::class]);

    // Admin — GSA only (enforced at application layer)
    $router->get('/api/v1/admin/stats', static function (Request $req) use ($container): Response {
        return $container->make(AdminController::class)->stats($req);
    }, [TenantContextMiddleware::class, AuthMiddleware::class]);

    // Backstage aliases (preferred naming in frontend)
    $router->get('/api/v1/backstage/stats', static function (Request $req) use ($container): Response {
        return $container->make(AdminController::class)->stats($req);
    }, [TenantContextMiddleware::class, AuthMiddleware::class]);

    $router->get('/api/v1/admin/member-growth', static function (Request $req) use ($container): Response {
        return $container->make(AdminController::class)->memberGrowth($req);
    }, [TenantContextMiddleware::class, AuthMiddleware::class]);

    $router->get('/api/v1/backstage/member-growth', static function (Request $req) use ($container): Response {
        return $container->make(AdminController::class)->memberGrowth($req);
    }, [TenantContextMiddleware::class, AuthMiddleware::class]);

    // Backstage — Dashboard (layout + widget catalog)
    $router->get('/api/v1/backstage/dashboard/layout', static function (Request $req) use ($container): Response {
        return $container->make(DashboardController::class)->getLayout($req);
    }, [TenantContextMiddleware::class, AuthMiddleware::class]);

    $router->put('/api/v1/backstage/dashboard/layout', static function (Request $req) use ($container): Response {
        return $container->make(DashboardController::class)->putLayout($req);
    }, [TenantContextMiddleware::class, AuthMiddleware::class]);

    $router->delete('/api/v1/backstage/dashboard/layout', static function (Request $req) use ($container): Response {
        return $container->make(DashboardController::class)->deleteLayout($req);
    }, [TenantContextMiddleware::class, AuthMiddleware::class]);

    $router->get('/api/v1/backstage/dashboard/catalog', static function (Request $req) use ($container): Response {
        return $container->make(DashboardController::class)->getCatalog($req);
    }, [TenantContextMiddleware::class, AuthMiddleware::class]);

    // Users — all protected
    $router->get('/api/v1/users/{id}', static function (Request $req, array $params) use ($container): Response {
        return $container->make(UserController::class)->profile($req, $params);
    }, [TenantContextMiddleware::class, AuthMiddleware::class]);

    $router->post('/api/v1/users/{id}', static function (Request $req, array $params) use ($container): Response {
        return $container->make(UserController::class)->update($req, $params);
    }, [TenantContextMiddleware::class, AuthMiddleware::class]);

    $router->post('/api/v1/users/{id}/password', static function (Request $req, array $params) use ($container): Response {
        return $container->make(UserController::class)->changePasswordAction($req, $params);
    }, [TenantContextMiddleware::class, AuthMiddleware::class]);

    $router->get('/api/v1/users/{id}/activity', static function (Request $req, array $params) use ($container): Response {
        return $container->make(UserController::class)->activity($req, $params);
    }, [TenantContextMiddleware::class, AuthMiddleware::class]);

    $router->post('/api/v1/users/{id}/anonymise', static function (Request $req, array $params) use ($container): Response {
        return $container->make(UserController::class)->anonymise($req, $params);
    }, [TenantContextMiddleware::class, AuthMiddleware::class]);

    // Auth
    $router->post('/api/v1/auth/login', static function (Request $req) use ($container): Response {
        return $container->make(AuthController::class)->login($req);
    }, [TenantContextMiddleware::class, RateLimitLoginMiddleware::class]);

    $router->post('/api/v1/auth/register', static function (Request $req) use ($container): Response {
        return $container->make(AuthController::class)->register($req);
    }, [TenantContextMiddleware::class]);

    $router->post('/api/v1/auth/logout', static function (Request $req) use ($container): Response {
        return $container->make(AuthController::class)->logout($req);
    }, [TenantContextMiddleware::class, AuthMiddleware::class]);

    $router->get('/api/v1/auth/me', static function (Request $req) use ($container): Response {
        return $container->make(AuthController::class)->me($req);
    }, [TenantContextMiddleware::class, AuthMiddleware::class]);


    // Backstage — Notifications
    $router->get('/api/v1/backstage/notifications/stats', static function (Request $req) use ($container): Response {
        return $container->make(\Daems\Infrastructure\Adapter\Api\Controller\BackstageController::class)->statsNotifications($req);
    }, [TenantContextMiddleware::class, AuthMiddleware::class]);

    $router->get('/api/v1/backstage/proposals', static function (Request $req) use ($container): Response {
        return $container->make(\Daems\Infrastructure\Adapter\Api\Controller\BackstageController::class)->listProposalsAdmin($req);
    }, [TenantContextMiddleware::class, AuthMiddleware::class]);

    // Backstage — tenant settings (currently only member_number_prefix)
    $router->post('/api/v1/backstage/tenant/settings', static function (Request $req) use ($container): Response {
        return $container->make(\Daems\Infrastructure\Adapter\Api\Controller\BackstageController::class)->updateTenantSettings($req);
    }, [TenantContextMiddleware::class, AuthMiddleware::class]);

    // Backstage — membership sub-tier (honor) catalog (per-tenant). Admin/GSA only.
    $router->get('/api/v1/backstage/tenant-settings/membership-subtiers', static function (Request $req) use ($container): Response {
        return $container->make(
            \Daems\Infrastructure\Adapter\Api\Controller\Backstage\MembershipSubTiersController::class
        )->index($req);
    }, [TenantContextMiddleware::class, AuthMiddleware::class]);

    // Me — public-profile privacy toggle (public_avatar_visible)
    $router->post('/api/v1/me/privacy', static function (Request $req) use ($container): Response {
        return $container->make(UserController::class)->updateMyPrivacy($req);
    }, [TenantContextMiddleware::class, AuthMiddleware::class]);

    // Me — TimePicker preference override ('12' | '24' | null)
    $router->post('/api/v1/me/time-format', static function (Request $req) use ($container): Response {
        return $container->make(UserController::class)->updateMyTimeFormat($req);
    }, [TenantContextMiddleware::class, AuthMiddleware::class]);

    // Public search (no auth). Locale middleware for i18n fallback resolution.
    $router->get('/api/v1/search', static function (Request $req) use ($container): Response {
        return $container->make(\Daems\Infrastructure\Adapter\Api\Controller\SearchController::class)->public($req);
    }, [TenantContextMiddleware::class, LocaleMiddleware::class]);

    // Backstage search (auth required; members domain gated inside controller).
    $router->get('/api/v1/backstage/search', static function (Request $req) use ($container): Response {
        return $container->make(\Daems\Infrastructure\Adapter\Api\Controller\SearchController::class)->backstage($req);
    }, [TenantContextMiddleware::class, LocaleMiddleware::class, AuthMiddleware::class]);

    $router->post('/api/v1/auth/invites/redeem', static function (Request $req) use ($container): Response {
        return $container->make(AuthController::class)->redeemInvite($req);
    }, [TenantContextMiddleware::class]);

    // Backstage — Platform-scope (GSA) tenant management (Wave F)
    // Platform-admin gate is enforced inside each controller method.
    $router->get('/api/v1/backstage/platform/tenants', static function (Request $req) use ($container): Response {
        return $container->make(\Daems\Infrastructure\Adapter\Api\Controller\Backstage\Platform\TenantsController::class)->list($req);
    }, [TenantContextMiddleware::class, AuthMiddleware::class]);

    $router->post('/api/v1/backstage/platform/tenants', static function (Request $req) use ($container): Response {
        return $container->make(\Daems\Infrastructure\Adapter\Api\Controller\Backstage\Platform\TenantsController::class)->create($req);
    }, [TenantContextMiddleware::class, AuthMiddleware::class]);

    $router->get('/api/v1/backstage/platform/tenants/{id}', static function (Request $req, array $params) use ($container): Response {
        return $container->make(\Daems\Infrastructure\Adapter\Api\Controller\Backstage\Platform\TenantsController::class)->get($req, $params);
    }, [TenantContextMiddleware::class, AuthMiddleware::class]);

    $router->patch('/api/v1/backstage/platform/tenants/{id}', static function (Request $req, array $params) use ($container): Response {
        return $container->make(\Daems\Infrastructure\Adapter\Api\Controller\Backstage\Platform\TenantsController::class)->patch($req, $params);
    }, [TenantContextMiddleware::class, AuthMiddleware::class]);

    $router->post('/api/v1/backstage/platform/tenants/{id}/suspend', static function (Request $req, array $params) use ($container): Response {
        return $container->make(\Daems\Infrastructure\Adapter\Api\Controller\Backstage\Platform\TenantsController::class)->suspend($req, $params);
    }, [TenantContextMiddleware::class, AuthMiddleware::class]);

    $router->post('/api/v1/backstage/platform/tenants/{id}/reactivate', static function (Request $req, array $params) use ($container): Response {
        return $container->make(\Daems\Infrastructure\Adapter\Api\Controller\Backstage\Platform\TenantsController::class)->reactivate($req, $params);
    }, [TenantContextMiddleware::class, AuthMiddleware::class]);

    // Tenant domains (GSA only)
    $router->get('/api/v1/backstage/platform/tenants/{id}/domains', static function (Request $req, array $params) use ($container): Response {
        return $container->make(\Daems\Infrastructure\Adapter\Api\Controller\Backstage\Platform\TenantDomainsController::class)->list($req, $params);
    }, [TenantContextMiddleware::class, AuthMiddleware::class]);

    $router->post('/api/v1/backstage/platform/tenants/{id}/domains', static function (Request $req, array $params) use ($container): Response {
        return $container->make(\Daems\Infrastructure\Adapter\Api\Controller\Backstage\Platform\TenantDomainsController::class)->add($req, $params);
    }, [TenantContextMiddleware::class, AuthMiddleware::class]);

    $router->patch('/api/v1/backstage/platform/tenants/{id}/domains/{did}', static function (Request $req, array $params) use ($container): Response {
        return $container->make(\Daems\Infrastructure\Adapter\Api\Controller\Backstage\Platform\TenantDomainsController::class)->update($req, $params);
    }, [TenantContextMiddleware::class, AuthMiddleware::class]);

    $router->delete('/api/v1/backstage/platform/tenants/{id}/domains/{did}', static function (Request $req, array $params) use ($container): Response {
        return $container->make(\Daems\Infrastructure\Adapter\Api\Controller\Backstage\Platform\TenantDomainsController::class)->remove($req, $params);
    }, [TenantContextMiddleware::class, AuthMiddleware::class]);

    // Tenant admins (GSA only)
    $router->get('/api/v1/backstage/platform/tenants/{id}/admins', static function (Request $req, array $params) use ($container): Response {
        return $container->make(\Daems\Infrastructure\Adapter\Api\Controller\Backstage\Platform\TenantAdminsController::class)->list($req, $params);
    }, [TenantContextMiddleware::class, AuthMiddleware::class]);

    $router->post('/api/v1/backstage/platform/tenants/{id}/admins', static function (Request $req, array $params) use ($container): Response {
        return $container->make(\Daems\Infrastructure\Adapter\Api\Controller\Backstage\Platform\TenantAdminsController::class)->grant($req, $params);
    }, [TenantContextMiddleware::class, AuthMiddleware::class]);

    $router->delete('/api/v1/backstage/platform/tenants/{id}/admins/{uid}', static function (Request $req, array $params) use ($container): Response {
        return $container->make(\Daems\Infrastructure\Adapter\Api\Controller\Backstage\Platform\TenantAdminsController::class)->revoke($req, $params);
    }, [TenantContextMiddleware::class, AuthMiddleware::class]);

    // Tenant-modules availability (GSA only)
    $router->get('/api/v1/backstage/platform/tenants/{id}/modules', static function (Request $req, array $params) use ($container): Response {
        return $container->make(\Daems\Infrastructure\Adapter\Api\Controller\Backstage\Platform\PlatformTenantModulesController::class)->list($req, $params);
    }, [TenantContextMiddleware::class, AuthMiddleware::class]);

    $router->post('/api/v1/backstage/platform/tenants/{id}/modules/{slug}/availability', static function (Request $req, array $params) use ($container): Response {
        return $container->make(\Daems\Infrastructure\Adapter\Api\Controller\Backstage\Platform\PlatformTenantModulesController::class)->availability($req, $params);
    }, [TenantContextMiddleware::class, AuthMiddleware::class]);

    // Tenant-self modules (admin in current tenant or platform admin)
    // Tenant id resolved from request's `tenant` attribute set by TenantContextMiddleware.
    $router->get('/api/v1/backstage/tenant/modules', static function (Request $req) use ($container): Response {
        return $container->make(\Daems\Infrastructure\Adapter\Api\Controller\Backstage\Tenant\TenantSelfModulesController::class)->list($req);
    }, [TenantContextMiddleware::class, AuthMiddleware::class]);

    $router->post('/api/v1/backstage/tenant/modules/{slug}/state', static function (Request $req, array $params) use ($container): Response {
        return $container->make(\Daems\Infrastructure\Adapter\Api\Controller\Backstage\Tenant\TenantSelfModulesController::class)->state($req, $params);
    }, [TenantContextMiddleware::class, AuthMiddleware::class]);

    // Module routes — invoke each discovered module's routes.php.
    $moduleRegistry = $container->make(\Daems\Infrastructure\Module\ModuleRegistry::class);
    $moduleRegistry->registerRoutes($router, $container);
};
