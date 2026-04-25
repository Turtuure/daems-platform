<?php

declare(strict_types=1);

namespace Daems\Application\Insight\UpdateInsight;

use Daems\Application\Insight\CreateInsight\CreateInsight;
use Daems\Domain\Insight\Insight;
use Daems\Domain\Insight\InsightRepositoryInterface;
use Daems\Domain\Shared\NotFoundException;
use Daems\Domain\Shared\ValidationException;

final class UpdateInsight
{
    public function __construct(private readonly InsightRepositoryInterface $repo) {}

    public function execute(UpdateInsightInput $in): UpdateInsightOutput
    {
        $existing = $this->repo->findByIdForTenant($in->insightId, $in->tenantId)
            ?? throw new NotFoundException('not_found');

        $this->validate($in);

        // Slug uniqueness: OK if slug unchanged or owned by this same insight.
        $bySlug = $this->repo->findBySlugForTenant($in->slug, $in->tenantId);
        if ($bySlug !== null && $bySlug->id()->value() !== $in->insightId->value()) {
            throw new ValidationException(['slug' => 'already_exists']);
        }

        $updated = new Insight(
            id: $existing->id(),
            tenantId: $in->tenantId,
            slug: $in->slug,
            title: $in->title,
            category: $in->category,
            categoryLabel: $in->categoryLabel,
            featured: $in->featured,
            date: $in->publishedDate,
            author: $in->author,
            readingTime: CreateInsight::computeReadingTime($in->content),
            excerpt: $in->excerpt,
            heroImage: $in->heroImage,
            tags: $in->tags,
            content: $in->content,
        );
        $this->repo->save($updated);

        return new UpdateInsightOutput($updated);
    }

    private function validate(UpdateInsightInput $in): void
    {
        $errors = [];
        if (trim($in->title) === '') {
            $errors['title'] = 'required';
        } elseif (mb_strlen($in->title) > 255) {
            $errors['title'] = 'too_long';
        }
        if (trim($in->slug) === '') {
            $errors['slug'] = 'required';
        }
        if (trim($in->excerpt) === '') {
            $errors['excerpt'] = 'required';
        } elseif (mb_strlen($in->excerpt) > 500) {
            $errors['excerpt'] = 'too_long';
        }
        if (trim($in->content) === '') {
            $errors['content'] = 'required';
        }
        if (trim($in->category) === '') {
            $errors['category'] = 'required';
        }
        // null published_date = draft (not yet published or scheduled).
        // Only validate the format when a value is provided.
        if ($in->publishedDate !== null && !\DateTime::createFromFormat('Y-m-d', $in->publishedDate)) {
            $errors['published_date'] = 'invalid_format';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
    }
}
