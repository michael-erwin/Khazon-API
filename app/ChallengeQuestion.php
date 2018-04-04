<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class ChallengeQuestion extends Model
{
    protected $fillable = [
        'question', 'answer', 'hash', 'series', 'user_id',
    ];

    protected $hidden = [
        'answer'
    ];

    public function user()
    {
        return $this->belongsTo('\App\User');
    }
}
