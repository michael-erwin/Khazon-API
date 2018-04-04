<?php

namespace App\Http\Controllers;

use \App\Libraries\Helpers;
use \App\Account;
use \App\Cuk;
use \Firebase\JWT\JWT;
use Illuminate\Http\Request;
use \PragmaRX\Google2FA\Google2FA;
use \App\Libraries\PlainTea;

class AccountController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth',['only'=>['index','emailVerify']]);
        $this->middleware('throttle');
    }
    
    /**
     * Create a new JWT.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int $id
     * @return string
     *
     */
    private function makeJWT($request, $uid, $remember=false, $require_otp = false)
    {
        $useragent = $request->header('User-Agent','Unknown');
        $aud = md5(trim($useragent));
        $exp = $remember? time()+env('JWT_DURATION_LONG', 604800) : time()+env('JWT_DURATION', 7200);
        $payload = [
            "aud" => $aud,  // audience - hash of user agent
            "exp" => $exp,  // expiration (UNIX time)
            "jti" => $uid,  // JWT ID - used as user id
        ];
        if($remember) $payload['rem'] = true;
        if($require_otp) $payload['otp'] = true;
        return JWT::encode($payload, env('APP_KEY'), 'HS256');
    }

    /* Main */

    public function index(Request $request)
    {
        $user = app('auth')->user();
        return response()->json($user, 200);
    }

    public function auth(Request $request)
    {
        // Validate input.
        $inputs = $request->all();
        $validation = app('validator')->make($inputs,[
            'email' => 'required|email',
            'password' => 'required',
        ]);
        if($validation->fails()) return app('api_error')->invalidInput($validation->errors(),"Check validation data");

        $errors = [];

        // Verify account existence.
        $user = Account::where('email',$inputs['email'])->first();
        if(!$user) $errors['email'] = ['Does not exist'];

        // Authenticate credentials.
        if($user)
        {
            $authentic = app('hash')->check($inputs['password'],$user->password);
            if(!$authentic) $errors['password'] = ['Incorrect'];
        }
        
        // Check for errors
        if(count($errors) > 0) return app('api_error')->unauthorized($errors,"Authentication failed");

        // If no errors, issue the JWT.
        $jwt = $this->makeJWT($request, $user->id, isset($inputs['remember_me']), $user->rand_key);
        $response = response()->json(["access_token" => $jwt, "user" => $user], 200);
        $response->header('Access-Token', $jwt);
        return $response;
    }

    public function register(Request $request)
    {
        $type = 'reg'; // For chamber unlock type.
        $default_role = config('general.reg_role_id');
        
        /**
         1 - Input Validation
        */

        // Basic input validation rules.
        $errors = [];
        $inputs = $request->only(['name', 'email', 'password', 'address', 'upl_address', 'cuk']);
        $inputs['address'] = strtolower($inputs['address']);
        $rules = [
            'name' => 'required|min:2',
            'email' => 'required|email|unique:users',
            'password' => 'required|min:6',
            'address' => 'required|regex:/^0x[a-z0-9]{40}$/|unique:users',
            'cuk' => 'required|size:29',
        ];

        #1.1 - Add upline address validation if present.
        if(isset($inputs['upl_address']) && !empty($inputs['upl_address'])) {
            $inputs['upl_address'] = strtolower($inputs['upl_address']);
            $rules['upl_address'] = 'regex:/^0x[a-z0-9]{40}$/|exists:users,address';
        }

        #1.2 - Run validation basic.
        $validation = app('validator')->make($inputs, $rules, [
            'email.unique' => 'Not available',
            'email.email' => 'Format is invalid',
            'address.regex' => 'Format is invalid',
            'address.unique' => 'Not available',
            'upl_address.regex' => 'Format is invalid',
            'upl_address.exists' => 'Entry does not exist',
        ]);
        if($validation->fails()) $errors = $validation->errors()->messages();

        #1.3 - Add CUK validation.
        $cuk_hash = md5($request->input('cuk'));
        $cuk = Cuk::where('hash',$cuk_hash)->first();
        if(!$cuk)
        { $errors['cuk'][] = 'Incorrect'; }
        elseif($cuk->user_id !== null)
        { $errors['cuk'][] = 'Already used'; }

        #1.3 - Return validation errors if present.
        if(count($errors) > 0) return app('api_error')->invalidInput($errors);

        /**
         2 - Register User
        */

        #2.1 - Get upline id for registration referral id.
        $upline_entry = null;
        $account = null;
        if(isset($inputs['upl_address']) && !empty($inputs['upl_address']))
        {
            $upline_entry = \App\User::where('address', $inputs['upl_address'])->first();
            $inputs['regref_id'] = (int) $upline_entry->id;
        }

        app('db')->transaction(function() use($request, $inputs, &$cuk, &$account, $default_role) {
            #2.2 - Save user data.
            $inputs['password'] = app('hash')->make($request->input('password'));
            $inputs['role_id'] = $default_role;
            $account = new Account($inputs);
            $account->save();

            #2.3 - Update CUK status as used by the user.
            $cuk->user_id = $account->id;
            $cuk->save();
        });

        #2.4 - Determine registering user's direct upline.
        #> Reference variables
        $direct_upline = null;
        #2.4a - With upline field specified in form.
        if(isset($inputs['upl_address']) && !empty($inputs['upl_address']))
        {
            #2.4a.1 - Get direct upline.
            #> Reference variable.
            $direct_upline = $upline_entry; // Already saved from above if validation passed.

            #2.4a.2 - Run applicable referral earnings.
            if($direct_upline)
            {
                app('db')->transaction(function() use($direct_upline, $account) {
                    #2.3a.2.1 - Referral & Earnings
                    $earning_ref = config('general.earning_ref');

                    // Level 1.
                    $ref_1 = $direct_upline;
                    # Referral entry
                    $ref_1_entry = new \App\Referral;
                    $ref_1_entry->user_id = $ref_1->id;
                    $ref_1_entry->user_reg_id = $account->id;
                    $ref_1_entry->type = 'ref_1';
                    $ref_1_entry->save();

                    # Earnings entry
                    $ref_1_earning = new \App\Transaction;
                    $ref_1_earning->user_id = $ref_1->id;
                    $ref_1_earning->kta_amt = $earning_ref[1];
                    $ref_1_earning->code = 'ref_1';
                    $ref_1_earning->ref = $ref_1_entry->id;
                    $ref_1_earning->type = 'cr';
                    $ref_1_earning->complete = 1;
                    $ref_1_earning->save();

                    # Guardian user's balance
                    $user1 = \App\User::where('id', $ref_1->id)->select(['id','balance'])->first();
                    $user1->balance += $earning_ref[1];
                    $user1->save();

                    $ref_2 = $ref_1->reg_ref_parent();
                    if($ref_2)
                    {
                        // Level 2 - Referral entry.
                        $ref_2_entry = new \App\Referral;
                        $ref_2_entry->user_id = $ref_2->id;
                        $ref_2_entry->user_reg_id = $account->id;
                        $ref_2_entry->type = 'ref_2';
                        $ref_2_entry->save();

                        // Level 2 - Earnings
                        $ref_2_earning = new \App\Transaction;
                        $ref_2_earning->user_id = $ref_2->id;
                        $ref_2_earning->kta_amt = $earning_ref[2];
                        $ref_2_earning->code = 'ref_2';
                        $ref_2_earning->ref = $ref_1_entry->id;
                        $ref_2_earning->type = 'cr';
                        $ref_2_earning->complete = 1;
                        $ref_2_earning->save();

                        # Guardian user's balance
                        $user2 = \App\User::where('id', $ref_2->id)->select(['id','balance'])->first();
                        $user2->balance += $earning_ref[2];
                        $user2->save();

                        $ref_3 = $ref_2->reg_ref_parent();
                        if($ref_3)
                        {
                            // Level 3 - Referral entry.
                            $ref_3_entry = new \App\Referral;
                            $ref_3_entry->user_id = $ref_3->id;
                            $ref_3_entry->user_reg_id = $account->id;
                            $ref_3_entry->type = 'ref_3';
                            $ref_3_entry->save();

                            // Level 3 - Earnings
                            $ref_3_earning = new \App\Transaction;
                            $ref_3_earning->user_id = $ref_3->id;
                            $ref_3_earning->kta_amt = $earning_ref[3];
                            $ref_3_earning->code = 'ref_3';
                            $ref_3_earning->ref = $ref_1_entry->id;
                            $ref_3_earning->type = 'cr';
                            $ref_3_earning->complete = 1;
                            $ref_3_earning->save();

                            # Guardian user's balance
                            $user3 = \App\User::where('id', $ref_3->id)->select(['id','balance'])->first();
                            $user3->balance += $earning_ref[3];
                            $user3->save();
                        }
                    }
                });
            }
        }
        #2.3b - Without upline field specified in form.
        else
        {
            app('db')->transaction(function() use(&$direct_upline, &$account) {
                # Fetch the earliest incomplete chamber relative to registration date of its user.
                $direct_upline_safe = \App\Chamber::where([['level','=',1],['completed','<',7],['user_id','!=',$account->id]])->orderBy('id')->first();
                if($direct_upline_safe) 
                {
                    $direct_upline = Account::find($direct_upline_safe->user_id);
                    // Update account upline address.
                    $account->upl_address = $direct_upline->address;
                    $account->upl_type = 'auto';
                    $account->save();
                }
            });
        }

        /**
         3 - Create User's Safe (Chamber entry)
        */
        #3a - Make chamber entry based on direct upline.
        if($direct_upline)
        {
            #3a.1 - Determine the location of chamber to be created.
            
            #3a.1.1 - Get chamber location of parent chamber.
            #> Reference variables.
            $parent_chamber_is_level1 = true; // On level 1 block.
            $parent_chamber_location = null;
            #> Get the level 1 chamber of direct upline that was incomplete.
            $parent_chamber = \App\Chamber::where([['user_id','=',$direct_upline->id],['level','=',1],['completed','<',7]])->first();
            if(!$parent_chamber) $parent_chamber_is_level1 = false;
            #> Decision based on presence of parent chamber.
            if($parent_chamber_is_level1)
            {
                $parent_chamber_location = (string) $parent_chamber->location;
            }
            else
            {
                // Get earliest incomplete chamber relative to registration date of its user.
                $parent_chamber = \App\Chamber::where([['level','=',1],['completed','<',7]])->orderBy('id','asc')->first();
                $parent_chamber_location = (string) $parent_chamber->location;
                $parent_chamber_account = \App\User::find($parent_chamber->user_id);
                // Update user's upline to reflect change as determined by legibility.
                if($parent_chamber_location)
                {
                    $account->upl_address = $parent_chamber_account->address;
                    $account->upl_type = 'adjust';
                    $account->save();
                }
            }

            if($parent_chamber_location)
            {
                #3a.1.2 - Extract safe of parent based on its chamber location.
                $parent_safe = Helpers::getSafeMap($parent_chamber_location);

                #3a.2 - Create chamber placement for the newly registered user.
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

                #3a.2.2 - Create new chamber entry for the user based on location found.
                $reg_user_chamber = new \App\Chamber;
                $reg_user_chamber->level = 1;
                $reg_user_chamber->user_id = $account->id;
                $reg_user_chamber->completed = 1;
                $reg_user_chamber->location = $chamber_unlocked_location;
                $reg_user_chamber->unlock_method = 'reg';
                $reg_user_chamber->save();

                #3a.2.3 - Emit a chamber creation event.
                event(new \App\Events\ChamberCreatedEvent($chamber_unlocked_location,$safe_position));
            }
            else
            {
                app('log')->warning("No 'parent_chamber_location' for 'user_id' {$account->id}");
            }
        }
        #3b - Make chamber entry for genesis account (no existing upline found).
        else
        {
            #3b.1 - Create new chamber entry for the user.
            $reg_user_chamber = new \App\Chamber;
            $reg_user_chamber->level = 1;
            $reg_user_chamber->user_id = $account->id;
            $reg_user_chamber->completed = 1;
            $reg_user_chamber->location = '1.1.1';
            $reg_user_chamber->unlock_method = 'reg';
            $reg_user_chamber->save();
        }

        /**
         4 - Final Output
        */
        $success = [
            "message" => "New account created.",
            "data" => $account,
        ];

        return response()->json($success, 200);
    }

    public function authVerify(Request $request, Google2FA $google2fa, PlainTea $PlainTea)
    {
        // Validate OTP format.
        $otp_code = $request->input('otp');
        $valid_otp = preg_match('/^\d{6}$/', $otp_code);
        if(!$valid_otp) return app('api_error')->invalidInput();

        // Validate OTP value.
        $user = app('auth')->user();
        $secret = $PlainTea->decrypt($user->rand_key, env('APP_KEY'));
        $verified = $google2fa->verifyKey($secret, (string) $otp_code);
        if(!$verified) return app('api_error')->invalidInput(null, 'Incorrect');

        // Issue new token.
        $payload = (array) config('jwt.claims');
        unset($payload['otp']);
        $jwt = \Firebase\JWT\JWT::encode($payload, env('APP_KEY'), 'HS256');
        return response()->json(["access_token" => $jwt, "user" => $user], 200, ['Access-Token' => $jwt]);
    }

    public function emailVerify(Request $request, Google2FA $google2fa)
    {
        
        /**
         Validate Input
        */

        #1 - Whitelist for allowed types.
        $allowed_types = ['primary','secondary'];
        
        #2 - Require 'type' field & validate.

        #2.1 - Require.
        $type = $request->input('type');
        if(!$type) return app('api_error')->badRequest();

        #2.2 - Validate value.
        if(!in_array($type, $allowed_types)) return app('api_error')->badRequest();
        
        #3 - Require 'value' field & validate.

        #3.1 - Require.
        $value = $request->input('value');
        if(!$value) return app('api_error')->badRequest();

        #3.2 - Validate format.
        if(!filter_var($value, FILTER_VALIDATE_EMAIL)) return app('api_error')->invalidInput(['value' => ['Invalid']]);

        #3.3 - Check if available.
        $user = app('auth')->user();
        $primary_taken = \App\User::where([['email', '=', $value], ['id', '!=', $user->id]])->count();
        if($primary_taken > 0) return app('api_error')->invalidInput(['value' => ['Not available']]);
        if($type == 'primary')
        {
            $current_secondary = $user->secondary_email;
            if($current_secondary) if($current_secondary->email == $value) return app('api_error')->invalidInput(['value' => ['Not available']]);
        }
        if($type == 'secondary')
        {
            if($user->email == $value) return app('api_error')->invalidInput(['value' => ['Cannot be the same as primary']]);
            $secondary_taken = \App\SecondaryEmail::where([['email', '=', $value], ['user_id', '!=', $user->id]])->count();
            if($secondary_taken > 0) return app('api_error')->invalidInput(['value' => ['Not available']]);
        }

        /**
         Generate and Send OTP
        */

        #4.1 - Generate OTP
        $google2fa->setOneTimePasswordLength(7);
        $otp = $google2fa->getCurrentOtp(env('TWO_FACTOR_KEY'));
        $otp_exp = env('OTP7_EXPIRY');

        #4.2 - Emit email verify event for listener to process.
        $env = env('APP_ENV');
        if($env == 'staging' || $env == 'production') event(new \App\Events\EmailVerifyEvent($value, $otp, $otp_exp));

        /**
         Response
        */
        $response = [
            'status'=>'SUCCESS',
            'message'=>'OTP has been sent to email.',
            'data' => [
                'email' => $value,
                'primary' => $user->email,
                'expiry' => $otp_exp
            ]
        ];
        return response()->json($response, 200);
    }

    public function recover(Request $request)
    {
        $user_id  = $request->input('jti');
        $txn_ref  = $request->input('ref');
        $password = $request->input('password');

        if($user_id && $txn_ref && $password)
        {
            $user = \App\User::where([['id','=',$user_id],['txn_token','=',$txn_ref]])->first();
            if(!$user) return app('api_error')->badRequest();
            if(strlen($password) < 6) return app('api_error')->invalidInput(null, ['password'=>['Too short']]);
            $user->password = app('hash')->make($password);
            $user->txn_token = '';
            $user->save();

            // Response.
            $success = [
                "status" => "SUCCESS",
                "message" => "New password saved."
            ];
            return response()->json($success);
        } else return app('api_error')->badRequest();
    }

    public function recoverNewRequest(Request $request)
    {
        $email = $request->input('email');
        $method = $request->input('method');
        $allowed_methods = ['link','cqa'];

        // Validate input.
        if(!filter_var($email, FILTER_VALIDATE_EMAIL)) return app('api_error')->invalidInput(['email' => ['Invalid']]);
        if(!$method) return app('api_error')->invalidInput(['method' => ['Required']]);
        if(!in_array($method, $allowed_methods)) return app('api_error')->invalidInput(['method' => ['Unknown']]);

        // Validate value.
        $user = \App\User::where('email', $email)->first();
        if(!$user)
        { // Check for secondary email.
            $secondary = \App\SecondaryEmail::where('email', $email)->first();
            if(!$secondary) return app('api_error')->invalidInput(['email' => ['Unrecognized']]);
            $user = $secondary->user;
            if(!$user) return app('api_error')->invalidInput(['email' => ['No user']]);
        }
        if($user->active == 0) return app('api_error')->invalidInput(['email' => ['Account is inactive']]);

        // Process request.
        if($method === $allowed_methods[0])
        {
            return $this->recoverViaEmail($user, $email);
        }
        elseif($method == $allowed_methods[1])
        {
            return $this->recoverViaQuestion($user, $email);
        }
        else
        {
            return app('api_error')->badRequest();
        }
    }

    public function verifyResetToken($token)
    {
        # Verify token.
        $claims = JWT::decode($token, env('APP_KEY'), ['HS256']);

        # Verify data.
        if(isset($claims->jti) && isset($claims->ref))
        {
            $existing = \App\User::where([['id','=',$claims->jti],['txn_token','=',$claims->ref]])->count();
            if($existing)
            {
                return response()->json(['status'=>'SUCCESS', 'data'=>$claims]);
            } else return app('api_error')->badRequest();
        } else return app('api_error')->badRequest();
    }

    public function checkAnswer(Request $request)
    {
        $req_qid = $request->input('qid');
        $req_answer = $request->input('answer');

        // Require fields.
        if(!is_numeric($req_qid) && !$req_answer) return app('api_error')->invalidInput();

        // Validate values.
        $question = \App\ChallengeQuestion::find($req_qid);
        if(!$question) return app('api_error')->badRequest(null, 'Question not found. '.$req_qid);
        $user = $question->user;
        if(!$user) return app('api_error')->badRequest(null, 'User not found.');

        $app_key = env('APP_KEY');
        $index = 'q'.$question->series;
        $answer_sig = strtolower(trim($req_answer).'_'.$index.'_'.$user->id.'_').$app_key;
        if($question->hash != md5($answer_sig)) return app('api_error')->invalidInput(['answer' => ['Incorrect']]);

        // Issue JWT
        // Construct JWT.
        $now = time();
        $ref = dechex($now);
        $payload = [
            "exp" => ($now + (60 * 5)), // To expire in 5 minutes.
            "jti" => $user->id,
            "ref" => $ref
        ];
        $jwt = \Firebase\JWT\JWT::encode($payload, $app_key, 'HS256');

        // Update transaction reference.
        $user->txn_token = $ref;
        $user->save();

        // Response.
        $success = [
            "status" => "SUCCESS",
            "message" => "Answer is correct.",
            "data" => [
                "token" => $jwt
            ]
        ];
        return response()->json($success);

    }

    private function recoverViaEmail($user, $email)
    {
        // Construct JWT.
        $now = time();
        $ref = dechex($now);
        $payload = [
            "exp" => ($now + env('JWT_LINK_EXPIRY')),
            "jti" => $user->id,
            "ref" => $ref
        ];
        $jwt = \Firebase\JWT\JWT::encode($payload, env('APP_KEY'), 'HS256');

        // Update transaction reference.
        $user->txn_token = $ref;
        $user->save();

        // Emit reset password request event.
        $env = env('APP_ENV');
        if($env == 'staging' || $env == 'production') event(new \App\Events\PwResetReqEvent($email, $jwt));

        // Response.
        $success = [
            "status" => "SUCCESS",
            "message" => "Reset link sent to email."
        ];
        return response()->json($success);
    }

    private function recoverViaQuestion($user, $email)
    {
        $questions = $user->challenge_questions;

        // Verify
        $items = count($questions);
        if($items == 0) return app('api_error')->invalidInput(['email' => ['Questions disabled']]);

        $plaintea = new PlainTea;
        $index = rand(0, $items-1);
        $question = $questions[$index];

        // Response
        $response = [
            'status' => 'SUCCESS',
            'data' => [
                'qid' => $question->id,
                'question' => $plaintea->decrypt($question->question, env('APP_KEY'))
            ]
        ];
        return response()->json($response);
    }
}
