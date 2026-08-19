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
        'public_id', 'title', 'synopsis', 'genre', 'language', 'subtitles',
        'age_rating', 'production_year', 'release_date', 'expires_on', 'expired_at', 'distributor_company',
        'poster_storage_key', 'poster_filename', 'poster_mime_type',
        'filename', 'storage_key', 'mime_type', 'size_bytes',
        'sha256', 'duration_ms', 'status', 'created_by', 'reviewed_by',
        'reviewed_at', 'rejection_reason',
    ];
}
