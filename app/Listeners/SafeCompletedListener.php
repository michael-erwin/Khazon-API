<?php

namespace App\Listeners;

use \App\Libraries\Helpers;

class SafeCompletedListener
{
    /**
     * Create the event listener.
     *
     * @return void
     */
    public function __construct()
    {
        //
    }

    /**
     * Handle the event.
     *
     * @param  object  $chamber  Eloquent object.
     * @return void
     */
    public function handle(\App\Events\SafeCompletedEvent $chamber)
    {
        #> Reference variable.
        $id = $chamber->data->id;
        $level = $chamber->data->level;
        $location = $chamber->data->location;
        $user_id = $chamber->data->user_id;

        if($level < 7) // Restrict level up to 7 only.
        {
            /**
             1 - Apply Safe Completed Earnings
            */
            app('db')->transaction(function() use($id, $level, $user_id) {
                // Earnings table entry.
                $kta_amt = \App\Libraries\Helpers::computeSafeEarnings($level);
                $safe_earning = new \App\Transaction;
                $safe_earning->user_id = $user_id;
                $safe_earning->kta_amt = $kta_amt;
                $safe_earning->code = 'safe';
                $safe_earning->ref = $id;
                $safe_earning->type = 'cr';
                $safe_earning->complete = 1;
                $safe_earning->save();
                // Update affected user's balance.
                $user = \App\User::where('id', $user_id)->select(['id','balance'])->first();
                $user->balance += $kta_amt;
                $user->save();
            }, 5);

            /**
             2 - Create Next Level Safe
            */

            #> Initialize reference values.
            $parent_chamber = null; // Chamber for next level where new unlock will be made on its safe.
            $parent_chamber_location = null;
            $next_level = $level + 1;

            #2.1 - Determine suitable parent safe found in the next level.
            $parent_chamber = \App\Chamber::where([['level','=',$next_level],['completed','<',7]])->orderBy('id','asc')->first();

            #2.2a - Attach to top of base chamber.
            if($parent_chamber)
            {
                $parent_chamber_location = $parent_chamber->location;
                $parent_safe = Helpers::getSafeMap($parent_chamber->location);

                #2.2a.1 - Create next level chamber placement for the completed user safe.
                #> Reference variables.
                $chamber_unlocked_location = null;
                $safe_position = null;

                #2.2a.1.1 - Get the location and position of first empty chamber in parent safe.
                foreach($parent_safe as $position => $chamber)
                {
                    if($chamber['data'] === null)
                    {
                        if($chamber_unlocked_location === null)
                        {
                            $chamber_unlocked_location = $chamber['location'];
                            $safe_position = $position;
                            break;
                        }
                    }
                }

                #2.2a.1.2 - Create new next level chamber for a completed safe.
                $chamber_new = new \App\Chamber;
                $chamber_new->location = $chamber_unlocked_location;
                $chamber_new->level = $next_level;
                $chamber_new->user_id = $user_id;
                $chamber_new->unlock_method = 'reg';
                $chamber_new->completed = 1;
                $chamber_new->save();

                #2.2a.1.3 - Emit a chamber creation event.
                event(new \App\Events\ChamberCreatedEvent($chamber_unlocked_location,$safe_position));
            }
            #2.2b - Create genesis entry in next level.
            else
            {
                $chamber_new = new \App\Chamber;
                $chamber_new->location = $next_level.'.1.1';
                $chamber_new->level = $next_level;
                $chamber_new->user_id = $user_id;
                $chamber_new->unlock_method = 'reg';
                $chamber_new->completed = 1;
                $chamber_new->save();
            }
        }
    }
}
