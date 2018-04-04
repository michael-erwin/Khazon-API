<?php

namespace App\Events;

class ChamberCreatedEvent extends Event
{
    public $location = '0.0.0';
    public $safe_position = 'unknown';

    /**
     * Create a new event instance.
     *
     * @return void
     */
    public function __construct($location,$safe_position)
    {
        $this->location = $location;
        $this->safe_position = $safe_position;

        // app('log')->info("Chamber created, location={$this->location}, position={$this->safe_position}");
    }
}
