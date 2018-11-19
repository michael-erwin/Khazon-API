<?php

namespace App\Events;

class FundTransferEvent extends Event
{
    public $sender, $receiver, $amount;
    /**
     * Create a new event instance.
     *
     * @return void
     */
    public function __construct($sender, $receiver, $amount)
    {
        $this->sender = $sender;
        $this->receiver = $receiver;
        $this->amount = $amount;
    }
}
