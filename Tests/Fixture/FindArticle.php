<?php

declare(strict_types=1);

namespace Storm\Api\Tests\Fixture;

/** The item read: answered with an {@see ArticleView} or null. */
final readonly class FindArticle
{
    public function __construct(
        public string $id,
    ) {}
}
