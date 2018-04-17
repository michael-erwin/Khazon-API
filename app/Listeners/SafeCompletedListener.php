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
        $id = $chamber->id;
        $level = explode('.', $chamber->location)[0];
        $location = $chamber->location;
        $user_id = $chamber->user_id;

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
            $next_level = $level + 1;

            #2.1 - Determine suitable parent safe found in the next level.
            $parent_chamber = \App\Chamber::where([
                    ['level','=',$next_level],
                    ['completed','<',7]
                ])->orderBy('id','asc')->first();

            #2.2a - Attach to top of base chamber.
            if($parent_chamber)
            {
                app('log')->info(
                    "Parent chamber found, location={$parent_chamber->location}, completed={$parent_chamber->completed}".
                    " for subject (location={$location}, user_id={$user_id})"
                );

                $parent_safe = Helpers::getSafeMap($parent_chamber->location);
                
                #2.2a.1 - Create next level chamber placement for the completed user safe.
                #> Reference variables.
                $chamber_unlocked_location = null;

                #2.2a.1.1 - Get the location and position of first empty chamber in parent safe.
                foreach($parent_safe as $position => $chamber)
                {
                    if(!$chamber['data'])
                    {
                        if(!$chamber_unlocked_location)
                        {
                            $chamber_unlocked_location = $chamber['location'];
                            break;
                        }
                    }
                }

                if($chamber_unlocked_location)
                {
                    #2.2a.1.2 - Create new next level chamber for a completed safe.
                    $chamber_new_lvl = new \App\Chamber;
                    $chamber_new_lvl->location = $chamber_unlocked_location;
                    $chamber_new_lvl->level = $next_level;
                    $chamber_new_lvl->user_id = $user_id;
                    $chamber_new_lvl->unlock_method = 'reg';
                    $chamber_new_lvl->completed = 1;
                    $chamber_new_lvl->save();

                    #2.2a.1.3 - Emit a chamber creation event.
                    event(new \App\Events\ChamberCreatedEvent($chamber_unlocked_location,$user_id));
                }
                else
                {
                    app('log')->warn(
                        "Assembled safe not empty for parent chamber on location={$parent_chamber->location}, 
                        completed={$parent_chamber->completed}"
                    );
                }
            }
            #2.2b - Create genesis entry in next level.
            else
            {
                $chamber_new_lvl = new \App\Chamber;
                $chamber_new_lvl->location = $next_level.'.1.1';
                $chamber_new_lvl->level = $next_level;
                $chamber_new_lvl->user_id = $user_id;
                $chamber_new_lvl->unlock_method = 'reg';
                $chamber_new_lvl->completed = 1;
                $chamber_new_lvl->save();

                # Log the event.
                app('log')->info(
                    "Chamber created, location={$chamber_new_lvl->location}, user_id={$user_id}"
                );
            }
        }
    }
}
