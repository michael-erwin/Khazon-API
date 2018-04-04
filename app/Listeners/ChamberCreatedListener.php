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
    { }

    /**
     * Handle the event.
     *
     * @param  object  $chamber  Event that has properties 'location' and 'safe_position'.
     * @return void
     */
    public function handle(\App\Events\ChamberCreatedEvent $chamber)
    {
        #> Reference variables.
        $logger = app('log');
        $layer2_chambers = ['lft','rgt'];
        $layer3_chambers = ['llt','lmd','rmd','rlt'];
        $coords = explode('.',$chamber->location);
        $row = $coords[0]; $col = $coords[1];

        /**
         Logic of Unlocked Chamber
         1.) If unlocked chamber is found on layer 3, update completed status of chamber on layer 2 and layer 1
             of current safe (upline of created chamber).
         2.) If unlocked chamber is found on layer 2, update completed status of chamber on layer 1 and layer 1
             of overlapped safe (upline of upline).
         */

        #1 - For layer 3.
        if(in_array($chamber->safe_position,$layer3_chambers))
        {
            #1.1 - Update chamber on layer 2.
            $chamber_layer2 = null;
            $chamber_layer2_coord = Helpers::getLowerChamberCoords($chamber->location);
            if($chamber_layer2_coord)
            {
                $chamber_layer2 = \App\Chamber::where('location',$chamber_layer2_coord)->first();
                $chamber_layer2->completed = $chamber_layer2->completed + 1;
                $chamber_layer2->save();
            }

            #1.2 - Update chamber on layer 1.
            $chamber_layer1 = null;
            if($chamber_layer2_coord)
            {
                $chamber_layer1_coord = Helpers::getLowerChamberCoords($chamber_layer2_coord);
                $chamber_layer1 = \App\Chamber::where('location',$chamber_layer1_coord)->first();
                $chamber_layer1->completed = $chamber_layer1->completed + 1;
                $chamber_layer1->save();
                #> Emit safe completion event passing layer 1 chamber info.
                if($chamber_layer1->completed == 7) event(new \App\Events\SafeCompletedEvent($chamber_layer1));
            }
        }
        #2 - For layer 2.
        elseif(in_array($chamber->safe_position,$layer2_chambers))
        {
            #2.1 - Update chamber on layer 1.
            $chamber_layer1 = null;
            $chamber_layer1_coord = Helpers::getLowerChamberCoords($chamber->location);
            if($chamber_layer1_coord)
            {
                $chamber_layer1 = \App\Chamber::where('location',$chamber_layer1_coord)->first();
                $chamber_layer1->completed = $chamber_layer1->completed + 1;
                $chamber_layer1->save();
            }

            #2.2 - Update chamber on layer 1 of overlapped safe.
            $lower_safe_chamber = null;
            if($chamber_layer1_coord)
            {
                $lower_safe_chamber_coord = Helpers::getLowerChamberCoords($chamber_layer1_coord);
                if($lower_safe_chamber_coord)
                {
                    $lower_safe_chamber = \App\Chamber::where('location',$lower_safe_chamber_coord)->first();
                    $lower_safe_chamber->completed = $lower_safe_chamber->completed + 1;
                    $lower_safe_chamber->save();
                    #> Emit safe completion event passing layer 1 chamber info.
                    if($lower_safe_chamber->completed == 7) event(new \App\Events\SafeCompletedEvent($lower_safe_chamber));
                }
            }
        }
        #3 - Atypical or unexpected result.
        else
        {
            $logger->info(static::class.' - No other chambers found below currently unlocked chamber.');
        }
    }
}
