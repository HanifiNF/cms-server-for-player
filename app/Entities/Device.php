<?php

namespace App\Entities;

use CodeIgniter\Entity\Entity;

class Device extends Entity
{
    protected $dates = [
        'activation_expires_at', 'last_seen_at', 'registered_at',
        'token_last_used_at', 'created_at', 'updated_at',
    ];

    protected $casts = [
        'inventory_revision' => 'integer',
        'schedule_revision'  => 'integer',
    ];
}
