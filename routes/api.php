<?php

declare(strict_types=1);

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
use Daems\Infrastructure\Framework\Http\Request;
use Daems\Infrastructure\Framework\Http\Response;
use Daems\Infrastructure\Framework\Http\Router;

return static function (Router $router, Container $container): void {

    $router->get('/api/v1/status', static function (): Response {
        return Response::json(['data' => ['status' => 'ok', 'version' => '1.0.0']]);
    });

    // Events — public reads
    $router->get('/api/v1/events', static function (Request $req) use ($container): Response {
        return $container->make(EventController::class)->index($req);
    });

    $router->get('/api/v1/events/{slug}', static function (Request $req, array $params) use ($container): Response {
        return $container->make(EventController::class)->show($req, $params);
    });

    // Insights — public reads
    $router->get('/api/v1/insights', static function (Request $req) use ($container): Response {
        return $container->make(InsightController::class)->index($req);
    });

    $router->get('/api/v1/insights/{slug}', static function (Request $req, array $params) use ($container): Response {
        return $container->make(InsightController::class)->show($req, $params);
    });

    // Projects — public reads
    $router->get('/api/v1/projects', static function (Request $req) use ($container): Response {
        return $container->make(ProjectController::class)->index($req);
    });

    $router->get('/api/v1/projects/{slug}', static function (Request $req, array $params) use ($container): Response {
        return $container->make(ProjectController::class)->show($req, $params);
    });

    // Projects — protected mutations
    $router->post('/api/v1/projects', static function (Request $req) use ($container): Response {
        return $container->make(ProjectController::class)->create($req);
    }, [AuthMiddleware::class]);

    $router->post('/api/v1/projects/{slug}', static function (Request $req, array $params) use ($container): Response {
        return $container->make(ProjectController::class)->update($req, $params);
    }, [AuthMiddleware::class]);

    $router->post('/api/v1/projects/{slug}/archive', static function (Request $req, array $params) use ($container): Response {
        return $container->make(ProjectController::class)->archive($req, $params);
    }, [AuthMiddleware::class]);

    $router->post('/api/v1/projects/{slug}/join', static function (Request $req, array $params) use ($container): Response {
        return $container->make(ProjectController::class)->join($req, $params);
    }, [AuthMiddleware::class]);

    $router->post('/api/v1/projects/{slug}/leave', static function (Request $req, array $params) use ($container): Response {
        return $container->make(ProjectController::class)->leave($req, $params);
    }, [AuthMiddleware::class]);

    $router->post('/api/v1/projects/{slug}/comments', static function (Request $req, array $params) use ($container): Response {
        return $container->make(ProjectController::class)->addComment($req, $params);
    }, [AuthMiddleware::class]);

    $router->post('/api/v1/project-comments/{id}/like', static function (Request $req, array $params) use ($container): Response {
        return $container->make(ProjectController::class)->likeComment($req, $params);
    }, [AuthMiddleware::class]);

    $router->post('/api/v1/projects/{slug}/updates', static function (Request $req, array $params) use ($container): Response {
        return $container->make(ProjectController::class)->addUpdate($req, $params);
    }, [AuthMiddleware::class]);

    $router->post('/api/v1/project-proposals', static function (Request $req) use ($container): Response {
        return $container->make(ProjectController::class)->propose($req);
    }, [AuthMiddleware::class]);

    // Users — all protected
    $router->get('/api/v1/users/{id}', static function (Request $req, array $params) use ($container): Response {
        return $container->make(UserController::class)->profile($req, $params);
    }, [AuthMiddleware::class]);

    $router->post('/api/v1/users/{id}', static function (Request $req, array $params) use ($container): Response {
        return $container->make(UserController::class)->update($req, $params);
    }, [AuthMiddleware::class]);

    $router->post('/api/v1/users/{id}/password', static function (Request $req, array $params) use ($container): Response {
        return $container->make(UserController::class)->changePasswordAction($req, $params);
    }, [AuthMiddleware::class]);

    $router->get('/api/v1/users/{id}/activity', static function (Request $req, array $params) use ($container): Response {
        return $container->make(UserController::class)->activity($req, $params);
    }, [AuthMiddleware::class]);

    $router->post('/api/v1/users/{id}/delete', static function (Request $req, array $params) use ($container): Response {
        return $container->make(UserController::class)->delete($req, $params);
    }, [AuthMiddleware::class]);

    // Events — protected mutations
    $router->post('/api/v1/events/{slug}/register', static function (Request $req, array $params) use ($container): Response {
        return $container->make(EventController::class)->register($req, $params);
    }, [AuthMiddleware::class]);

    $router->post('/api/v1/events/{slug}/unregister', static function (Request $req, array $params) use ($container): Response {
        return $container->make(EventController::class)->unregister($req, $params);
    }, [AuthMiddleware::class]);

    // Applications — protected
    $router->post('/api/v1/applications/member', static function (Request $req) use ($container): Response {
        return $container->make(ApplicationController::class)->member($req);
    }, [AuthMiddleware::class]);

    $router->post('/api/v1/applications/supporter', static function (Request $req) use ($container): Response {
        return $container->make(ApplicationController::class)->supporter($req);
    }, [AuthMiddleware::class]);

    // Auth
    $router->post('/api/v1/auth/login', static function (Request $req) use ($container): Response {
        return $container->make(AuthController::class)->login($req);
    }, [RateLimitLoginMiddleware::class]);

    $router->post('/api/v1/auth/register', static function (Request $req) use ($container): Response {
        return $container->make(AuthController::class)->register($req);
    });

    $router->post('/api/v1/auth/logout', static function (Request $req) use ($container): Response {
        return $container->make(AuthController::class)->logout($req);
    }, [AuthMiddleware::class]);

    // Forum — public reads
    $router->get('/api/v1/forum/categories', static function (Request $req) use ($container): Response {
        return $container->make(ForumController::class)->index($req);
    });

    $router->get('/api/v1/forum/categories/{slug}', static function (Request $req, array $params) use ($container): Response {
        return $container->make(ForumController::class)->category($req, $params);
    });

    $router->get('/api/v1/forum/topics/{slug}', static function (Request $req, array $params) use ($container): Response {
        return $container->make(ForumController::class)->thread($req, $params);
    });

    // Forum — protected writes
    $router->post('/api/v1/forum/categories/{slug}/topics', static function (Request $req, array $params) use ($container): Response {
        return $container->make(ForumController::class)->createTopic($req, $params);
    }, [AuthMiddleware::class]);

    $router->post('/api/v1/forum/topics/{slug}/posts', static function (Request $req, array $params) use ($container): Response {
        return $container->make(ForumController::class)->createPost($req, $params);
    }, [AuthMiddleware::class]);

    $router->post('/api/v1/forum/posts/{id}/like', static function (Request $req, array $params) use ($container): Response {
        return $container->make(ForumController::class)->likePost($req, $params);
    }, [AuthMiddleware::class]);

    // Public — view counter doesn't need auth
    $router->post('/api/v1/forum/topics/{slug}/view', static function (Request $req, array $params) use ($container): Response {
        return $container->make(ForumController::class)->incrementView($req, $params);
    });
};
