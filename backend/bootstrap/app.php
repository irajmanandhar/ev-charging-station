<?php

use App\Exceptions\DomainRuleException;
use App\Http\Middleware\EnsureRole;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

$error = fn (string $message, int $status, array $errors = []) => response()->json([
    'success' => false,
    'message' => $message,
    'errors' => (object) $errors,
], $status);

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias(['role' => EnsureRole::class]);
    })
    ->withExceptions(function (Exceptions $exceptions) use ($error): void {
        // Business-rule outcomes (slot taken, closed, ...) are expected, not errors.
        $exceptions->dontReport([DomainRuleException::class]);

        $isApi = fn (Request $request) => $request->is('api/*') || $request->expectsJson();

        $exceptions->render(function (DomainRuleException $e, Request $request) use ($error) {
            return $error($e->getMessage(), $e->status, ['code' => $e->errorCode]);
        });

        $exceptions->render(function (ValidationException $e, Request $request) use ($error, $isApi) {
            if ($isApi($request)) {
                return $error($e->validator->errors()->first() ?: 'The given data was invalid.', 422, $e->errors());
            }
        });

        $exceptions->render(function (AuthenticationException $e, Request $request) use ($error, $isApi) {
            if ($isApi($request)) {
                return $error('Please sign in to continue.', 401);
            }
        });

        $exceptions->render(function (AuthorizationException|AccessDeniedHttpException $e, Request $request) use ($error, $isApi) {
            if ($isApi($request)) {
                return $error('You are not allowed to do that.', 403);
            }
        });

        $exceptions->render(function (ModelNotFoundException|NotFoundHttpException $e, Request $request) use ($error, $isApi) {
            if ($isApi($request)) {
                return $error('Not found.', 404);
            }
        });

        $exceptions->render(function (HttpExceptionInterface $e, Request $request) use ($error, $isApi) {
            if ($isApi($request)) {
                return $error($e->getMessage() ?: 'Request failed.', $e->getStatusCode());
            }
        });
    })->create();
