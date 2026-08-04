<?php

namespace App\Models;

use App\Entities\Device;
use CodeIgniter\Model;

class DeviceModel extends Model
{
    protected $table = 'devices';
    protected $primaryKey = 'id';
    protected $returnType = Device::class;
    protected $useTimestamps = true;
    protected $allowedFields = [
        'public_id', 'name', 'device_key_hash', 'activation_code_hash', 'status',
        'activation_expires_at', 'fingerprint_hash', 'app_version', 'platform',
        'timezone', 'last_seen_at', 'registered_at', 'token_last_used_at', 'ip_address',
        'assigned_user_id', 'claimed_by', 'claimed_at', 'location',
        'inventory_revision', 'schedule_revision',
    ];
}
