<?php

namespace App\Events;

class ChamberCreatedEvent extends Event
{
    public $location = '0.0.0';
    public $user_id = 'unknown';

    /**
     * Create a new event instance.
     *
     * @return void
     */
    public function __construct($location,$user_id)
    {
        $this->location = $location;
        $this->user_id = $user_id;

        # Log the event.
        app('log')->info(
            "Chamber created, location={$this->location}, user_id={$this->user_id}"
        );
    }
}
