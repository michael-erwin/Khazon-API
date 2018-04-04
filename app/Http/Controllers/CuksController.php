<?php

namespace App\Http\Controllers;

use App\Cuk;
use App\Libraries\Helpers;
use App\Libraries\PlainTea;
use Illuminate\Http\Request;

class CuksController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
    }

    public function index(Request $request)
    {
        // Restrict access.
        $restricted = Helpers::restrictAccess(['all','cuk_r']);
        if($restricted) return $restricted;

        // Process result.
        $where = [];
        $type = $request->input('type');
        if(in_array($type, ['used', 'unused']))
        {
            if($type == 'used')
            {
                $where = function($query) {
                    $query->whereNotNull('user_id');
                };
            }
            if($type == 'unused')
            {
                $where = function($query) {
                    $query->whereNull('user_id');
                };
            }
        }
        $limit = $request->input('per_page', 15);
        if($limit > 500) $limit = 500; // Maximum rows limit to 500 per request.
        $cuks = Cuk::where($where)->paginate($limit);

        // Decrypt encrypted CUK codes.
        $cuks->getCollection()->transform(function($item,$key){
            return [
                'id' => $item->id,
                'code' => PlainTea::decrypt($item->code, env('APP_KEY')),
                'user_id' => $item->user_id,
                'created_at' => $item->created_at,
                'updated_at' => $item->updated_at,
            ];
        });

        // // Response
        return response()->json($cuks);
    }

    public function create(Request $request)
    {
        // Restrict access.
        $restricted = Helpers::restrictAccess(['all','cuks_c']);
        if($restricted) return $restricted;

        // Validate input.
        $inputs = $request->all();
        $validation = app('validator')->make($inputs,[
            'items' => 'required|numeric|min:1'
        ]);

        if($validation->fails()) return app('api_error')->invalidInput($validation->errors(),"Check validation data.");

        // Generate keys
        $plain_codes = Helpers::genCUK($inputs['items']);

        // Insert keys
        $cuk_items = [];
        foreach($plain_codes as $code)
        {
            $cuk_items[] = [
                'code' => plainTea::encrypt($code, env('APP_KEY')),
                'hash' => md5($code),
                'user_id' => null,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s')
            ];
        }
        Cuk::insert($cuk_items);

        // Response
        return response()->json(['message'=>'Success','data'=>['count'=>count($plain_codes)]]);
    }

    public function update(Request $request, int $id)
    {
        // Restrict access.
        $restricted = Helpers::restrictAccess(['all','cuk_u']);
        if($restricted) return $restricted;

        // $limit = $request->input('per_page', 15);
        // if($limit > 500) $limit = 500; // Maximum rows limit to 500 per request.
        // $cuks = Cuk::paginate($limit);

        // // Response
        // return response()->json($cuks);
        return "Update CUK";
    }
}
