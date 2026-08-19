<?php

namespace App\Entities;

use CodeIgniter\Entity\Entity;

class AssetVersion extends Entity
{
    protected $dates = ['reviewed_at', 'created_at', 'updated_at'];

    protected $casts = [
        'revision' => 'integer', 'size_bytes' => 'integer', 'duration_ms' => 'integer',
        'plaintext_size_bytes' => '?integer', 'ldg_chunk_size' => '?integer',
        'key_version' => 'integer', 'encryption_revision' => '?integer',
    ];
}
