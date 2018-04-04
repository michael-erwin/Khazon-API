<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class SecondaryEmail extends Model
{
    protected $fillable = [
        'email', 'user_id',
    ];

    public function user()
    {
        return $this->belongsTo('\App\User');
    }
}
