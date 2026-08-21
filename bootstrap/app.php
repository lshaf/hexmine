<?php

use App\Game\GameException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Client and API are the same origin, so the API is cookie-authenticated
        // rather than token-based. statefulApi() brings sessions and CSRF to the
        // /api group; there is no CORS config because there is no second origin.
        $middleware->statefulApi();

        // Behind a tunnel (Cloudflare, ngrok) or any reverse proxy, TLS is
        // terminated upstream and the app is reached over plain HTTP on
        // localhost. Untrusted, Laravel would believe the request really is
        // insecure and generate http:// asset URLs into an https:// page --
        // which the browser blocks as mixed content, leaving a blank #app.
        //
        // The proxy list is '*' because the only hop is the tunnel client on
        // this machine; there is no public ingress that could spoof the header.
        $middleware->trustProxies(at: '*');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // A GameException is a rule saying no ("both slots are taken", "not
        // enough gold"), not a fault. It carries player-facing copy and renders
        // as 422 so the client can show it verbatim.
        $exceptions->render(function (GameException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json([
                    'message' => $e->getMessage(),
                    'code' => $e->errorCode,
                ], 422);
            }

            return null;
        });
    })->create();
