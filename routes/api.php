<?php

declare(strict_types=1);

use Daems\Infrastructure\Adapter\Api\Controller\EventController;
use Daems\Infrastructure\Adapter\Api\Controller\ForumController;
use Daems\Infrastructure\Adapter\Api\Controller\InsightController;
use Daems\Infrastructure\Adapter\Api\Controller\ApplicationController;
use Daems\Infrastructure\Adapter\Api\Controller\AuthController;
use Daems\Infrastructure\Adapter\Api\Controller\ProjectController;
use Daems\Infrastructure\Adapter\Api\Controller\UserController;
use Daems\Infrastructure\Framework\Container\Container;
use Daems\Infrastructure\Framework\Http\Request;
use Daems\Infrastructure\Framework\Http\Response;
use Daems\Infrastructure\Framework\Http\Router;

return static function (Router $router, Container $container): void {

    $router->get('/api/v1/status', static function (): Response {
        return Response::json(['data' => ['status' => 'ok', 'version' => '1.0.0']]);
    });

    $router->get('/api/v1/events', static function (Request $req) use ($container): Response {
        return $container->make(EventController::class)->index($req);
    });

    $router->get('/api/v1/events/{slug}', static function (Request $req, array $params) use ($container): Response {
        return $container->make(EventController::class)->show($req, $params);
    });

    $router->get('/api/v1/insights', static function (Request $req) use ($container): Response {
        return $container->make(InsightController::class)->index($req);
    });

    $router->get('/api/v1/insights/{slug}', static function (Request $req, array $params) use ($container): Response {
        return $container->make(InsightController::class)->show($req, $params);
    });

    $router->get('/api/v1/projects', static function (Request $req) use ($container): Response {
        return $container->make(ProjectController::class)->index($req);
    });

    $router->post('/api/v1/projects', static function (Request $req) use ($container): Response {
        return $container->make(ProjectController::class)->create($req);
    });

    $router->get('/api/v1/projects/{slug}', static function (Request $req, array $params) use ($container): Response {
        return $container->make(ProjectController::class)->show($req, $params);
    });

    $router->post('/api/v1/projects/{slug}', static function (Request $req, array $params) use ($container): Response {
        return $container->make(ProjectController::class)->update($req, $params);
    });

    $router->post('/api/v1/projects/{slug}/archive', static function (Request $req, array $params) use ($container): Response {
        return $container->make(ProjectController::class)->archive($req, $params);
    });

    $router->post('/api/v1/projects/{slug}/join', static function (Request $req, array $params) use ($container): Response {
        return $container->make(ProjectController::class)->join($req, $params);
    });

    $router->post('/api/v1/projects/{slug}/leave', static function (Request $req, array $params) use ($container): Response {
        return $container->make(ProjectController::class)->leave($req, $params);
    });

    $router->post('/api/v1/projects/{slug}/comments', static function (Request $req, array $params) use ($container): Response {
        return $container->make(ProjectController::class)->addComment($req, $params);
    });

    $router->post('/api/v1/project-comments/{id}/like', static function (Request $req, array $params) use ($container): Response {
        return $container->make(ProjectController::class)->likeComment($req, $params);
    });

    $router->post('/api/v1/projects/{slug}/updates', static function (Request $req, array $params) use ($container): Response {
        return $container->make(ProjectController::class)->addUpdate($req, $params);
    });

    $router->post('/api/v1/project-proposals', static function (Request $req) use ($container): Response {
        return $container->make(ProjectController::class)->propose($req);
    });

    $router->get('/api/v1/users/{id}', static function (Request $req, array $params) use ($container): Response {
        return $container->make(UserController::class)->profile($req, $params);
    });

    $router->post('/api/v1/users/{id}', static function (Request $req, array $params) use ($container): Response {
        return $container->make(UserController::class)->update($req, $params);
    });

    $router->post('/api/v1/users/{id}/password', static function (Request $req, array $params) use ($container): Response {
        return $container->make(UserController::class)->changePasswordAction($req, $params);
    });

    $router->get('/api/v1/users/{id}/activity', static function (Request $req, array $params) use ($container): Response {
        return $container->make(UserController::class)->activity($req, $params);
    });

    $router->post('/api/v1/users/{id}/delete', static function (Request $req, array $params) use ($container): Response {
        return $container->make(UserController::class)->delete($req, $params);
    });

    $router->post('/api/v1/events/{slug}/register', static function (Request $req, array $params) use ($container): Response {
        return $container->make(EventController::class)->register($req, $params);
    });

    $router->post('/api/v1/events/{slug}/unregister', static function (Request $req, array $params) use ($container): Response {
        return $container->make(EventController::class)->unregister($req, $params);
    });

    $router->post('/api/v1/applications/member', static function (Request $req) use ($container): Response {
        return $container->make(ApplicationController::class)->member($req);
    });

    $router->post('/api/v1/applications/supporter', static function (Request $req) use ($container): Response {
        return $container->make(ApplicationController::class)->supporter($req);
    });

    $router->post('/api/v1/auth/login', static function (Request $req) use ($container): Response {
        return $container->make(AuthController::class)->login($req);
    });

    $router->post('/api/v1/auth/register', static function (Request $req) use ($container): Response {
        return $container->make(AuthController::class)->register($req);
    });

    $router->get('/api/v1/forum/categories', static function (Request $req) use ($container): Response {
        return $container->make(ForumController::class)->index($req);
    });

    $router->get('/api/v1/forum/categories/{slug}', static function (Request $req, array $params) use ($container): Response {
        return $container->make(ForumController::class)->category($req, $params);
    });

    $router->get('/api/v1/forum/topics/{slug}', static function (Request $req, array $params) use ($container): Response {
        return $container->make(ForumController::class)->thread($req, $params);
    });

    $router->post('/api/v1/forum/categories/{slug}/topics', static function (Request $req, array $params) use ($container): Response {
        return $container->make(ForumController::class)->createTopic($req, $params);
    });

    $router->post('/api/v1/forum/topics/{slug}/posts', static function (Request $req, array $params) use ($container): Response {
        return $container->make(ForumController::class)->createPost($req, $params);
    });

    $router->post('/api/v1/forum/posts/{id}/like', static function (Request $req, array $params) use ($container): Response {
        return $container->make(ForumController::class)->likePost($req, $params);
    });

    $router->post('/api/v1/forum/topics/{slug}/view', static function (Request $req, array $params) use ($container): Response {
        return $container->make(ForumController::class)->incrementView($req, $params);
    });
};
