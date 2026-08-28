<?php

declare(strict_types=1);

namespace Storm\Api\Tests\Fixture;

/**
 * The internal read model, what a projection would have written. Never leaves the app
 * unmapped: {@see ArticleResource::fromReadModel()} is the only way out.
 */
final readonly class ArticleView
{
    public function __construct(
        public string $id,
        public string $title,
    ) {}
}
