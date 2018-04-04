<?php

namespace App\Providers;

use \Firebase\JWT\JWT;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        //
    }

    /**
     * Boot the authentication services for the application.
     *
     * @return void
     */
    public function boot()
    {
        // Here you may define how you wish users to be authenticated for your Lumen
        // application. The callback which receives the incoming request instance
        // should return either a User instance or null. You're free to obtain
        // the User instance via an API token or any other method necessary.
        
        $this->app['auth']->viaRequest('api', function ($request) {
            $bearer = $request->header('Authorization');
            if($bearer) {
                $bearer_sig = '/^Bearer ([a-zA-Z0-9_-]+\.[a-zA-Z0-9_-]+\.[a-zA-Z0-9_-]+)/';
                if(preg_match($bearer_sig, $bearer, $matches)) {

                    // Set the claims.
                    $JWT_string = $matches[1];
                    $claims = JWT::decode($JWT_string, env('APP_KEY'), ['HS256']);
                    config(['jwt.claims' => $claims]);

                    // Ensure device used is the same.
                    $device = md5(trim($request->header('User-Agent','Unknown')));
                    if($device == $claims->aud) return \App\User::find($claims->jti);
                }
            }
        });
    }
}
