<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class ReferralsController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
    }

    public function index()
    {}

    public function readMine(Request $request)
    {
        $user = app('auth')->user();
        $limit = $request->input('per_page', 15);
        $date_from = $request->input('date_from');
        $date_upto = $request->input('date_to');
        $search = $request->input('search');
        $where = [['user_id','=',$user->id]];
        $or = [];
        if($search)
        {
            $or = function($query) use($search) {
                $query->where('users.name','like','%'.$search.'%')
                      ->orWhere('users.email','like','%'.$search.'%')
                      ->orWhere('users.address','like','%'.$search.'%');
            };
        }
        if(preg_match('/\d{4}\-\d{2}\-\d{2}/', $date_from))
        {
            $where[] = ['referrals.created_at', '>=', $date_from.' 00:00:00'];

            if(preg_match('/\d{4}\-\d{2}\-\d{2}/', $date_upto))
            {
                $where[] = ['referrals.created_at', '<=', $date_upto.' 23:59:59'];
            }
        }
        $referrals = app('db')->table('referrals')
                    ->join('users','users.id','=','referrals.user_reg_id')
                    ->select([
                        'referrals.id',
                        'referrals.type',
                        'referrals.created_at',
                        'referrals.updated_at',
                        'users.name',
                        'users.email',
                        'users.address'])
                    ->where($where)
                    ->where($or)
                    ->paginate($limit);

        // Response
        return response()->json($referrals, 200);
    }
}
