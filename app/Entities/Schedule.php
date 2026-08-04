<?php

namespace App\Entities;

use CodeIgniter\Entity\Entity;

class Schedule extends Entity
{
    protected $dates = ['start_at', 'end_at', 'created_at', 'updated_at'];

    protected $casts = [
        'priority'     => 'integer',
        'loop_enabled' => 'boolean',
        'revision'     => 'integer',
    ];
}
