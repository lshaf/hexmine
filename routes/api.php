<?php

use App\Http\Controllers\Api\BattleController;
use App\Http\Controllers\Api\CraftingController;
use App\Http\Controllers\Api\EquipmentController;
use App\Http\Controllers\Api\GuildController;
use App\Http\Controllers\Api\InventoryController;
use App\Http\Controllers\Api\MapController;
use App\Http\Controllers\Api\MiningController;
use App\Http\Controllers\Api\SettlementController;
use App\Http\Controllers\Api\QuestController;
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
    // §5 -- the world is a disc centred on the origin, so most of the map has
    // negative coordinates. whereNumber() is `[0-9]+` and would 404 all of it.
    Route::get('/tiles/{col}/{row}/preview', [MapController::class, 'preview'])
        ->where(['col' => '-?\d+', 'row' => '-?\d+']);

    Route::post('/mining', [MiningController::class, 'store']);
    // §4.0 -- the bare-handed verb, and its own endpoint rather than a flag on
    // the one above: mining refuses without the line's tool, and gathering is
    // the answer to that refusal rather than a quieter version of it.
    Route::post('/gathering', [MiningController::class, 'gather']);
    // §5.5 -- hunting is its own verb, not a mode of mining: it takes no tile
    // slot, depletes nothing, and is the only Tier 4 faucet outside a dungeon.
    Route::post('/hunting', [MiningController::class, 'hunt']);

    // §9.5.5 -- no coordinates: the only fight on offer is the one standing on
    // the hex under your feet, and asking about anyone else's would be a scanner.
    Route::get('/battle/preview', [BattleController::class, 'preview']);
    Route::post('/battle', [BattleController::class, 'store']);

    // §10 -- guilds. The listing is only the recruiting ones (§10.0.1): a
    // roster you can see and cannot join is a queue with extra steps.
    Route::get('/guilds', [GuildController::class, 'index']);
    Route::post('/guilds', [GuildController::class, 'store']);
    Route::patch('/guilds/mine', [GuildController::class, 'update']);
    Route::delete('/guilds/mine', [GuildController::class, 'leave']);
    // §10.5 -- the treasury: anybody may fill it, the owner alone spends it.
    Route::post('/guilds/mine/donations', [GuildController::class, 'donate']);
    Route::post('/guilds/mine/facilities', [GuildController::class, 'upgrade']);
    Route::post('/guilds/{guild}/members', [GuildController::class, 'join'])->whereNumber('guild');
    Route::delete('/guilds/{guild}/applications', [GuildController::class, 'withdraw'])->whereNumber('guild');
    Route::post('/guilds/mine/applications/{member}', [GuildController::class, 'decide'])->whereNumber('member');
    Route::delete('/guilds/mine/members/{member}', [GuildController::class, 'removeMember'])->whereNumber('member');
    Route::post('/guilds/mine/members/{member}/role', [GuildController::class, 'setRole'])->whereNumber('member');
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
    // §8.2 -- gear goes back by id, not by key and quantity: it is one object
    // with a durability, and what it fetches depends on that.
    Route::post('/shop/equipment-sales/{item}', [ShopController::class, 'sellEquipment'])
        ->whereNumber('item');

    Route::post('/crafting', [CraftingController::class, 'store']);

    // §7.4 -- the tree is static and player-independent, so it is its own GET.
    Route::get('/jobs-tree', [SkillTreeController::class, 'index']);
    Route::post('/jobs-tree/nodes', [SkillTreeController::class, 'store']);

    // §12.1 -- the catalog once, the claim per quest. Where a character stands
    // rides in the state like everything else that moves.
    Route::get('/quests', [QuestController::class, 'index']);
    Route::post('/quests/{quest}/claim', [QuestController::class, 'claim'])
        ->where('quest', '[a-z_]+');

    Route::post('/equipment/{item}/equip', [EquipmentController::class, 'equip'])->whereNumber('item');
    Route::post('/equipment/{item}/unequip', [EquipmentController::class, 'unequip'])->whereNumber('item');
    Route::post('/equipment/{item}/repair', [EquipmentController::class, 'repair'])->whereNumber('item');
    Route::delete('/equipment/{item}', [EquipmentController::class, 'destroy'])->whereNumber('item');
});
