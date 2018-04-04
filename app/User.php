<?php

namespace App;

use Illuminate\Auth\Authenticatable;
use Laravel\Lumen\Auth\Authorizable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Contracts\Auth\Access\Authorizable as AuthorizableContract;

class User extends Model implements AuthenticatableContract, AuthorizableContract
{

    use Authenticatable, Authorizable;

    protected $fillable = [
        'name', 'address', 'upl_address', 'email', 'password', 'role', 'regref_id',
    ];

    protected $hidden = [
        'password', 'txn_token', 'rand_key',
    ];

    protected $claims;

    public function role()
    {
        return $this->belongsTo('\App\Role');
    }

    public function referral()
    {
        return $this->hasMany('\App\Referral','user_id');
    }

    public function reg_ref_parent()
    {
        return User::where('id', $this->regref_id)->first();
    }

    public function upline()
    {
        return User::where('address', $this->upl_address)->first();
    }

    public function secondary_email()
    {
        return $this->hasOne('\App\SecondaryEmail','user_id');
    }

    public function challenge_questions()
    {
        return $this->hasMany('\App\ChallengeQuestion','user_id');
    }
}
