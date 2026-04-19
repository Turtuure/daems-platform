<?php

declare(strict_types=1);

namespace Daems\Infrastructure\Adapter\Api\Controller;

use Daems\Application\Forum\CreateForumPost\CreateForumPost;
use Daems\Application\Forum\CreateForumPost\CreateForumPostInput;
use Daems\Application\Forum\CreateForumTopic\CreateForumTopic;
use Daems\Application\Forum\CreateForumTopic\CreateForumTopicInput;
use Daems\Application\Forum\GetForumCategory\GetForumCategory;
use Daems\Application\Forum\GetForumCategory\GetForumCategoryInput;
use Daems\Application\Forum\GetForumThread\GetForumThread;
use Daems\Application\Forum\GetForumThread\GetForumThreadInput;
use Daems\Application\Forum\IncrementTopicView\IncrementTopicView;
use Daems\Application\Forum\IncrementTopicView\IncrementTopicViewInput;
use Daems\Application\Forum\LikeForumPost\LikeForumPost;
use Daems\Application\Forum\LikeForumPost\LikeForumPostInput;
use Daems\Application\Forum\ListForumCategories\ListForumCategories;
use Daems\Application\Forum\ListForumCategories\ListForumCategoriesInput;
use Daems\Domain\Shared\NotFoundException;
use Daems\Domain\Tenant\Tenant;
use Daems\Infrastructure\Framework\Http\Request;
use Daems\Infrastructure\Framework\Http\Response;

final class ForumController
{
    public function __construct(
        private readonly ListForumCategories $listCategories,
        private readonly GetForumCategory $getCategory,
        private readonly GetForumThread $getThread,
        private readonly CreateForumTopic $createTopic,
        private readonly CreateForumPost $createPost,
        private readonly LikeForumPost $likePostUseCase,
        private readonly IncrementTopicView $incrementViewUseCase,
    ) {}

    public function index(Request $request): Response
    {
        $tenantId = $this->requireTenant($request)->id;
        $output = $this->listCategories->execute(new ListForumCategoriesInput($tenantId));
        return Response::json(['data' => $output->categories]);
    }

    public function category(Request $request, array $params): Response
    {
        $tenantId = $this->requireTenant($request)->id;
        $output = $this->getCategory->execute(new GetForumCategoryInput($tenantId, $params['slug']));

        if ($output->data === null) {
            return Response::notFound('Category not found');
        }

        return Response::json(['data' => $output->data]);
    }

    public function thread(Request $request, array $params): Response
    {
        $tenantId = $this->requireTenant($request)->id;
        $output = $this->getThread->execute(new GetForumThreadInput($tenantId, $params['slug']));

        if ($output->data === null) {
            return Response::notFound('Thread not found');
        }

        return Response::json(['data' => $output->data]);
    }

    private function requireTenant(Request $request): Tenant
    {
        $tenant = $request->attribute('tenant');
        if (!$tenant instanceof Tenant) {
            throw new NotFoundException('unknown_tenant');
        }
        return $tenant;
    }

    public function createTopic(Request $request, array $params): Response
    {
        $acting = $request->requireActingUser();
        $title   = trim($request->string('title') ?? '');
        $content = trim($request->string('content') ?? '');

        if ($title === '' || $content === '') {
            return Response::badRequest('Title and content are required.');
        }

        $output = $this->createTopic->execute(new CreateForumTopicInput(
            $acting,
            $params['slug'],
            $title,
            $content,
        ));

        if ($output->error !== null) {
            return Response::notFound($output->error);
        }

        return Response::json(['data' => ['slug' => $output->topicSlug]], 201);
    }

    public function createPost(Request $request, array $params): Response
    {
        $acting = $request->requireActingUser();
        $content = trim($request->string('content') ?? '');

        if ($content === '') {
            return Response::badRequest('Content is required.');
        }

        $output = $this->createPost->execute(new CreateForumPostInput(
            $acting,
            $params['slug'],
            $content,
        ));

        if (!$output->success) {
            return Response::notFound($output->error ?? 'Thread not found.');
        }

        return Response::json(['data' => $output->post], 201);
    }

    public function likePost(Request $request, array $params): Response
    {
        $request->requireActingUser();
        $this->likePostUseCase->execute(new LikeForumPostInput($params['id']));
        return Response::json(['data' => ['ok' => true]]);
    }

    public function incrementView(Request $request, array $params): Response
    {
        $tenantId = $this->requireTenant($request)->id;
        $this->incrementViewUseCase->execute(new IncrementTopicViewInput($tenantId, $params['slug']));
        return Response::json(['data' => ['ok' => true]]);
    }
}
