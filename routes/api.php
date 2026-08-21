<?php

use App\Http\Controllers\Api\CraftingController;
use App\Http\Controllers\Api\EquipmentController;
use App\Http\Controllers\Api\InventoryController;
use App\Http\Controllers\Api\MapController;
use App\Http\Controllers\Api\MiningController;
use App\Http\Controllers\Api\SettlementController;
use App\Http\Controllers\Api\ShopController;
use App\Http\Controllers\Api\SkillTreeController;
use App\Http\Controllers\Api\StateController;
use App\Http\Middleware\ResolveCharacter;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Game API
|--------------------------------------------------------------------------
|
| Server-authoritative, §16. The client sends intent and renders what it is
| told; it never asserts elapsed time, yields or drops.
|
| Every mutating route answers { data, state, message } where `state` is the
| complete fresh PlayerState.
|
*/

Route::middleware(ResolveCharacter::class)->group(function () {
    Route::get('/state', [StateController::class, 'show']);

    Route::get('/world', [MapController::class, 'world']);
    Route::get('/map', [MapController::class, 'index']);
    Route::get('/tiles/{col}/{row}/preview', [MapController::class, 'preview'])
        ->whereNumber(['col', 'row']);

    Route::post('/mining', [MiningController::class, 'store']);
    Route::post('/jobs/{job}/collect', [MiningController::class, 'collect'])->whereNumber('job');
    Route::delete('/jobs/{job}', [MiningController::class, 'destroy'])->whereNumber('job');

    Route::get('/settlements/{settlement}', [SettlementController::class, 'show']);
    Route::post('/settlements/{settlement}/processing', [SettlementController::class, 'processing']);
    Route::post('/travel', [SettlementController::class, 'travel']);
    Route::delete('/travel', [SettlementController::class, 'cancelTravel']);

    Route::post('/inventory/discards', [InventoryController::class, 'discard']);
    Route::post('/inventory/drinks', [InventoryController::class, 'drink']);

    Route::post('/shop/purchases', [ShopController::class, 'purchase']);
    Route::post('/shop/sales', [ShopController::class, 'sell']);

    Route::post('/crafting', [CraftingController::class, 'store']);

    // §7.4 -- the tree is static and player-independent, so it is its own GET.
    Route::get('/jobs-tree', [SkillTreeController::class, 'index']);
    Route::post('/jobs-tree/nodes', [SkillTreeController::class, 'store']);

    Route::post('/equipment/{item}/equip', [EquipmentController::class, 'equip'])->whereNumber('item');
    Route::post('/equipment/{item}/unequip', [EquipmentController::class, 'unequip'])->whereNumber('item');
    Route::post('/equipment/{item}/repair', [EquipmentController::class, 'repair'])->whereNumber('item');
    Route::delete('/equipment/{item}', [EquipmentController::class, 'destroy'])->whereNumber('item');
});
