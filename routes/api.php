<?php

declare(strict_types=1);

use Daems\Infrastructure\Adapter\Api\Controller\AdminController;
use Daems\Infrastructure\Adapter\Api\Controller\ApplicationController;
use Daems\Infrastructure\Adapter\Api\Controller\AuthController;
use Daems\Infrastructure\Adapter\Api\Controller\EventController;
use Daems\Infrastructure\Adapter\Api\Controller\ForumController;
use Daems\Infrastructure\Adapter\Api\Controller\InsightController;
use Daems\Infrastructure\Adapter\Api\Controller\ProjectController;
use Daems\Infrastructure\Adapter\Api\Controller\UserController;
use Daems\Infrastructure\Framework\Container\Container;
use Daems\Infrastructure\Framework\Http\Middleware\AuthMiddleware;
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

    // Events — public reads
    $router->get('/api/v1/events', static function (Request $req) use ($container): Response {
        return $container->make(EventController::class)->index($req);
    }, [TenantContextMiddleware::class]);

    $router->get('/api/v1/events/{slug}', static function (Request $req, array $params) use ($container): Response {
        return $container->make(EventController::class)->show($req, $params);
    }, [TenantContextMiddleware::class]);

    // Insights — public reads
    $router->get('/api/v1/insights', static function (Request $req) use ($container): Response {
        return $container->make(InsightController::class)->index($req);
    }, [TenantContextMiddleware::class]);

    $router->get('/api/v1/insights/{slug}', static function (Request $req, array $params) use ($container): Response {
        return $container->make(InsightController::class)->show($req, $params);
    }, [TenantContextMiddleware::class]);

    // Projects — public reads
    $router->get('/api/v1/projects', static function (Request $req) use ($container): Response {
        return $container->make(ProjectController::class)->index($req);
    }, [TenantContextMiddleware::class]);

    $router->get('/api/v1/projects/{slug}', static function (Request $req, array $params) use ($container): Response {
        return $container->make(ProjectController::class)->show($req, $params);
    }, [TenantContextMiddleware::class]);

    // Projects — protected mutations
    $router->post('/api/v1/projects', static function (Request $req) use ($container): Response {
        return $container->make(ProjectController::class)->create($req);
    }, [TenantContextMiddleware::class, AuthMiddleware::class]);

    $router->post('/api/v1/projects/{slug}', static function (Request $req, array $params) use ($container): Response {
        return $container->make(ProjectController::class)->update($req, $params);
    }, [TenantContextMiddleware::class, AuthMiddleware::class]);

    $router->post('/api/v1/projects/{slug}/archive', static function (Request $req, array $params) use ($container): Response {
        return $container->make(ProjectController::class)->archive($req, $params);
    }, [TenantContextMiddleware::class, AuthMiddleware::class]);

    $router->post('/api/v1/projects/{slug}/join', static function (Request $req, array $params) use ($container): Response {
        return $container->make(ProjectController::class)->join($req, $params);
    }, [TenantContextMiddleware::class, AuthMiddleware::class]);

    $router->post('/api/v1/projects/{slug}/leave', static function (Request $req, array $params) use ($container): Response {
        return $container->make(ProjectController::class)->leave($req, $params);
    }, [TenantContextMiddleware::class, AuthMiddleware::class]);

    $router->post('/api/v1/projects/{slug}/comments', static function (Request $req, array $params) use ($container): Response {
        return $container->make(ProjectController::class)->addComment($req, $params);
    }, [TenantContextMiddleware::class, AuthMiddleware::class]);

    $router->post('/api/v1/project-comments/{id}/like', static function (Request $req, array $params) use ($container): Response {
        return $container->make(ProjectController::class)->likeComment($req, $params);
    }, [TenantContextMiddleware::class, AuthMiddleware::class]);

    $router->post('/api/v1/projects/{slug}/updates', static function (Request $req, array $params) use ($container): Response {
        return $container->make(ProjectController::class)->addUpdate($req, $params);
    }, [TenantContextMiddleware::class, AuthMiddleware::class]);

    $router->post('/api/v1/project-proposals', static function (Request $req) use ($container): Response {
        return $container->make(ProjectController::class)->propose($req);
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

    // Events — protected mutations
    $router->post('/api/v1/events/{slug}/register', static function (Request $req, array $params) use ($container): Response {
        return $container->make(EventController::class)->register($req, $params);
    }, [TenantContextMiddleware::class, AuthMiddleware::class]);

    $router->post('/api/v1/events/{slug}/unregister', static function (Request $req, array $params) use ($container): Response {
        return $container->make(EventController::class)->unregister($req, $params);
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

    // Forum — public reads
    $router->get('/api/v1/forum/categories', static function (Request $req) use ($container): Response {
        return $container->make(ForumController::class)->index($req);
    }, [TenantContextMiddleware::class]);

    $router->get('/api/v1/forum/categories/{slug}', static function (Request $req, array $params) use ($container): Response {
        return $container->make(ForumController::class)->category($req, $params);
    }, [TenantContextMiddleware::class]);

    $router->get('/api/v1/forum/topics/{slug}', static function (Request $req, array $params) use ($container): Response {
        return $container->make(ForumController::class)->thread($req, $params);
    }, [TenantContextMiddleware::class]);

    // Forum — protected writes
    $router->post('/api/v1/forum/categories/{slug}/topics', static function (Request $req, array $params) use ($container): Response {
        return $container->make(ForumController::class)->createTopic($req, $params);
    }, [TenantContextMiddleware::class, AuthMiddleware::class]);

    $router->post('/api/v1/forum/topics/{slug}/posts', static function (Request $req, array $params) use ($container): Response {
        return $container->make(ForumController::class)->createPost($req, $params);
    }, [TenantContextMiddleware::class, AuthMiddleware::class]);

    $router->post('/api/v1/forum/posts/{id}/like', static function (Request $req, array $params) use ($container): Response {
        return $container->make(ForumController::class)->likePost($req, $params);
    }, [TenantContextMiddleware::class, AuthMiddleware::class]);

    // Public — view counter doesn't need auth
    $router->post('/api/v1/forum/topics/{slug}/view', static function (Request $req, array $params) use ($container): Response {
        return $container->make(ForumController::class)->incrementView($req, $params);
    }, [TenantContextMiddleware::class]);

    // Backstage — tenant-admin / GSA
    $router->get('/api/v1/backstage/applications/pending', static function (Request $req) use ($container): Response {
        return $container->make(\Daems\Infrastructure\Adapter\Api\Controller\BackstageController::class)->pendingApplications($req);
    }, [TenantContextMiddleware::class, AuthMiddleware::class]);

    $router->post('/api/v1/backstage/applications/{type}/{id}/decision', static function (Request $req, array $params) use ($container): Response {
        return $container->make(\Daems\Infrastructure\Adapter\Api\Controller\BackstageController::class)->decideApplication($req, $params);
    }, [TenantContextMiddleware::class, AuthMiddleware::class]);

    $router->get('/api/v1/backstage/members', static function (Request $req) use ($container): Response {
        return $container->make(\Daems\Infrastructure\Adapter\Api\Controller\BackstageController::class)->members($req);
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

    $router->post('/api/v1/backstage/applications/{type}/{id}/dismiss', static function (Request $req, array $params) use ($container): Response {
        return $container->make(\Daems\Infrastructure\Adapter\Api\Controller\BackstageController::class)->dismissApplication($req, $params);
    }, [TenantContextMiddleware::class, AuthMiddleware::class]);

    // Backstage — Events (admin)
    $router->get('/api/v1/backstage/events', static function (Request $req) use ($container): Response {
        return $container->make(\Daems\Infrastructure\Adapter\Api\Controller\BackstageController::class)->listEvents($req);
    }, [TenantContextMiddleware::class, AuthMiddleware::class]);

    $router->post('/api/v1/backstage/events', static function (Request $req) use ($container): Response {
        return $container->make(\Daems\Infrastructure\Adapter\Api\Controller\BackstageController::class)->createEvent($req);
    }, [TenantContextMiddleware::class, AuthMiddleware::class]);

    $router->post('/api/v1/backstage/events/{id}', static function (Request $req, array $params) use ($container): Response {
        return $container->make(\Daems\Infrastructure\Adapter\Api\Controller\BackstageController::class)->updateEvent($req, $params);
    }, [TenantContextMiddleware::class, AuthMiddleware::class]);

    $router->post('/api/v1/backstage/events/{id}/publish', static function (Request $req, array $params) use ($container): Response {
        return $container->make(\Daems\Infrastructure\Adapter\Api\Controller\BackstageController::class)->publishEvent($req, $params);
    }, [TenantContextMiddleware::class, AuthMiddleware::class]);

    $router->post('/api/v1/backstage/events/{id}/archive', static function (Request $req, array $params) use ($container): Response {
        return $container->make(\Daems\Infrastructure\Adapter\Api\Controller\BackstageController::class)->archiveEvent($req, $params);
    }, [TenantContextMiddleware::class, AuthMiddleware::class]);

    $router->get('/api/v1/backstage/events/{id}/registrations', static function (Request $req, array $params) use ($container): Response {
        return $container->make(\Daems\Infrastructure\Adapter\Api\Controller\BackstageController::class)->listEventRegistrations($req, $params);
    }, [TenantContextMiddleware::class, AuthMiddleware::class]);

    $router->post('/api/v1/backstage/events/{id}/registrations/{user_id}/remove', static function (Request $req, array $params) use ($container): Response {
        return $container->make(\Daems\Infrastructure\Adapter\Api\Controller\BackstageController::class)->removeEventRegistration($req, $params);
    }, [TenantContextMiddleware::class, AuthMiddleware::class]);

    $router->post('/api/v1/backstage/events/{id}/images', static function (Request $req, array $params) use ($container): Response {
        return $container->make(\Daems\Infrastructure\Adapter\Api\Controller\MediaController::class)->uploadEventImage($req, $params);
    }, [TenantContextMiddleware::class, AuthMiddleware::class]);

    $router->post('/api/v1/backstage/events/{id}/images/delete', static function (Request $req, array $params) use ($container): Response {
        return $container->make(\Daems\Infrastructure\Adapter\Api\Controller\MediaController::class)->deleteEventImage($req, $params);
    }, [TenantContextMiddleware::class, AuthMiddleware::class]);

    // Backstage — Projects admin
    $router->get('/api/v1/backstage/projects', static function (Request $req) use ($container): Response {
        return $container->make(\Daems\Infrastructure\Adapter\Api\Controller\BackstageController::class)->listProjectsAdmin($req);
    }, [TenantContextMiddleware::class, AuthMiddleware::class]);

    $router->post('/api/v1/backstage/projects', static function (Request $req) use ($container): Response {
        return $container->make(\Daems\Infrastructure\Adapter\Api\Controller\BackstageController::class)->createProjectAdmin($req);
    }, [TenantContextMiddleware::class, AuthMiddleware::class]);

    $router->post('/api/v1/backstage/projects/{id}', static function (Request $req, array $params) use ($container): Response {
        return $container->make(\Daems\Infrastructure\Adapter\Api\Controller\BackstageController::class)->updateProjectAdmin($req, $params);
    }, [TenantContextMiddleware::class, AuthMiddleware::class]);

    $router->post('/api/v1/backstage/projects/{id}/status', static function (Request $req, array $params) use ($container): Response {
        return $container->make(\Daems\Infrastructure\Adapter\Api\Controller\BackstageController::class)->changeProjectStatus($req, $params);
    }, [TenantContextMiddleware::class, AuthMiddleware::class]);

    $router->post('/api/v1/backstage/projects/{id}/featured', static function (Request $req, array $params) use ($container): Response {
        return $container->make(\Daems\Infrastructure\Adapter\Api\Controller\BackstageController::class)->setProjectFeatured($req, $params);
    }, [TenantContextMiddleware::class, AuthMiddleware::class]);

    $router->get('/api/v1/backstage/proposals', static function (Request $req) use ($container): Response {
        return $container->make(\Daems\Infrastructure\Adapter\Api\Controller\BackstageController::class)->listProposalsAdmin($req);
    }, [TenantContextMiddleware::class, AuthMiddleware::class]);

    $router->post('/api/v1/backstage/proposals/{id}/approve', static function (Request $req, array $params) use ($container): Response {
        return $container->make(\Daems\Infrastructure\Adapter\Api\Controller\BackstageController::class)->approveProposal($req, $params);
    }, [TenantContextMiddleware::class, AuthMiddleware::class]);

    $router->post('/api/v1/backstage/proposals/{id}/reject', static function (Request $req, array $params) use ($container): Response {
        return $container->make(\Daems\Infrastructure\Adapter\Api\Controller\BackstageController::class)->rejectProposal($req, $params);
    }, [TenantContextMiddleware::class, AuthMiddleware::class]);

    $router->get('/api/v1/backstage/comments/recent', static function (Request $req) use ($container): Response {
        return $container->make(\Daems\Infrastructure\Adapter\Api\Controller\BackstageController::class)->listProjectComments($req);
    }, [TenantContextMiddleware::class, AuthMiddleware::class]);

    $router->post('/api/v1/backstage/projects/{id}/comments/{comment_id}/delete', static function (Request $req, array $params) use ($container): Response {
        return $container->make(\Daems\Infrastructure\Adapter\Api\Controller\BackstageController::class)->deleteProjectComment($req, $params);
    }, [TenantContextMiddleware::class, AuthMiddleware::class]);

    $router->post('/api/v1/auth/invites/redeem', static function (Request $req) use ($container): Response {
        return $container->make(AuthController::class)->redeemInvite($req);
    }, [TenantContextMiddleware::class]);
};
