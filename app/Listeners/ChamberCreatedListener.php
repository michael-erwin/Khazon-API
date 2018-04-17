<?php

namespace App\Listeners;

use App\Events\name;
use \App\Libraries\Helpers;

class ChamberCreatedListener
{
    /**
     * Create the event listener.
     *
     * @return void
     */
    public function __construct()
    {  }

    /**
     * Handle the event.
     *
     * @param  object  $chamber  Event that has properties 'location' and 'safe_position'.
     * @return void
     */
    public function handle(\App\Events\ChamberCreatedEvent $chamber)
    {
        /* Argument $chamber properties
           +-----------------------------------------------------------------------------------------+
           | location - The Chamber location that was unlocked. ex: "2.2.1".                         |
           +-----------------------------------------------------------------------------------------+
        */

        #> Reference variables.
        $parent_chamber_lvl_1 = null;
        $parent_chamber_lvl_2 = null;
        $parent_chamber_lvl_1_location = null;
        $parent_chamber_lvl_2_location = null;

        /**
         Logic of Unlocked Chamber
         1. Every chamber unlock will update completed status of two other chambers below it. Those two other chambers
            needs to be updated because their safe can see the current chamber being unlock. They will be referred to
            'parent_chamber_lvl_1' and 'parent_chamber_lvl_2' in relation to it's proximity to the subject chamber.
         2. Only update when applicable as parent location coordinates should exist otherwise subject chamber itself is
            a genesis entry or one of its parent(s) is a genesis.
         3. When completed field reaches 7, emit SafeCompletedEvent($parent_chamber_location_here);
         4. Only at parent_chamber_lvl_2 stage can only trigger safe completion event since subject chamber is in its
            Safe's layer 4.
         */

        #1 - Update 'parent_chamber_lvl_1'.
        $parent_chamber_lvl_1_location = Helpers::getLowerChamberCoords($chamber->location);
        if($parent_chamber_lvl_1_location)
        {
            $parent_chamber_lvl_1 = \App\Chamber::where('location',$parent_chamber_lvl_1_location)->first();
            if($parent_chamber_lvl_1)
            {
                $parent_chamber_lvl_1->completed += 1;
                $parent_chamber_lvl_1->save();
            }
            else
            {
                app('log')->warn('Record of chamber at '.$parent_chamber_lvl_1_location.' was not found.');
            }
        }

        #2 - Update 'parent_chamber_lvl_2'.
        if($parent_chamber_lvl_1)
        {
            $parent_chamber_lvl_2_location = Helpers::getLowerChamberCoords($parent_chamber_lvl_1_location);
            if($parent_chamber_lvl_2_location)
            {
                $parent_chamber_lvl_2 = \App\Chamber::where('location',$parent_chamber_lvl_2_location)->first();
                if($parent_chamber_lvl_2)
                {
                    $parent_chamber_lvl_2->completed += 1;
                    $parent_chamber_lvl_2->save();
                    if($parent_chamber_lvl_2->completed == 7)
                    {
                        event(new \App\Events\SafeCompletedEvent(
                            $parent_chamber_lvl_2->id,
                            $parent_chamber_lvl_2->location,
                            $parent_chamber_lvl_2->user_id
                        ));
                    }
                }
                else
                {
                    app('log')->warn('Record of chamber at '.$parent_chamber_lvl_1_location.' was not found.');
                }
            }
        }
    }
}
