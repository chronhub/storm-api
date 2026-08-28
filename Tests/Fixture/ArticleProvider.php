<?php

declare(strict_types=1);

namespace Storm\Api\Tests\Fixture;

use ApiPlatform\Metadata\CollectionOperationInterface;
use ApiPlatform\Metadata\Operation;
use LogicException;
use Override;
use Storm\Api\State\QueryProvider;

use function is_string;

/**
 * What an app provider looks like: name the read, delegate the mapping to the resource's
 * explicit factory; the template method does the rest.
 *
 * @extends QueryProvider<ArticleView, ArticleResource>
 */
final class ArticleProvider extends QueryProvider
{
    #[Override]
    protected function queryFor(Operation $operation, array $uriVariables, array $context): object
    {
        if ($operation instanceof CollectionOperationInterface) {
            return new ListArticles;
        }

        $id = $uriVariables['id'] ?? null;

        if (! is_string($id)) {
            throw new LogicException('The article item route carries a string "id" uri variable.');
        }

        return new FindArticle($id);
    }

    #[Override]
    protected function toResource(mixed $readModel): object
    {
        return ArticleResource::fromReadModel($readModel);
    }
}
