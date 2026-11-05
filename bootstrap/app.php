<?php

use App\Exceptions\FriendlyExceptionPresenter;
use App\Http\Middleware\SetLocale;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Foundation\Exceptions\RegisterErrorViewPaths;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->web(append: [
            SetLocale::class,
        ]);

        $middleware->trustProxies(at: '*');

        // There is no `login` route: guests hitting an `auth` route go to the admin login.
        $middleware->redirectGuestsTo(fn (): string => route('filament.admin.auth.login'));
    })
    ->withExceptions(function (Exceptions $exceptions) {
        /*
         * API clients (routes under api/ and admin/api/) and anything asking for
         * JSON get JSON errors. Everything else gets the plain-language pages in
         * resources/views/errors.
         */
        $wantsJson = fn (Request $request): bool => $request->is('api/*')
            || $request->is('admin/api/*')
            || $request->expectsJson();

        $exceptions->render(function (ValidationException $e, Request $request) use ($wantsJson) {
            if ($wantsJson($request)) {
                return response()->json([
                    'message' => $e->getMessage(),
                    'errors'  => $e->errors(),
                ], 422);
            }
        });

        $exceptions->render(function (AuthenticationException $e, Request $request) use ($wantsJson) {
            if ($wantsJson($request)) {
                return response()->json([
                    'message' => 'Unauthenticated.',
                ], 401);
            }
        });

        $exceptions->render(function (AuthorizationException $e, Request $request) use ($wantsJson) {
            if ($wantsJson($request)) {
                return response()->json([
                    'message' => 'This action is unauthorized.',
                ], 403);
            }
        });

        $exceptions->render(function (AccessDeniedHttpException $e, Request $request) use ($wantsJson) {
            if ($wantsJson($request)) {
                return response()->json([
                    'message' => 'This action is unauthorized.',
                ], 403);
            }
        });

        $exceptions->render(function (ModelNotFoundException $e, Request $request) use ($wantsJson) {
            if ($wantsJson($request)) {
                return response()->json([
                    'message' => 'Resource not found.',
                ], 404);
            }
        });

        $exceptions->render(function (NotFoundHttpException $e, Request $request) use ($wantsJson) {
            if ($wantsJson($request)) {
                return response()->json([
                    'message' => 'The requested resource was not found.',
                ], 404);
            }
        });

        $exceptions->render(function (Throwable $e, Request $request) use ($wantsJson) {
            if ($wantsJson($request)) {
                $statusCode = $e instanceof HttpExceptionInterface ? $e->getStatusCode() : 500;

                if ($statusCode === 500) {
                    // Class, file and line only while debugging; never to real users.
                    $debug = (bool) config('app.debug') && ! app()->environment('production');

                    return response()->json([
                        'message'   => $debug ? $e->getMessage() : 'Server error occurred.',
                        'exception' => $debug ? get_class($e) : null,
                        'file'      => $debug ? $e->getFile() : null,
                        'line'      => $debug ? $e->getLine() : null,
                    ], 500);
                }

                return null;
            }

            // A business rule stopped a normal page request (a download, an export, ...):
            // show its message on the friendly error page, whatever APP_DEBUG says.
            // Livewire requests are handled by FriendlyErrorsServiceProvider instead.
            if (! $request->hasHeader('X-Livewire')
                && ($message = app(FriendlyExceptionPresenter::class)->domainMessage($e)) !== null) {
                (new RegisterErrorViewPaths)();

                return response()->view('errors::422', ['message' => $message], 422);
            }

            return null;
        });
    })->create();
