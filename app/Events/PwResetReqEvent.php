<?php

namespace App\Events;

class PwResetReqEvent extends Event
{
    public $email;
    public $token;
    /**
     * Create a new event instance.
     *
     * @return void
     */
    public function __construct($email, $token)
    {
        $this->email = $email;
        $this->token = $token;
    }
}
