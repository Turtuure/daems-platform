<?php

declare(strict_types=1);

namespace Daems\Infrastructure\Adapter\Api\Controller;

use Daems\Application\Insight\GetInsight\GetInsight;
use Daems\Application\Insight\GetInsight\GetInsightInput;
use Daems\Application\Insight\ListInsights\ListInsights;
use Daems\Application\Insight\ListInsights\ListInsightsInput;
use Daems\Infrastructure\Framework\Http\Request;
use Daems\Infrastructure\Framework\Http\Response;

final class InsightController
{
    public function __construct(
        private readonly ListInsights $listInsights,
        private readonly GetInsight $getInsight,
    ) {}

    public function index(Request $request): Response
    {
        $category = $request->query('category');
        $output = $this->listInsights->execute(new ListInsightsInput($category ?: null));
        return Response::json(['data' => $output->insights]);
    }

    public function show(Request $request, array $params): Response
    {
        $output = $this->getInsight->execute(new GetInsightInput($params['slug']));

        if ($output->insight === null) {
            return Response::notFound('Insight not found');
        }

        return Response::json(['data' => $output->insight]);
    }
}
