<?php

namespace App\Events;

class EmailVerifyEvent extends Event
{
    public $email, $otp, $otp_exp;
    /**
     * Create a new event instance.
     *
     * @return void
     */
    public function __construct($email, $otp, $otp_exp)
    {
        $this->email = $email;
        $this->otp = $otp;
        $this->otp_exp = $otp_exp;
    }
}
