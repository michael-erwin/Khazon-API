<?php

namespace App\Http\Controllers;

use App\User;
use App\Libraries\Helpers;
use Illuminate\Http\Request;

class UsersController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
    }

    public function index(Request $request)
    {
        // Restrict access.
        $restricted = Helpers::restrictAccess(['all','users_r']);
        if($restricted) return $restricted;

        // Filter isolate
        $input_filter_field = trim($request->input('filter_field'));
        $input_filter_value = trim($request->input('filter_value'));
        $input_order_by = $request->input('order_by', 'id');
        $input_sort_as = $request->input('sort_as', 'asc');

        $filters_allowed = ['has_2fa', 'has_cqa', 'active'];
        $orders_allowed = ['id','name','email','balance','created_at','updated_at'];
        $filter_field = in_array($input_filter_field, $filters_allowed) ? $input_filter_field : null;
        $order_by = in_array($input_order_by, $orders_allowed) ? $input_order_by : 'id';
        $sort_as = in_array($input_sort_as, ['asc', 'desc']) ? $input_sort_as : 'asc';

        // Search
        $search = trim(strtolower($request->input('search')));
        $field_search = $this->getSearchFieldName($search);
        
        // Build query
        $where = function($query) use($search, $field_search, $filter_field, $input_filter_value)
        {
            if (strlen($search) > 0) $query->where($field_search,'like','%'.$search.'%');
            if ($filter_field) $query->where($filter_field, $input_filter_value);
        };
        $limit = $request->input('per_page', 15);
        if ($limit > 500) $limit = 500; // Maximum rows limit to 500 per request.

        if ($input_order_by === 'balance')
        {
            $query = "CAST(`balance` AS DECIMAL(10,4)) ".strtoupper($sort_as);
            $users = User::where($where)->orderByRaw($query)->paginate($limit);
        }
        else
        {
            $users = User::where($where)->orderBy($order_by, $sort_as)->paginate($limit);
        }

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

    public function update(Request $request, $id)
    {
        $inputs = $request->only(['name', 'email', 'address', 'active']);

        // Restric access.
        $restricted = \App\Libraries\Helpers::restrictAccess(['all','users_u']);
        if($restricted) return $restricted;

        // User must exist.
        $user = User::find($id);
        if (!$user) return app('api_error')->notFound();

        // Require at least 1 field.
        if (count($inputs) == 0) return app('api_error')->badRequest();

        // Validation rules.
        $input_rules = [
            'name' => 'min:5',
            'email' => 'email',
            'address' => 'regex:/^0x[a-z0-9]{40}$/i',
            'active' => 'boolean'
        ];
        $input_errors = [
            'name.min' => 'Too short',
            'email.email' => 'Format is invalid',
            'active.boolean' => 'Must be boolean',
        ];

        // Apply validations.
        $validation = app('validator')->make($inputs, $input_rules, $input_errors);
        if($validation->fails())
        {
            $errors = $validation->errors()->messages();
            return app('api_error')->invalidInput($errors);
        } 

        // Update record.
        foreach ($inputs as $field => $value) $user->$field = $value;
        $user->save();

        // Response
        return response()->json([ 'status' => 'SUCCESS', 'data' => $user ]);
    }

    private function getSearchFieldName($keyword) {
        $field_name = 'name';
        if (preg_match('/^0x[a-f0-9]{40}/i', $keyword))
        {
            $field_name = 'address';
        }
        elseif(preg_match('/@/', $keyword))
        {
            $field_name = 'email';
        }
        return $field_name;
    }
}
