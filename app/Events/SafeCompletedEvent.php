<?php

namespace App\Events;

class SafeCompletedEvent extends Event
{
    public $data;
    /**
     * Create a new event instance.
     *
     * @return void
     */
    public function __construct($chamber)
    {
        $this->data = $chamber;
    }
}
