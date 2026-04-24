<?php

declare(strict_types=1);

namespace Daems\Application\Insight\CreateInsight;

use Daems\Domain\Insight\Insight;
use Daems\Domain\Insight\InsightId;
use Daems\Domain\Insight\InsightRepositoryInterface;
use Daems\Domain\Shared\ValidationException;
use Daems\Domain\Shared\ValueObject\Uuid7;

final class CreateInsight
{
    public function __construct(private readonly InsightRepositoryInterface $repo) {}

    public function execute(CreateInsightInput $in): CreateInsightOutput
    {
        $this->validate($in);

        if ($this->repo->findBySlugForTenant($in->slug, $in->tenantId) !== null) {
            throw new ValidationException(['slug' => 'already_exists']);
        }

        $insight = new Insight(
            id: InsightId::fromString(Uuid7::generate()->value()),
            tenantId: $in->tenantId,
            slug: $in->slug,
            title: $in->title,
            category: $in->category,
            categoryLabel: $in->categoryLabel,
            featured: $in->featured,
            date: $in->publishedDate,
            author: $in->author,
            readingTime: self::computeReadingTime($in->content),
            excerpt: $in->excerpt,
            heroImage: $in->heroImage,
            tags: $in->tags,
            content: $in->content,
        );
        $this->repo->save($insight);

        return new CreateInsightOutput($insight);
    }

    private function validate(CreateInsightInput $in): void
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
        if (!self::isDate($in->publishedDate)) {
            $errors['published_date'] = 'invalid_format';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
    }

    private static function isDate(string $s): bool
    {
        return (bool) \DateTime::createFromFormat('Y-m-d', $s);
    }

    public static function computeReadingTime(string $content): int
    {
        $plain = strip_tags($content);
        $words = max(1, str_word_count($plain));
        return max(1, (int) ceil($words / 200));
    }
}
