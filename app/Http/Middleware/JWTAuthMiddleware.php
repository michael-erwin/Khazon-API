<?php

namespace App\Http\Middleware;

use Closure;

class JWTAuthMiddleware
{

    /**
     * Create a new middleware instance.
     *
     * @param  \Illuminate\Contracts\Auth\Factory  $auth
     * @return void
     */
    public function __construct()
    {}

    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @param  string|null  $guard
     * @return mixed
     */
    public function handle($request, Closure $next, $guard = null)
    {
        // Prevent unauthenticated request.
        if (app('auth')->guard('api')->guest()) return app('api_error')->unauthorized();

        // Impose 2FA if present.
        $jwt_claims = config('jwt.claims');
        if(isset($jwt_claims->otp)) return app('api_error')->locked(null,'OTP is required.');

        // Automatically issue new token as "Access-Token" header when
        // current token is about to expire.
        $response = $next($request);
        if(($jwt_claims->exp - env('JWT_REFRESH_AHEAD', 900)) < time())  // Default to 15min.
        {
            $jwt_claims->exp = isset($jwt_claims->rem)? time()+env('JWT_DURATION_LONG', 604800) : time()+env('JWT_DURATION', 7200);
            $payload = (array) $jwt_claims;
            $jwt = \Firebase\JWT\JWT::encode($payload, env('APP_KEY'), 'HS256');
            $response->header('Access-Token', $jwt);
        }

        // Response
        return $response;
    }
}
