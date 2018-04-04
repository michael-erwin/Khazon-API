<?php

namespace App\Libraries;

class Helpers
{
    /**
     * Generate Chamber Unlocking Key (CUK)
     * 
     * @param   int          $count
     * @return  string|array 
     */
    public static function genCUK(int $count=null)
    {
        $cuks = [];
        $secret = env('APP_KEY');
        
        if(!$count)
        {
            $key_raw = strtoupper(md5($secret.microtime()));
            $key_parts = [];
            for($i=0;$i<strlen($key_raw)-7;$i+=5) $key_parts[] = substr($key_raw,$i,5);
            return implode('-',$key_parts);
        }

        for($i=0; $i<$count; $i++)
        {
            $key_raw = strtoupper(md5($secret.microtime()));
            $key_parts = [];
            for($n=0;$n<strlen($key_raw)-7;$n+=5) $key_parts[] = substr($key_raw,$n,5);
            $cuks[$i] = implode('-',$key_parts);
        }
        return $cuks;
    }

    /**
     * Extract safe data based on location.
     * 
     * @param   string  $location  Base of safe (bse) as dotted numeric notation as (level).(row).(col).
     * @return  array   Array containing keys as positions bse,llt etc. and value as array containing data
     *                  for id,user_id,location & unlock_method.
     */
    public static function getSafe(string $location)
    {
        # Define safe object.
        $safe = ['bse' => null, 'lft' => null, 'rgt' => null, 'llt' => null, 'lmd' => null, 'rmd' => null, 'rlt' => null];
        $chamber_locations = $safe;
        # Get base row and column.
        $coords = explode('.',$location);
        $bse_lvl = $coords[0];
        $bse_row = $coords[1];
        $bse_col = $coords[2];
        # Layer 1 location.
        $chamber_locations['bse'] = $location;
        # Get layer 2 locations.
        $layer_2l = ['max_row' => $bse_row + 1, 'max_col' => $bse_col * 2];
        $chamber_locations['lft'] = $bse_lvl.'.'.$layer_2l['max_row'].'.'.($layer_2l['max_col'] - 1);
        $chamber_locations['rgt'] = $bse_lvl.'.'.$layer_2l['max_row'].'.'.$layer_2l['max_col'];
        # Get layer 3 locations.
        $layer_3l = ['max_row' => $bse_row + 2, 'max_col' => ($bse_col * 2) * 2];
        $chamber_locations['llt'] = $bse_lvl.'.'.$layer_3l['max_row'].'.'.($layer_3l['max_col'] - 3);
        $chamber_locations['lmd'] = $bse_lvl.'.'.$layer_3l['max_row'].'.'.($layer_3l['max_col'] - 2);
        $chamber_locations['rmd'] = $bse_lvl.'.'.$layer_3l['max_row'].'.'.($layer_3l['max_col'] - 1);
        $chamber_locations['rlt'] = $bse_lvl.'.'.$layer_3l['max_row'].'.'.$layer_3l['max_col'];
        # Build items query.
        $locations = [];
        foreach($chamber_locations as $chamber_location) $locations[] = $chamber_location;
        # Get chambers data from database.
        $chambers = \App\Chamber::whereIn('location',$locations)->orderBy('id')->get();
        # Update safe values with data gathered.
        foreach($chamber_locations as $position => $location)
        {
            foreach($chambers as $chamber)
            {
                if($location == $chamber->location)
                {
                    if($position == 'bse')
                    {
                        $safe['level'] = $chamber->level;
                        $safe['location'] = $chamber->location;
                        $safe['completed'] = $chamber->completed;
                        $safe['created_at'] = $chamber->created_at;
                        $safe['updated_at'] = $chamber->updated_at;
                    }
                    $safe[$position] = [
                        'id' => $chamber->id,
                        'user_id' => $chamber->user_id,
                        'location' => $chamber->location,
                        'unlock_method' => $chamber->unlock_method
                    ];
                }
            }
        }

        return $safe;
    }

    /**
     * Extract safe mappings with data.
     * 
     * @param   string  $location  Base of safe (bse) as dotted numeric notation as (level).(row).(col).
     * @return  array   Array containing keys as positions bse,llt etc. and value as array containing
     *                  keys 'location' and 'data' containing values for id,level,user_id,completed,
     *                  location,unlock_method, and timestamps.
     */
    public static function getSafeMap(string $location)
    {
        # Define safe object.
        $safe = [
            'bse' => ['location'=>null,'data'=>null],
            'lft' => ['location'=>null,'data'=>null],
            'rgt' => ['location'=>null,'data'=>null],
            'llt' => ['location'=>null,'data'=>null],
            'lmd' => ['location'=>null,'data'=>null],
            'rmd' => ['location'=>null,'data'=>null],
            'rlt' => ['location'=>null,'data'=>null]
        ];
        # Get base row and column.
        $coords = explode('.',$location);
        $bse_lvl = $coords[0];
        $bse_row = $coords[1];
        $bse_col = $coords[2];
        # Layer 1 defaults.
        $safe['bse']['location'] = $location;
        # Get layer 2 locations.
        $layer_2l = [
            'max_row' => $bse_row + 1,
            'max_col' => $bse_col * 2
        ];
        $safe['lft']['location'] = $bse_lvl.'.'.$layer_2l['max_row'].'.'.($layer_2l['max_col'] - 1);
        $safe['rgt']['location'] = $bse_lvl.'.'.$layer_2l['max_row'].'.'.$layer_2l['max_col'];
        # Get layer 3 locations.
        $layer_3l = [
            'max_row' => $bse_row + 2,
            'max_col' => ($bse_col * 2) * 2
        ];
        $safe['llt']['location'] = $bse_lvl.'.'.$layer_3l['max_row'].'.'.($layer_3l['max_col'] - 3);
        $safe['lmd']['location'] = $bse_lvl.'.'.$layer_3l['max_row'].'.'.($layer_3l['max_col'] - 2);
        $safe['rmd']['location'] = $bse_lvl.'.'.$layer_3l['max_row'].'.'.($layer_3l['max_col'] - 1);
        $safe['rlt']['location'] = $bse_lvl.'.'.$layer_3l['max_row'].'.'.$layer_3l['max_col'];
        # Build items query.
        $locations = [];
        foreach($safe as $chamber) $locations[] = $chamber['location'];
        # Get chambers data from database.
        $chambers = \App\Chamber::whereIn('location',$locations)->get();
        foreach($chambers as $chamber)
        {
            foreach($safe as $position => $safe_chamber)
            {
                if($safe_chamber['location'] == $chamber->location) $safe[$position]['data'] = $chamber->toArray();
            }
        }

        return $safe;
    }

    /**
     * Get location (coordinate) of chamber found at the lower row of the given chamber location.
     * 
     * @param   string  $location  Dotted numeric notation as (level).(row).(col).
     * @return  string  Dotted numeric notation ie. 1.2.2.
     */
    public static function getLowerChamberCoords($location)
    {
        #> Reference variables.
        $coords = explode('.',$location);
        $lvl = $coords[0];
        $row = $coords[1];
        $col = $coords[2];
        $base_location = null;

        if($row > 1)
        {
            #1 - Determine chamber position relative to safe as left(odd) or right(even).
            $remainder = $col % 2;

            #2 - Extract base location.
            $new_row = $row - 1;
            #2.1a - Location for odd.
            if($remainder > 0)
            {
                $new_col = ($col + 1) / 2;
                $base_location = $lvl.'.'.$new_row.'.'.$new_col;
            }
            #2.1b - Location for even.
            else
            {
                $new_col = $col / 2;
                $base_location = $lvl.'.'.$new_row.'.'.$new_col;
            }
            return $base_location;
        } else {return false; }
    }
    /**
     * Get coordinate location of base (layer 1 of safe).
     * 
     * @param   string  $location  Dotted numeric notation as (level).(row).(col).
     * @return  string  Dotted numeric notation ie. 1.2.2.
     */
    public static function getBaseChamberCoords(string $location)
    {   
        $row = explode('.',$location); $row = $row[1];
        if($row > 2)
        {
            return self::getLowerChamberCoords(self::getLowerChamberCoords($location));
        } else { return false; }
    }

    /**
     * Generate Chamber Unlocking Key (CUK)
     * 
     * @param   array                             $ctrl_permissions
     * @return  boolean|Illuminate\Http\Response
     */
    public static function restrictAccess(array $ctrl_permissions)
    {
        $user_permissions  = app('auth')->user()->role->permissions;
        $user_permissions  = explode(",", $user_permissions);
        $permitted_actions = array_intersect($ctrl_permissions, $user_permissions);
        if(count($permitted_actions) == 0) return app('api_error')->forbidden();
    }

    /**
     * Compute KTA earnings based on safe count.
     * @param   int    $count
     * @return  float
     */
    public static function computeSafeEarnings(int $count=1)
    {
        $earning_safe = config('general.earning_safe');
        $safe_level = $count % 7;
        if($safe_level == 0) $safe_level = 7;
        return $earning_safe[$safe_level];
    }
}