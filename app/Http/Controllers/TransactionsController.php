<?php

namespace App\Http\Controllers;

use App\Libraries\Helpers;
use Illuminate\Http\Request;

class TransactionsController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
    }

    public function index(Request $request)
    {
        // Restrict access.
        $restricted = Helpers::restrictAccess(['all','transactions_r']);
        if($restricted) return $restricted;

        $limit = $request->input('per_page', 15);
        if($limit > 500) $limit = 500; // Maximum rows limit to 500 per request.
        $transactions = \App\Transaction::paginate($limit);

        // Response
        return response()->json($transactions);
    }

    public function account(Request $request)
    {
        $user = app('auth')->user();
        $limit = $request->input('per_page', 15);
        $date_from = $request->input('date_from');
        $date_upto = $request->input('date_to');
        $code = $request->input('code');
        if($limit > 500) $limit = 500; // Maximum rows limit to 500 per request.
        $query = [['user_id', '=', $user->id]];
        if(preg_match('/\d{4}\-\d{2}\-\d{2}/', $date_from))
        {
            $query[] = ['created_at', '>=', $date_from.' 00:00:00'];

            if(preg_match('/\d{4}\-\d{2}\-\d{2}/', $date_upto))
            {
                $query[] = ['created_at', '<=', $date_upto.' 23:59:59'];
            }
        }
        if(in_array($code, ['ref_1','ref_2','ref_3','safe','withdraw']))
        {
            $query[] = ['code', '=', $code];
        }
        
        $transactions = \App\Transaction::where($query)->orderBy('created_at', 'desc')->paginate($limit);

        // Response
        return response()->json($transactions);
    }

    public function withdraw(Request $request)
    {
        $amount = $request->input('amount');
        // Validate input.
        if(!$amount) return app('api_error')->badRequest(null, 'Parameter missing.');
        if(!is_numeric($amount)) return app('api_error')->invalidInput(['amount'=>['Not a number.']]);

        $user = app('auth')->user();
        $tx_exist = \App\Transaction::where([['user_id','=',$user->id], ['code','=','withdraw'], ['complete','=',0]])->count();
        if($tx_exist > 0) return app('api_error')->invalidInput(['amount'=>['Request already present']]);
        if($user->balance < $amount) return app('api_error')->invalidInput(['amount'=>['Not enough fund.']]);

        $withdrawal = new \App\Transaction;
        $withdrawal->user_id = $user->id;
        $withdrawal->code = 'withdraw';
        $withdrawal->kta_amt = $amount;
        $withdrawal->type = 'dr';
        $withdrawal->complete = 0;
        $withdrawal->save();

        // Response
        return response()->json(['status' => 'SUCCESS', 'message' => 'Withdrawal request added.']);
    }

    public function cancel(Request $request)
    {
        $id = $request->input('id');

        // Validate input.
        if(!$id) return app('api_error')->badRequest(null, 'Parameter missing.');
        if(!is_numeric($id)) return app('api_error')->invalidInput(null, 'Not a number.');
        $user = app('auth')->user();
        $pending = \App\Transaction::where([['id','=',$id], ['user_id','=',$user->id]])->first();
        if(!$pending) return app('api_error')->invalidInput(null, 'Entry does not exist.');

        // Delete entry.
        $pending->delete();

        // Response.
        return response()->json(['status' => 'SUCCESS', 'message' => 'Withdrawal request deleted.']);
    }

    public function pay(Request $request)
    {
        // Restrict access.
        $restricted = Helpers::restrictAccess(['all','transaction_pay']);
        if($restricted) return $restricted;

        // Validate input
        $errors = null;
        $inputs = $request->only(['id', 'amount', 'ref']);
        $rules = [
            'id' => 'required|numeric|exists:transactions,id',
            'amount' => 'required|numeric',
            'ref' => 'required|regex:/^0x[a-zA-Z0-9]{64}$/'
        ];

        $validation = app('validator')->make($inputs, $rules, [
            'ref.regex' => 'Format is invalid',
            'id.exists' => 'Entry does not exist'
        ]);
        if($validation->fails()) $errors = $validation->errors()->messages();
        if($errors) return app('api_error')->invalidInput($errors);

        // Update record.
        $success = false;
        app('db')->transaction(function() use($inputs, &$success) {
            try
            {
                $payable = \App\Transaction::find($inputs['id']);
                $user = \App\User::find($payable->user_id);
                $payable->ref = $inputs['ref'];
                $payable->complete = 1;
                $payable->save();
                $user->balance -= $inputs['amount'];
                $user->save();
                $success = true;
            }
            catch(\Exception $e)
            {
                $success = false;
            }
        });

        // Reseponse
        if($success)
        {
            return response()->json(['status' => 'SUCCESS', 'message' => 'Payment success.']);
        }
        else
        {
            return app('api_error')->serverError(null, 'Database error occured.');
        }
    }
    public function payables(Request $request)
    {
        // Restrict access.
        $restricted = Helpers::restrictAccess(['all','transaction_r']);
        if($restricted) return $restricted;
        $date_from = $request->input('date_from');
        $date_upto = $request->input('date_to');
        
        // Process request.
        $user = app('auth')->user();
        $paid = $request->input('complete');
        $search = $request->input('search');
        $limit = $request->input('per_page', 15);
        if($limit > 500) $limit = 500; // Maximum rows limit to 500 per request.
        
        // Apply filter.
        $where = [['transactions.type','=', 'dr']];
        $or = [];
        if(is_numeric($paid))
        {
            if($paid == 0) $where[] = ['transactions.complete','=', 0];
            if($paid == 1) $where[] = ['transactions.complete','=', 1];
        }
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
            $where[] = ['transactions.created_at', '>=', $date_from.' 00:00:00'];

            if(preg_match('/\d{4}\-\d{2}\-\d{2}/', $date_upto))
            {
                $where[] = ['transactions.created_at', '<=', $date_upto.' 23:59:59'];
            }
        }
        $payables = app('db')
                    ->table('transactions')
                    ->join('users','users.id','=','transactions.user_id')
                    ->select([
                        'transactions.id',
                        'transactions.user_id',
                        'transactions.complete',
                        'transactions.kta_amt',
                        'transactions.type',
                        'transactions.code',
                        'transactions.created_at',
                        'users.name',
                        'users.email',
                        'users.address'])
                    ->where($where)
                    ->where($or)
                    ->paginate($limit);
        // Response
        return response()->json($payables);
    }
}
