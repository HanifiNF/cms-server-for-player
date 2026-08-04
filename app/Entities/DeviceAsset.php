<?php

namespace App\Entities;

use CodeIgniter\Entity\Entity;

class DeviceAsset extends Entity
{
    protected $dates = ['modified_at', 'last_reported_at', 'created_at', 'updated_at'];

    protected $casts = [
        'size_bytes'  => 'integer',
        'duration_ms' => 'integer',
    ];
}
