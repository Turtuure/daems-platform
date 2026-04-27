<?php

declare(strict_types=1);

use Daems\Infrastructure\Adapter\Api\Controller\AdminController;
use Daems\Infrastructure\Adapter\Api\Controller\ApplicationController;
use Daems\Infrastructure\Adapter\Api\Controller\AuthController;
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

    // Applications — public (anyone can apply for membership or supporter status)
    $router->post('/api/v1/applications/member', static function (Request $req) use ($container): Response {
        return $container->make(ApplicationController::class)->member($req);
    }, [TenantContextMiddleware::class]);

    $router->post('/api/v1/applications/supporter', static function (Request $req) use ($container): Response {
        return $container->make(ApplicationController::class)->supporter($req);
    }, [TenantContextMiddleware::class]);

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


    // Backstage — tenant-admin / GSA
    $router->get('/api/v1/backstage/applications/pending', static function (Request $req) use ($container): Response {
        return $container->make(\Daems\Infrastructure\Adapter\Api\Controller\BackstageController::class)->pendingApplications($req);
    }, [TenantContextMiddleware::class, AuthMiddleware::class]);

    $router->get('/api/v1/backstage/applications/decided', static function (Request $req) use ($container): Response {
        return $container->make(\Daems\Infrastructure\Adapter\Api\Controller\BackstageController::class)->decidedApplications($req);
    }, [TenantContextMiddleware::class, AuthMiddleware::class]);

    $router->post('/api/v1/backstage/applications/{type}/{id}/decision', static function (Request $req, array $params) use ($container): Response {
        return $container->make(\Daems\Infrastructure\Adapter\Api\Controller\BackstageController::class)->decideApplication($req, $params);
    }, [TenantContextMiddleware::class, AuthMiddleware::class]);

    $router->get('/api/v1/backstage/members', static function (Request $req) use ($container): Response {
        return $container->make(\Daems\Infrastructure\Adapter\Api\Controller\BackstageController::class)->members($req);
    }, [TenantContextMiddleware::class, AuthMiddleware::class]);

    $router->get('/api/v1/backstage/members/stats', static function (Request $req) use ($container): Response {
        return $container->make(\Daems\Infrastructure\Adapter\Api\Controller\BackstageController::class)->statsMembers($req);
    }, [TenantContextMiddleware::class, AuthMiddleware::class]);

    $router->post('/api/v1/backstage/members/{id}/status', static function (Request $req, array $params) use ($container): Response {
        return $container->make(\Daems\Infrastructure\Adapter\Api\Controller\BackstageController::class)->changeMemberStatus($req, $params);
    }, [TenantContextMiddleware::class, AuthMiddleware::class]);

    $router->get('/api/v1/backstage/members/{id}/audit', static function (Request $req, array $params) use ($container): Response {
        return $container->make(\Daems\Infrastructure\Adapter\Api\Controller\BackstageController::class)->memberAudit($req, $params);
    }, [TenantContextMiddleware::class, AuthMiddleware::class]);

    $router->get('/api/v1/backstage/applications/pending-count', static function (Request $req) use ($container): Response {
        return $container->make(\Daems\Infrastructure\Adapter\Api\Controller\BackstageController::class)->listPendingForAdmin($req);
    }, [TenantContextMiddleware::class, AuthMiddleware::class]);

    $router->get('/api/v1/backstage/applications/stats', static function (Request $req) use ($container): Response {
        return $container->make(\Daems\Infrastructure\Adapter\Api\Controller\BackstageController::class)->statsApplications($req);
    }, [TenantContextMiddleware::class, AuthMiddleware::class]);

    $router->post('/api/v1/backstage/applications/{type}/{id}/dismiss', static function (Request $req, array $params) use ($container): Response {
        return $container->make(\Daems\Infrastructure\Adapter\Api\Controller\BackstageController::class)->dismissApplication($req, $params);
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

    // Me — public-profile privacy toggle (public_avatar_visible)
    $router->post('/api/v1/me/privacy', static function (Request $req) use ($container): Response {
        return $container->make(UserController::class)->updateMyPrivacy($req);
    }, [TenantContextMiddleware::class, AuthMiddleware::class]);

    // Me — TimePicker preference override ('12' | '24' | null)
    $router->post('/api/v1/me/time-format', static function (Request $req) use ($container): Response {
        return $container->make(UserController::class)->updateMyTimeFormat($req);
    }, [TenantContextMiddleware::class, AuthMiddleware::class]);

    // Public — member verification (NO auth). {id} is users.id (UUIDv7).
    $router->get('/api/v1/members/{id}', static function (Request $req, array $params) use ($container): Response {
        return $container->make(\Daems\Infrastructure\Adapter\Api\Controller\MemberController::class)->getPublicProfile($req, $params);
    }, []);

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

    // Module routes — invoke each discovered module's routes.php.
    $moduleRegistry = $container->make(\Daems\Infrastructure\Module\ModuleRegistry::class);
    $moduleRegistry->registerRoutes($router, $container);
};
