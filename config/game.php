<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Clock compression
    |--------------------------------------------------------------------------
    |
    | Real timers are 30-60 minutes (CLAUDE.md §7.3), which makes the game
    | impossible to exercise by hand. Durations are divided by this at the
    | persistence boundary only -- every formula stays honest, so 1 gives true
    | production timings.
    |
    | This MUST match the frontend's VITE_TIME_SCALE, or the countdowns the
    | client predicts will not line up with the ones the server actually runs.
    |
    */
    'time_scale' => (int) env('GAME_TIME_SCALE', 1),

    /*
    |--------------------------------------------------------------------------
    | Map combat, §9.5
    |--------------------------------------------------------------------------
    |
    | Whether packs stand on hexes at all. On by default; off makes the roads
    | quiet, which is what a test measuring mining wants and what an operator
    | might want on a debug server. Nothing else about combat changes: gear,
    | benches and the battle jobs stay exactly where they are.
    |
    */
    'packs' => (bool) env('GAME_PACKS', true),

    /*
    |--------------------------------------------------------------------------
    | Automatic character provisioning
    |--------------------------------------------------------------------------
    |
    | The design is wallet-bound (§7): one soulbound character per wallet, and a
    | wallet must hold a minimum balance for 7 continuous days before it can act
    | (§2). None of that exists yet -- there is no wallet connect flow.
    |
    | While this is on, the API mints a character for the caller's session on
    | first contact so the game is playable end to end. It is a DEVELOPMENT
    | AFFORDANCE and must be off in production: with it on, anyone can create
    | unlimited characters, which is exactly the sybil vector §2 exists to close.
    |
    */
    'auto_provision' => (bool) env('GAME_AUTO_PROVISION', true),

    /*
    |--------------------------------------------------------------------------
    | The map
    |--------------------------------------------------------------------------
    |
    | Nothing about the world is stored: every tile is a pure function of its
    | coordinates and the seed (CLAUDE.md §5), so these two values ARE the world.
    | Change either and every hex on it changes with it.
    |
    | The map is square, so one radius covers it. Measured from the middle out
    | (§5.1): 200 means every column and every row from -200 to 200 inclusive,
    | so the grid is 401 a side. Ship value is 2500, for the 5000x5000 of §5.
    |
    | The client is handed all three by GET /api/world at boot rather than
    | compiling them in, so this file is the single source of truth and no
    | frontend edit is needed. What a change DOES need is a regenerated parity
    | fixture: `php artisan game:worldgen-fixture`, then `npm run parity`.
    |
    | The seed accepts hex (0x5eed1a3f) or decimal, and is masked to 32 bits --
    | the hash is a bit-for-bit port of the JavaScript one and only agrees with
    | it inside that width.
    |
    */
    'map' => [
        'radius' => (int) env('GAME_MAP_RADIUS', 200),
        'seed' => env('GAME_MAP_SEED', '0x5eed1a3f'),
    ],

];
