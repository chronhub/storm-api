<?php

declare(strict_types=1);

namespace Chronhub\Storm\Http\Api\Provider;

use Illuminate\Support\ServiceProvider;
use Chronhub\Storm\Http\Api\RetrieveAllPaginated;
use Chronhub\Storm\Contracts\Chronicler\QueryFilter;
use Chronhub\Storm\Contracts\Message\MessageFactory;
use Chronhub\Storm\Http\Api\Support\StreamEventFactory;
use Chronhub\Storm\Http\Api\RetrieveFromToStreamPosition;
use Chronhub\Storm\Http\Api\QueryFilter\AllPaginatedStream;
use Chronhub\Storm\Http\Api\QueryFilter\FromToStreamPosition;
use Chronhub\Storm\Http\Api\RetrieveFromIncludedStreamPosition;
use Chronhub\Storm\Http\Api\QueryFilter\FromIncludedStreamPosition;

class StormApiServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../../routes/storm_api.php');
    }

    public function register(): void
    {
        $this->app->bind(MessageFactory::class, StreamEventFactory::class);
    }
}
