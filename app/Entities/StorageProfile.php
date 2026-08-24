<?php

namespace App\Entities;

use CodeIgniter\Entity\Entity;

class StorageProfile extends Entity
{
    protected $dates = ['last_tested_at', 'created_at', 'updated_at'];
    protected $casts = ['is_default' => 'boolean', 'created_by' => '?integer'];
}
