<?php

declare(strict_types=1);

namespace Storm\Api\Tests\Fixture;

/**
 * The public resource DTO with its explicit mapping factory, the idiom the base provider's
 * `toResource` hook delegates to.
 */
final readonly class ArticleResource
{
    public function __construct(
        public string $id,
        public string $title,
    ) {}

    public static function fromReadModel(ArticleView $view): self
    {
        return new self($view->id, $view->title);
    }
}
