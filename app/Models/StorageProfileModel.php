<?php

namespace App\Models;

use App\Entities\StorageProfile;
use CodeIgniter\Model;

class StorageProfileModel extends Model
{
    protected $table = 'storage_profiles';
    protected $primaryKey = 'id';
    protected $returnType = StorageProfile::class;
    protected $useTimestamps = true;
    protected $allowedFields = [
        'public_id', 'name', 'driver', 'status', 'is_default', 'config',
        'credentials_encrypted', 'last_tested_at', 'last_test_status',
        'last_test_message', 'created_by',
    ];
}
