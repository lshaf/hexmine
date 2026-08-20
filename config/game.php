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

];
