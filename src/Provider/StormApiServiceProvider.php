<?php

declare(strict_types=1);

namespace Chronhub\Storm\Http\Api\Provider;

use Illuminate\Support\ServiceProvider;

class StormApiServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../../routes/storm_api.php');
    }

    public function register(): void
    {
    }
}
