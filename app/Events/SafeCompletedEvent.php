<?php

namespace App\Events;

class SafeCompletedEvent extends Event
{
    public $id, $location, $user_id;
    /**
     * Create a new event instance.
     *
     * @return void
     */
    public function __construct($id, $location, $user_id)
    {
        $this->id = $id;
        $this->location = $location;
        $this->user_id = $user_id;
        
        # Log the event.
        app('log')->info(
            "Safe completed, location={$this->location}, user_id={$this->user_id}"
        );
    }
}
