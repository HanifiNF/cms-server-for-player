<?php

namespace App\Models;

use App\Entities\AssetVersion;
use CodeIgniter\Model;

class AssetVersionModel extends Model
{
    protected $table = 'asset_versions';
    protected $primaryKey = 'id';
    protected $returnType = AssetVersion::class;
    protected $useTimestamps = true;
    protected $allowedFields = [
        'asset_id', 'revision', 'filename', 'storage_key', 'mime_type', 'size_bytes',
        'sha256', 'duration_ms', 'status', 'metadata_snapshot', 'submitted_by',
        'reviewed_by', 'reviewed_at', 'rejection_reason',
        'encryption_format', 'plaintext_size_bytes', 'plaintext_sha256', 'ldg_chunk_size',
        'wrapped_dek', 'dek_nonce', 'dek_tag', 'key_version', 'encryption_revision',
    ];
}
