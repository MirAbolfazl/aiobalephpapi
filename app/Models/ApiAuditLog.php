<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ApiAuditLog extends Model
{
    protected $fillable = [
        'user_id',
        'request_id',
        'action',
        'method',
        'path',
        'status_code',
        'ip_address',
        'metadata',
    ];

    protected function casts(): array
    {
        return ['metadata' => 'array'];
    }
}
