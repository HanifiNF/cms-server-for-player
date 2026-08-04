<?php

namespace App\Models;

use App\Entities\Asset;
use CodeIgniter\Model;

class AssetModel extends Model
{
    protected $table = 'assets';
    protected $primaryKey = 'id';
    protected $returnType = Asset::class;
    protected $useTimestamps = true;
    protected $allowedFields = [
        'public_id', 'title', 'filename', 'storage_key', 'mime_type', 'size_bytes',
        'sha256', 'duration_ms', 'status', 'created_by',
    ];
}
