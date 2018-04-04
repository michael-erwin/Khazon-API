<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use \App\Libraries\Helpers;
use \App\User;

class SafesController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
    }

    public function index(Request $request)
    {
        // Restrict access.
        $restricted = Helpers::restrictAccess(['all','safe_r']);
        if($restricted) return $restricted;

        // Paginate
        $limit = $request->input('per_page', 15);
        if($limit > 500) $limit = 500; // Maximum rows limit to 500 per request.
        $safes = Safe::simplePaginate($limit);

        // Response
        return response()->json($safes);
    }
    
    public function readMine(Request $request)
    {
        #> Reference variables.
        $user = app('auth')->user();
        $chambers = \App\Chamber::where([['user_id','=',$user->id],['unlock_method','=','reg']])->orderBy('level')->get(['location','level']);
        $safes = [];
        $safes_data = [];

        #1 - Extract safes based on chambers.
        foreach($chambers as $chamber)
        {
            $safes[] = Helpers::getSafe($chamber->location);
        }
        // Replace user_id with their own data.
        foreach($safes as $safe) $safes_data[] = $this->addUserInfo($safe);

        // Response
        return response()->json($safes_data);
    }

    public function readId(Request $request, $id)
    {
        /**
         1 - Validate Input
        */

        #1.1 - Check for numeric format.
        if(!preg_match('/^[0-9]+$/', $id)) return app('api_error')->notFound();
        $user = User::find($id);

        #1.2 - Ensure user exists.
        if(!$user) return app('api_error')->notFound();

        /**
         2 - Get All Safe With User Details
        */

        #> Reference variables.
        $safes = [];
        $safes_data = [];

        #2.1 - Get all user's chambers from database.
        $chambers = \App\Chamber::where([['user_id','=',$user->id],['unlock_method','=','reg']])->orderBy('level')->get(['location']);

        #2.2 - Extract safe data based on chambers.
        foreach($chambers as $chamber) $safes[] = Helpers::getSafe($chamber->location);
        #2.2.1 - Add user details.
        foreach($safes as $safe) $safes_data[] = $this->addUserInfo($safe);

        /**
         3 - Response
        */
        return response()->json($safes_data);
    }

    public function readLocation(Request $request, $location)
    {
        /**
         1 - Validate Input
        */

        #1.1 - Check if location exist.
        $chamber = \App\Chamber::where('location',$location)->first();
        if(!$chamber) app('api_error')->notFound();

        #1.2 - Ensure user exists.
        $user = User::find($chamber->user_id);
        if(!$user) return app('api_error')->notFound();

        /**
         2 - Get All Safe With User Details
        */

        #> Reference variables.
        $safes = [];
        $safes_data = [];

        #2.1 - Get all user's chambers from database.
        $chambers = \App\Chamber::where([['user_id','=',$user->id],['unlock_method','=','reg']])->orderBy('level')->get(['location']);

        #2.2 - Extract safe data based on chambers.
        foreach($chambers as $chamber) $safes[] = Helpers::getSafe($chamber->location);
        #2.2.1 - Add user details.
        foreach($safes as $safe) $safes_data[] = $this->addUserInfo($safe);

        /**
         3 - Response
        */
        return response()->json($safes_data);
    }

    public function unlock(Request $request)
    {
        /**
         1 - Input Validation
        */
        #> Reference variables.
        $input = $request->input('cuk');
        $type = 'cuk'; // For chamber unlock type.

        #1.1 - CUK input must be present.
        if(!$input) return app('api_error')->invalidInput(['cuk' => ['Required']]);

        #1.2 - CUK must exist in database.
        $cuk = \App\Cuk::where('hash', md5($input))->first();
        if(!$cuk) return app('api_error')->invalidInput(['cuk' => ['Incorrect']]);

        #1.3 - CUK must be new.
        if(!empty($cuk->user_id)) return app('api_error')->invalidInput(['cuk' => ['Already used']]);

        /**
         2 - Update CUK table for used entry.
        */
        #> Reference variables.
        $user = app('auth')->user();

        #> Update CUK status as used by the user.
        $cuk->user_id = $user->id;
        $cuk->save();

        /**
         3. Update User Safe
        */

        #3a - Make chamber entry based on own's safe at level 1.

        #3a.1 - Determine the location of chamber to be created.
        
        #3a.1.1 - Get chamber location of parent chamber.
        #> Reference variables.
        $parent_chamber_is_level1 = true;
        $parent_chamber_location = null;
        #> Get the level 1 chamber of direct upline that was incomplete.
        $parent_chamber = \App\Chamber::where([['user_id','=',$user->id],['level','=',1],['completed','<',7]])->first();
        if(!$parent_chamber) $parent_chamber_is_level1 = false;
        
        #3a.1.1a - Only allow level 1.
        if(!$parent_chamber_is_level1) return app('api_error')->forbidden(null,'CUK unlock is applicable only to level 1 safe.');

        #3a.1.2 - Extract safe of parent based on its chamber location.
        $parent_chamber_location = (string) $parent_chamber->location;
        $parent_safe = Helpers::getSafeMap($parent_chamber_location);

        #3a.2 - Create chamber placement for self unocked user.
        #> Reference variables.
        $chamber_unlocked_location = null;
        $safe_position = null;

        #3a.2.1 - Get the location and position of first empty chamber in parent safe.
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

        #3a.2.2 - Create new chamber entry (unlock) for the user based on location found.
        $reg_user_chamber = new \App\Chamber;
        $reg_user_chamber->level = 1;
        $reg_user_chamber->user_id = $user->id;
        $reg_user_chamber->completed = 1;
        $reg_user_chamber->location = $chamber_unlocked_location;
        $reg_user_chamber->unlock_method = 'cuk';
        $reg_user_chamber->save();

        #3a.2.3 - Emit a chamber creation event.
        event(new \App\Events\ChamberCreatedEvent($chamber_unlocked_location,$safe_position));

        $success = [
            "message" => "New chamber unlocked.",
            "data" => $user,
        ];
        
        return response()->json($success, 200);
    }

    public function addUserInfo($safe)
    {
        #> Reference variables.
        $users = []; // User id as key and user info as value.
        $user_ids = [];
        $positions = ['bse','lft','rgt','llt','lmd','rmd','rlt'];
        $safe_keys = [];

        #1 - Get all user ids from safe along with mapping details.
        foreach($safe as $position => $data) if(in_array($position,$positions)) $user_ids[] = $data['user_id'];
        
        #2 - Get user data from database using ids obtained.
        $fields = ['id','name','email','address','upl_address','upl_type','regref_id'];
        $users_data = \App\User::whereIn('id',$user_ids)->get($fields);

        #3 - Populate users id=>value map.
        foreach($users_data as $user_data) $users[$user_data->id] = $user_data->toArray();
        
        #4 - Merge user data into safe.
        foreach($safe as $position => $data)
        {
            if(in_array($position,$positions))
            {
                if(isset($users[$data['user_id']])) $safe[$position]['user'] = $users[$data['user_id']];
            }
        }

        #5 - Return output.
        return $safe;
    }
}
