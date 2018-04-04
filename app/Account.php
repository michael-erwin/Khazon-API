<?php

namespace App;

use Illuminate\Auth\Authenticatable;
use Laravel\Lumen\Auth\Authorizable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Contracts\Auth\Access\Authorizable as AuthorizableContract;

class Account extends \App\User
{
    protected $table = 'users';

    protected $fillable = [
        'name', 'address', 'upl_address', 'email', 'password', 'role_id', 'regref_id',
    ];
}
