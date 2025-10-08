<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Payout extends Model
{
    protected $fillable = [
        'school_id',
        'amount',
        'currency',
        'payout_date',
        'start_at',
        'end_at',
        'status',
        'transfer_code',
        'transfer_id',
        'response',
    ];

    protected $casts = [
        'response' => 'array',
        'payout_date' => 'date',
        'start_at' => 'datetime',
        'end_at' => 'datetime',
    ];

    public function school()
    {
        return $this->belongsTo(School::class);
    }
}
