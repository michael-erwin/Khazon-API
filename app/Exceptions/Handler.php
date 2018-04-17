<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Validation\ValidationException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Laravel\Lumen\Exceptions\Handler as ExceptionHandler;
use Symfony\Component\HttpKernel\Exception\HttpException;
// use Bugsnag\BugsnagLaravel\Facades\Bugsnag;

class Handler extends ExceptionHandler
{
    /**
     * A list of the exception types that should not be reported.
     *
     * @var array
     */
    protected $dontReport = [
        AuthorizationException::class,
        HttpException::class,
        ModelNotFoundException::class,
        ValidationException::class,
    ];

    /**
     * Report or log an exception.
     *
     * This is a great spot to send exceptions to Sentry, Bugsnag, etc.
     *
     * @param  \Exception  $e
     * @return void
     */
    public function report(Exception $e)
    {
        switch ($e) {
            case $e instanceof \Firebase\JWT\ExpiredException:
                return null;
                break;
            default:
                parent::report($e);
                // Bugsnag::notifyException(new RuntimeException("Test error"));                
        }
    }

    /**
     * Render an exception into an HTTP response.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Exception  $e
     * @return \Illuminate\Http\Response
     */
    public function render($request, Exception $e)
    {
        switch ($e) {
            case $e instanceof \Firebase\JWT\ExpiredException:
                return app('api_error')->unauthorized(null,"Token has expired.");
                break;
            case $e instanceof \DomainException:
                return app('api_error')->badRequest();
                break;
            case $e instanceof \Firebase\JWT\SignatureInvalidException:
                return app('api_error')->unauthorized(null,"Invalid token signature.");
                break;
            // case $e instanceof \Illuminate\Database\QueryException:
            //     return app('api_error')->serverError(null,"Failed to update database.");
            //     break;
            default:
                return (env('APP_ENV') == 'local')? parent::render($request, $e) : app('api_error')->badRequest();
        }
    }
}
