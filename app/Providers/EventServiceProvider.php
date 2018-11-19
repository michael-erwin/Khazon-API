<?php

namespace App\Providers;

use Laravel\Lumen\Providers\EventServiceProvider as ServiceProvider;

class EventServiceProvider extends ServiceProvider
{
    /**
     * The event listener mappings for the application.
     *
     * @var array
     */
    protected $listen = [
        'App\Events\ChamberCreatedEvent' => [
            'App\Listeners\ChamberCreatedListener',
        ],
        'App\Events\SafeCompletedEvent' => [
            'App\Listeners\SafeCompletedListener',
        ],
        'App\Events\PwResetReqEvent' => [
            'App\Listeners\PwResetReqListener',
        ],
        'App\Events\EmailVerifyEvent' => [
            'App\Listeners\EmailVerifyListener',
        ],
        'App\Events\FundTransferEvent' => [
            'App\Listeners\FundTransferListener',
        ],
    ];
}
