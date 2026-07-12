<?php

namespace Serpensin\RenderedLogs\Models;

use Illuminate\Database\Eloquent\Model;

class RenderedLog extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
        ];
    }
}
