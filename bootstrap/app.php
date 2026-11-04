<?php

use App\Exceptions\ApiExceptionRenderer;
use App\Http\Middleware\ForceJson;
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
    ->withMiddleware(function (Middleware $middleware) {
        // Global (avant le routage) pour que les 404/405 et le JSON malformé
        // de /api soient eux aussi rendus en JSON.
        $middleware->prepend(ForceJson::class);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->render(
            fn (Throwable $e, Request $request) => app(ApiExceptionRenderer::class)->render($e, $request)
        );
    })->create();
