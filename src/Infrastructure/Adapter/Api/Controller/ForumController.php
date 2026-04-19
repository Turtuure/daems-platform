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
        $output = $this->listCategories->execute(new ListForumCategoriesInput());
        return Response::json(['data' => $output->categories]);
    }

    public function category(Request $request, array $params): Response
    {
        $output = $this->getCategory->execute(new GetForumCategoryInput($params['slug']));

        if ($output->data === null) {
            return Response::notFound('Category not found');
        }

        return Response::json(['data' => $output->data]);
    }

    public function thread(Request $request, array $params): Response
    {
        $output = $this->getThread->execute(new GetForumThreadInput($params['slug']));

        if ($output->data === null) {
            return Response::notFound('Thread not found');
        }

        return Response::json(['data' => $output->data]);
    }

    public function createTopic(Request $request, array $params): Response
    {
        $acting = $request->requireActingUser();
        $title   = trim((string) $request->input('title'));
        $content = trim((string) $request->input('content'));

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
        $content = trim((string) $request->input('content'));

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
        $acting = $request->requireActingUser();
        $this->likePostUseCase->execute(new LikeForumPostInput($acting, $params['id']));
        return Response::json(['data' => ['ok' => true]]);
    }

    public function incrementView(Request $request, array $params): Response
    {
        $this->incrementViewUseCase->execute(new IncrementTopicViewInput($params['slug']));
        return Response::json(['data' => ['ok' => true]]);
    }
}
