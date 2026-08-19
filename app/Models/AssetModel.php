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
        'public_id', 'revision', 'title', 'synopsis', 'genre', 'language', 'subtitles',
        'age_rating', 'production_year', 'release_date', 'expires_on', 'expired_at', 'distributor_company',
        'poster_storage_key', 'poster_filename', 'poster_mime_type',
        'filename', 'storage_key', 'mime_type', 'size_bytes',
        'sha256', 'duration_ms', 'status', 'created_by', 'reviewed_by',
        'reviewed_at', 'rejection_reason',
        'encryption_format', 'plaintext_size_bytes', 'plaintext_sha256', 'ldg_chunk_size',
        'wrapped_dek', 'dek_nonce', 'dek_tag', 'key_version', 'encryption_revision',
    ];
}
