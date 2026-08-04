<?php

namespace App\Models;

use App\Entities\DeviceAsset;
use CodeIgniter\Model;

class DeviceAssetModel extends Model
{
    protected $table = 'device_assets';
    protected $primaryKey = 'id';
    protected $returnType = DeviceAsset::class;
    protected $useTimestamps = true;
    protected $allowedFields = [
        'device_id', 'asset_id', 'media_key', 'source', 'title', 'filename',
        'relative_path', 'size_bytes', 'duration_ms', 'sha256', 'status',
        'modified_at', 'last_reported_at',
    ];
}
