<?php

declare(strict_types=1);

use App\Domain\Exceptions\DomainException;
use App\Http\Middleware\JwtAuthMiddleware;
use App\Http\Responses\ApiResponse;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        apiPrefix: 'api/v1',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'jwt.auth' => JwtAuthMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (DomainException $e, Request $request) {
            return ApiResponse::error(
                $e->errorCode(),
                $e->getMessage(),
                $e->details(),
                $e->httpStatus()
            );
        });

        $exceptions->render(function (NotFoundHttpException|MethodNotAllowedHttpException $e, Request $request) {
            return ApiResponse::error(
                'NOT_FOUND',
                'El recurso o endpoint solicitado no existe.',
                [],
                404
            );
        });

        $exceptions->render(function (Throwable $e, Request $request) {
            // RNF-4 & architecture.md §5: Never expose stack traces, SQL, server paths, or passwords
            return ApiResponse::error(
                'INTERNAL_ERROR',
                'Ocurrió un error interno al procesar la solicitud.',
                [],
                500
            );
        });
    })->create();
