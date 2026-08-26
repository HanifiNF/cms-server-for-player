<?php

namespace App\Entities;

use CodeIgniter\Entity\Entity;

class StorageProfile extends Entity
{
    protected $dates = ['last_tested_at', 'created_at', 'updated_at'];
    protected $casts = ['created_by' => '?integer'];

    /**
     * PostgreSQL may expose BOOLEAN values as the strings "t" and "f".
     * PHP's normal boolean cast treats both non-empty strings as true.
     */
    protected function getIsDefault(): bool
    {
        $value = $this->attributes['is_default'] ?? false;
        if (is_bool($value)) return $value;
        if (is_int($value)) return $value !== 0;

        return in_array(strtolower(trim((string) $value)), ['1', 't', 'true', 'y', 'yes', 'on'], true);
    }
}
