<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Model;

class MediaAsset extends Model
{
    protected $fillable = [
        'user_id',
        'bale_account_id',
        'disk',
        'path',
        'original_name',
        'mime_type',
        'size',
        'sha256',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'metadata' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function baleAccount(): BelongsTo
    {
        return $this->belongsTo(BaleAccount::class);
    }
}
