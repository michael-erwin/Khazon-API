<?php

namespace App\Http\Controllers;

use App\Libraries\Helpers;
use Illuminate\Http\Request;

class TransactionsController extends Controller
{
    private $codes = ['ref_1','ref_2','ref_3','safe','withdraw','transfer'];
    private $types = ['cr','dr'];
    private $sortables = ['type','created_at','updated_at'];

    public function __construct()
    {
        $this->middleware('auth');
    }

    /**
    | Frontend API
    +------------------------------------------------------------------------------------------------------------------------
    */

    /**
     * Shows single entry item for transfer type only.
     * 
     * @param Object  $request Request object.
     * @param Integer $id Transaction id.
     */
    public function showTransferItem(Request $request, $id)
    {
        $item = app('db')->table('transactions')
                ->join('users', 'transactions.user_id', '=', 'users.id')
                ->select([
                    'transactions.kta_amt',
                    'transactions.type',
                    'transactions.ref',
                    'transactions.created_at',
                    'users.address'])
                ->where([
                    ['transactions.id','=',$id],
                    ['transactions.code','=','transfer']])
                ->first();
        if(!$item) return app('api_error')->notFound();
        
        $ref = \App\User::where('id','=',$item->ref)->select(['address'])->first();
        if(!$ref) return app('api_error')->serverError();

        $response = [
            'sender' => null,
            'receiver' => null,
            'amount' => $item->kta_amt,
            'created_at' => $item->created_at
        ];

        if($item->type == "dr")
        {
            $response['sender'] = $item->address;
            $response['receiver'] = $ref->address; 
        }
        else
        {
            $response['sender'] = $ref->address;
            $response['receiver'] = $item->address;
        }

        // Response
        return response()->json($response);
    }

    /**
     * Shows trasaction entries of currently logged user.
     * 
     * @param Object $request Request object.
     */
    public function showListFromMyAccount(Request $request)
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
        if(in_array($code, ['ref_1','ref_2','ref_3','safe','withdraw','transfer']))
        {
            $query[] = ['code', '=', $code];
        }
        
        $transactions = \App\Transaction::where($query)->orderBy('created_at', 'desc')->paginate($limit);

        // Response
        return response()->json($transactions);
    }

    /**
     * Adds transaction entry of withdrawal request for currently logged user.
     * 
     * @param Object $request Request object.
     */
    public function withdrawFromMyAccount(Request $request)
    {
        $amount = $request->input('amount');
        // Validate input.
        if(!$amount) return app('api_error')->badRequest(null, 'Parameter missing.');
        if(!is_numeric($amount)) return app('api_error')->invalidInput(['amount'=>['Not a number.']]);

        $user = app('auth')->user();
        $tx_exist = \App\Transaction::where([['user_id','=',$user->id], ['code','=','withdraw'], ['complete','=',0]])->count();
        if($tx_exist > 0) return app('api_error')->invalidInput(['amount'=>['Pending request not yet settled']]);
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

    /**
     * Adds transaction entries of transfer between currently logged user and recipient user via address field.
     * 
     * @param Object $request Request object.
     */
    public function transferFromMyAccount(Request $request)
    {
        $amount = $request->input('amount');
        $address = $request->input('address');

        // Validate input.
        $generic_error = "";
        $field_errors = [];
        $txn_success = false;

        if(!$amount || !$address)
        {
            return app('api_error')->badRequest(null, 'Missing parameter.');
        }
        else
        {
            $receiver = \App\User::where('address',$address)->first();
            if(!$receiver)
            {
                $field_errors['address'][] = 'Does not exist';
            }
            elseif(!$receiver->active) {
                $field_errors['address'][] = 'User is inactive';
            }
            else
            {
                $sender = app('auth')->user();
                $pending = \App\Transaction::where([['user_id','=',$sender->id],['complete','=','0']])->first();
                if($pending) return app('api_error')->invalidInput(null, 'Pending request not yet settled');
                if($address === $sender->address) $field_errors['address'][] = 'Own address not allowed';
                if(!preg_match('/^\d*(\.\d+)?$/', $amount))
                {
                    $field_errors['amount'][] = 'Format not supported.';
                }
                elseif($amount > (float) $sender->balance)
                {
                    $field_errors['amount'][] = 'Insuficient funds.';
                }
                else {
                    $amount = number_format($amount, 8);
                }
            }
        }

        // Return errors found.
        if(count($field_errors) > 0) return app('api_error')->invalidInput($field_errors);

        //*
        app('db')->transaction(function() use(&$sender,&$receiver,$amount,&$txn_success) {
            $sender->balance = number_format(($sender->balance - $amount), 8);
            $sender->save();
            $receiver->balance = number_format(($receiver->balance + $amount), 8);
            $receiver->save();

            $sender_tx = new \App\Transaction;
            $sender_tx->kta_amt = $amount;
            $sender_tx->user_id = $sender->id;
            $sender_tx->ref = $receiver->id;
            $sender_tx->code = 'transfer';
            $sender_tx->complete = 1;
            $sender_tx->type = 'dr';
            $sender_tx->save();
            
            $receiver_tx = new \App\Transaction;
            $receiver_tx->kta_amt = $amount;
            $receiver_tx->user_id = $receiver->id;
            $receiver_tx->ref = $sender->id;
            $receiver_tx->code = 'transfer';
            $receiver_tx->complete = 1;
            $receiver_tx->type = 'cr';
            $receiver_tx->save();

            $txn_success = true;
        }, 5);
        //*/

        $txn_success = true;

        if($txn_success)
        {
            // Fire fund transfer event.
            event(new \App\Events\FundTransferEvent($sender, $receiver, $amount));
        
            // Return a response.
            return response()->json([
                'code' => 'SUCCESS',
                'message' => 'Success',
                'data' => [
                    'amount' => $amount,
                    'address' => $address
                ]
            ]);
        }
        else
        {
            return app('api_error')->badRequest(null, 'Database error has occured.');
        }
    }

    /**
     * Deletes transaction entry of withdrawal request for currenly logged user.
     * 
     * @param Object    $request    Request object.
     * @param Integer   $id         Transaction id.
     */
    public function deleteFromMyAccount(Request $request, $id)
    {
        // Validate input.
        if(!$id) return app('api_error')->badRequest(null, 'Parameter missing.');
        if(!is_numeric($id)) return app('api_error')->invalidInput(null, 'Not a number.');
        $user = app('auth')->user();
        $pending = \App\Transaction::where([['id','=',$id], ['user_id','=',$user->id]])->first();
        if(!$pending) return app('api_error')->invalidInput(null, 'Entry does not exist.');
        if($pending->locked == 1) return app('api_error')->invalidInput(['id'=>['Item is currently locked.']], 
            'Item is currently locked.');

        // Delete entry.
        $pending->delete();

        // Response.
        return response()->json(['status' => 'SUCCESS', 'message' => 'Withdrawal request deleted.']);
    }

    /**
    | Backend API
    +------------------------------------------------------------------------------------------------------------------------
    */

    /**
     * Shows transaction entries for all.
     * 
     * @param Object $request Request object.
     */
    public function index(Request $request)
    {
        // Restrict access.
        $restricted = Helpers::restrictAccess(['all','transaction_r']);
        if($restricted) return $restricted;

        // Table filter fields
        $type       = $request->input('type', '');
        $code       = $request->input('code', '');
        $complete   = $request->input('complete');

        // Addition filters
        $date_from  = $request->input('date_from');
        $date_upto  = $request->input('date_to');
        $search     = $request->input('search');
        $limit      = $request->input('per_page', 15);
        $order_by   = $request->input('order_by');
        $sort_desc  = $request->input('sort_desc');

        // Apply filters.
        $where = []; $or = [];
        if(in_array($type, $this->types))  $where[] = ['transactions.type','=', $type];
        if(in_array($code, $this->codes))  $where[] = ['transactions.code','=', $code];
        if(in_array($complete, ['0','1'])) $where[] = ['transactions.complete','=', $complete];
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
        if($limit > 500) $limit = 500; // Maximum rows limit to 500 per request.

        // Final query
        $selects = [
            'transactions.id',
            'transactions.user_id',
            'transactions.ref',
            'transactions.kta_amt',
            'transactions.type',
            'transactions.code',
            'transactions.complete',
            'transactions.locked',
            'transactions.created_at',
            'users.name',
            'users.email',
            'users.address'];
        if(in_array($order_by, $this->sortables)) # order_by & sort_desc
        {
            $sort = empty($sort_desc)? 'asc' : 'desc';
            $items = app('db')
                ->table('transactions')
                ->join('users','users.id','=','transactions.user_id')
                ->select($selects)
                ->where($where)
                ->where($or)
                ->orderBy($order_by, $sort)
                ->paginate($limit);
        }
        else
        {
            $items = app('db')
                ->table('transactions')
                ->join('users','users.id','=','transactions.user_id')
                ->select($selects)
                ->where($where)
                ->where($or)
                ->paginate($limit);
        }

        // Response
        return response()->json($items);
    }

    /**
     * Shows transaction entry identified by id.
     * 
     * @param Object  $request     Request object.
     * @param Integer $identifier  Transaction id.
     */
    public function show(Request $request, $id)
    {
        // Restrict access.
        $restricted = Helpers::restrictAccess(['all','transaction_r']);
        if($restricted) return $restricted;

    }

    /**
     * Shows transaction entries using user's unique identifier.
     * 
     * @param Object          $request        Request object.
     * @param Integer|String  $identifier     Can be id, address or email.
     */
    public function showByUser(Request $request, $identifier)
    {
        // Restrict access.
        $restricted = Helpers::restrictAccess(['all','transaction_r']);
        if($restricted) return $restricted;

        // Validate field
        $field = null;
        if(preg_match('/^\d+$/',$identifier))               $field = 'id';
        if(preg_match('/^0x[0-9a-f]{40}$/i',$identifier))   $field = 'address';
        if(filter_var($identifier, FILTER_VALIDATE_EMAIL))  $field = 'email';
        if(!$field) return app('api_error')->invalidInput(null, 'Invalid field.');

        // Validate user's existence
        $user = \App\User::where([[$field,'=',$identifier]])->get(['id','name','email','address'])->first();
        if(!$user) return app('api_error')->invalidInput(null, 'User not found.');

        // Table filter fields
        $type       = $request->input('type', '');
        $code       = $request->input('code', '');
        $complete   = $request->input('complete');

        // Addition filters
        $date_from  = $request->input('date_from');
        $date_upto  = $request->input('date_to');
        $limit      = $request->input('per_page', 15);
        $order_by   = $request->input('order_by');
        $sort_desc  = $request->input('sort_desc');

        // Apply filters.
        $where = [['user_id','=',$user->id]];
        if(in_array($type, $this->types))  $where[] = ['type','=', $type];
        if(in_array($code, $this->codes))  $where[] = ['code','=', $code];
        if(in_array($complete, ['0','1'])) $where[] = ['complete','=', $complete];
        if(preg_match('/\d{4}\-\d{2}\-\d{2}/', $date_from))
        {
            $where[] = ['created_at', '>=', $date_from.' 00:00:00'];
            if(preg_match('/\d{4}\-\d{2}\-\d{2}/', $date_upto))
            {
                $where[] = ['created_at', '<=', $date_upto.' 23:59:59'];
            }
        }
        if($limit > 500) $limit = 500; // Maximum rows limit to 500 per request.

        // Get data
        if(in_array($order_by, $this->sortables)) # order_by & sort_desc
        {
            $sort = empty($sort_desc)? 'asc' : 'desc';
            $items = \App\Transaction::where($where)->orderBy($order_by, $sort)->paginate($limit);
        }
        else
        {
            $items = \App\Transaction::where($where)->paginate($limit);
        }

        // Response
        return response()->json($items);
    }

    /**
     * Update transaction entry.
     * 
     * @param Integer $id  Transaction id.
     */
    public function update(Request $request, $id)
    {
        // Restrict access.
        $restricted = Helpers::restrictAccess(['all','transaction_w']);
        if($restricted) return $restricted;

        // Validate inputs.
        $item = \App\Transaction::find($id);
        if (!$item) return app('api_error')->invalidInput(['id'=>['Does not exist']], 'Item does not exist.');
        $inputs = $request->only(['kta_amt', 'ref', 'type', 'code', 'complete', 'locked']);
        $rules = [
            'kta_amt' => 'numeric',
            'type' => 'in:cr,dr',
            'complete' => 'in:0,1',
            'locked' => 'in:0,1'
        ];
        $validation = app('validator')->make($inputs, $rules);
        if($validation->fails()) $errors = $validation->errors()->messages();

        // Save new values
        foreach($inputs as $key => $value) $item->{$key} = $value;
        $item->save();

        // Update values.
        return response()->json(['status' => 'SUCCESS', 'message' => 'Item updated.'], 200);
    }

    /**
     * Updates transaction entry and user balance to settle payment for withdrawal request.
     * 
     * @param Object      $request    Request object.
     * @param Integer     $id         User's id.
     */
    public function payUser(Request $request, $id)
    {
        // Restrict access.
        $restricted = Helpers::restrictAccess(['all','transaction_pay']);
        if($restricted) return $restricted;

        // Validate input
        $errors = null;
        $inputs = $request->only(['user_id','amount','ref']);
        $payable = \App\Transaction::find($id);
        if(!$payable) return app('api_error')->invalidInput(['id'=>['Does not exist']], 'Item does not exist.');
        $rules = [
            'amount' => 'required|numeric',
            'ref' => 'required|regex:/^0x[a-zA-Z0-9]{64}$/'
        ];
        $validation = app('validator')->make($inputs, $rules);
        if($validation->fails()) $errors = $validation->errors()->messages();
        if($errors) return app('api_error')->invalidInput($errors);

        // Update record.
        $success = false;
        app('db')->transaction(function() use($id, $inputs, &$payable, &$success) {
            try
            {
                $payable = \App\Transaction::find($id);
                $payable->ref = $inputs['ref'];
                $payable->complete = 1;
                $payable->save();

                $user = \App\User::find($payable->user_id);
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
            return response()->json(['status' => 'SUCCESS', 'message' => 'Item updated.'], 200);
        }
        else
        {
            return app('api_error')->serverError(null, 'Database error occured.');
        }
    }
}
