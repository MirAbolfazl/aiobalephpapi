<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Model;

class BaleAuthChallenge extends Model
{
    protected $fillable = [
        'user_id',
        'phone',
        'transaction_hash',
        'device_title',
        'attempts',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'transaction_hash' => 'encrypted',
            'attempts' => 'integer',
            'expires_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
