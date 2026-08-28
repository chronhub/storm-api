<?php

declare(strict_types=1);

namespace Storm\Api\State;

use ApiPlatform\Metadata\CollectionOperationInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use LogicException;
use Override;
use Storm\Story\QueryBus;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Throwable;
use Traversable;

use function array_map;
use function array_values;
use function get_debug_type;
use function is_iterable;
use function iterator_to_array;
use function sprintf;

/**
 * The bridge's read side: one template method maps the HTTP read to a Story query, asks the
 * QueryBus, and maps the read model or models onto the public resource DTO. The app provider
 * only declares WHAT to ask through `queryFor` and HOW to expose it through `toResource`;
 * `provide()` is final so the bridge invariants cannot be re-decided per resource:
 *
 *  - A missing item stays `null`, API Platform's native 404, never an exception raised here;
 *
 *  - A collection is re-keyed as a list and mapped item by item through the same explicit
 *    factory, so a read model is never exposed naked;
 *
 *  - A pure read: no dispatch, no retry, no state. Pagination and filters belong to the app
 *    provider; parse them in `queryFor` and carry them IN the query.
 *
 * A handler failure travels out still wrapped in Messenger's envelope on purpose: the single
 * unwrapping point is the error mapping layer, not each provider.
 *
 * @template TReadModel
 * @template TResource of object
 *
 * @implements ProviderInterface<TResource>
 */
abstract class QueryProvider implements ProviderInterface
{
    public function __construct(
        private readonly QueryBus $queryBus,
    ) {}

    /**
     * {@inheritDoc}
     *
     * @return ($operation is CollectionOperationInterface ? list<TResource> : TResource|null)
     *
     * @throws HandlerFailedException wrapping the query handler's own failure, unwrapped downstream by the error layer
     * @throws LogicException when zero or several handlers answered the query, a wiring fault
     *                        surfaced by Messenger; or when a collection handler answered a
     *                        non-iterable, named here rather than left to a raw TypeError
     * @throws Throwable any other failure, unwrapped by the error layer
     */
    #[Override]
    final public function provide(Operation $operation, array $uriVariables = [], array $context = []): object|array|null
    {
        $result = $this->queryBus->ask($this->queryFor($operation, $uriVariables, $context));

        if ($operation instanceof CollectionOperationInterface) {
            if (! is_iterable($result)) {
                throw new LogicException(sprintf(
                    'The query handler behind collection operation "%s" returned %s — a collection read must answer an iterable of read models, and a raw TypeError here would name the symptom, not this wiring fault.',
                    $operation->getName() ?? $operation::class,
                    get_debug_type($result),
                ));
            }

            /** @var iterable<TReadModel> $result */
            $items = $result instanceof Traversable ? iterator_to_array($result, preserve_keys: false) : array_values($result);

            return array_map($this->toResource(...), $items);
        }

        if ($result === null) {
            return null;
        }

        /** @var TReadModel $result */
        return $this->toResource($result);
    }

    /**
     * Build the Story query answering this operation; the app names the read. For a collection,
     * pagination and filter parameters are parsed HERE and carried in the query, never applied
     * on the result. Ownership is included: scope in the query, not by post-filtering.
     *
     * @param  array<string, mixed>  $uriVariables
     * @param  array<string, mixed>  $context
     */
    abstract protected function queryFor(Operation $operation, array $uriVariables, array $context): object;

    /**
     * Map ONE read model onto the public resource DTO, explicit mapping through the resource's
     * own static factory, for example, `BookResource::fromReadModel($readModel)`.
     *
     * @param  TReadModel  $readModel
     * @return TResource
     */
    abstract protected function toResource(mixed $readModel): object;
}
