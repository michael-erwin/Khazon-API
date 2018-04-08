<?php

namespace App\Http\Controllers;

use App\User;
use App\Libraries\Helpers;
use Illuminate\Http\Request;

class UsersController extends Controller
{
    public function __construct()
    {
        // $this->middleware('auth', ['except' => 'generate']);
    }

    public function index(Request $request)
    {
        // Restrict access.
        $restricted = Helpers::restrictAccess(['all','users_r']);
        if($restricted) return $restricted;

        // Process request.
        $search = trim($request->input('search'));
        $where = [];
        if(strlen($search))
        {
            $where = function($query) use($search) {
                $query->where('name','like','%'.$search.'%')
                      ->orWhere('email','like','%'.$search.'%')
                      ->orWhere('address','like','%'.$search.'%');
            };
        }
        $limit = $request->input('per_page', 15);
        if($limit > 500) $limit = 500; // Maximum rows limit to 500 per request.
        $users = User::where($where)->paginate($limit);

        // Response
        return response()->json($users);
    }

    public function readId(Request $request, $id)
    {
        $fields = ['id','role_id', 'name','email','address','upl_address as mounting_address','upl_type as mounting_type',
                    'regref_id as guardian_id','created_at'];
        $user = User::where('id', $id)->select($fields)->first();
        
        if (!$user) return app('api_error')->notFound();
        if ($user->role_id == 1) return app('api_error')->notFound();

        $user->guardian_address = "";
        if($user->mounting_type != "auto" && is_numeric($user->guardian_id))
        {
            $guardian = User::where('id', $user->guardian_id)->select(['address'])->first();
            if($guardian->address)
            { $user->guardian_address = $guardian->address; }
            else
            { $user->guardian_address = ""; }
        }
        $user->setHidden(['role_id']);

        // Response
        return response()->json($user);
    }

    public function readAddress(Request $request, $address)
    {
        $fields = ['id','name','email','address','upl_address as mounting_address','upl_type as mounting_type',
                    'regref_id as guardian_id','created_at'];
        $user = User::where('address', $address)->select($fields)->first();
        
        if (!$user) return app('api_error')->notFound();
        if ($user->role_id == 1) return app('api_error')->notFound();

        $user->guardian_address = "";
        if($user->mounting_type != "auto" && is_numeric($user->guardian_id))
        {
            $guardian = User::where('id', $user->guardian_id)->select(['address'])->first();
            if($guardian->address)
            { $user->guardian_address = $guardian->address; }
            else
            { $user->guardian_address = ""; }
        }
        $user->setHidden(['role_id']);

        // Response
        return response()->json($user);
    }

    public function generate(Request $request, \Faker\Generator $faker)
    {
        $amount = $request->input('amount',15);
        $users  = [];

        for($i=0;$i<$amount;$i++)
        {
            $fname = $faker->firstName;
            $lname = $faker->lastName;
            $domain = $faker->freeEmailDomain;
            $users[] = [
                'name' => "{$fname} {$lname}",
                'email' => strtolower("{$fname}.{$lname}@{$domain}"),
                'password' => 'password123',
                'address' => '0x'.sha1(microtime())
            ];
        }
        
        return response()->json($users);
    }
}
