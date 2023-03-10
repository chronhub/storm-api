<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

Route::prefix('api/storm/stream')->group(function (): void {
    Route::post('/', \Chronhub\Storm\Http\Api\PostStream::class);
    Route::delete('/', \Chronhub\Storm\Http\Api\DeleteStream::class);
    Route::get('/', \Chronhub\Storm\Http\Api\RetrieveAll::class);
    Route::get('/paginated', \Chronhub\Storm\Http\Api\RetrieveAllPaginated::class);
    Route::get('/from', \Chronhub\Storm\Http\Api\RetrieveFromToStreamPosition::class);
    Route::get('/from_to', \Chronhub\Storm\Http\Api\RetrieveFromToStreamPosition::class);
    Route::get('names', \Chronhub\Storm\Http\Api\RequestStreamNames::class);
    Route::get('categories', \Chronhub\Storm\Http\Api\RequestCategoryNames::class);
    Route::get('exists', \Chronhub\Storm\Http\Api\RequestStreamExists::class);
});

Route::prefix('api/storm/projection')->group(function (): void {
    Route::get('/reset', \Chronhub\Storm\Http\Api\ResetProjection::class);
    Route::get('/stop', \Chronhub\Storm\Http\Api\StopProjection::class);
    Route::get('/state', \Chronhub\Storm\Http\Api\RequestProjectionState::class);
    Route::get('/status', \Chronhub\Storm\Http\Api\RequestProjectionStatus::class);
    Route::get('/position', \Chronhub\Storm\Http\Api\RequestProjectionStreamPosition::class);
    Route::delete('/', \Chronhub\Storm\Http\Api\DeleteProjection::class);
});
