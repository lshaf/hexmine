<?php

use Illuminate\Support\Facades\Route;

/*
| The whole client is one Vue SPA. Every non-API path returns the same shell so
| the app owns its own routing; /api/* is handled by routes/api.php and never
| reaches this catch-all.
*/
Route::view('/{any?}', 'app')->where('any', '^(?!api|sanctum|storage|up).*$');
