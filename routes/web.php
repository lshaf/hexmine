<?php

use Illuminate\Support\Facades\Route;

/*
| The almanac is its own page, not part of the SPA: it is a reference over the
| static catalogs, it takes no character and makes no request, and it has no
| business loading the map to be read. Registered first so the catch-all below
| never sees it.
*/
Route::view('/almanac', 'almanac');

/*
| The whole client is one Vue SPA. Every non-API path returns the same shell so
| the app owns its own routing; /api/* is handled by routes/api.php and never
| reaches this catch-all.
*/
Route::view('/{any?}', 'app')->where('any', '^(?!api|sanctum|storage|up).*$');
