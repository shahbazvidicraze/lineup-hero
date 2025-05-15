<?php

use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpFoundation\Request;



return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        //
    })
    ->withExceptions(function (Exceptions $exceptions) {

        // --- Authentication Exception Handling for API ---
        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if ($request->is('api/*') || $request->wantsJson()) {
                return response()->json(['success'=>false, 'message' => 'You are unauthenticated.'], 401);
            }
        });

        $exceptions->render(function (ModelNotFoundException $e, Request $request) {
            if ($request->is('api/*') || $request->wantsJson()) {
                 return response()->json(['success'=> false,'message' => 'Resource not found.'], 404);
            }
        });


        $exceptions->render(function (NotFoundHttpException $e, Request $request) { // <-- Handler for NotFoundHttpException
            if ($request->is('api/*') || $request->wantsJson()) {

                return response()->json(['success'=>false,'message' => 'Resource not found.'], 404); // Return JSON 404
            }
             // Let default handler manage non-API 404s (shows HTML 404 page)
        });


    })->create();
