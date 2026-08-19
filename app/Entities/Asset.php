<?php

namespace App\Entities;

use CodeIgniter\Entity\Entity;

class Asset extends Entity
{
    protected $dates = ['release_date', 'expires_on', 'expired_at', 'reviewed_at', 'created_at', 'updated_at'];

    protected $casts = [
        'size_bytes'  => 'integer',
        'duration_ms' => 'integer',
        'production_year' => '?integer',
    ];
}
