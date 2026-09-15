<?php

namespace App\Models;

use CodeIgniter\Model;

class ExternalEncryptionJobModel extends Model
{
    protected $table = 'external_encryption_jobs';
    protected $primaryKey = 'id';
    protected $returnType = 'object';
    protected $useTimestamps = true;
    protected $allowedFields = [
        'public_id', 'owner_user_id', 'purpose', 'target_asset_id', 'result_public_id', 'revision',
        'status', 'metadata_json', 'wrapped_dek', 'dek_nonce', 'dek_tag', 'key_version', 'job_token_hash',
        'progress_stage', 'processed_bytes', 'total_bytes', 'progress_percent', 'filename', 'mime_type',
        'plaintext_size_bytes', 'plaintext_sha256', 'ldg_chunk_size', 'output_size_bytes', 'output_sha256',
        'duration_ms', 'error_message', 'expires_at', 'completed_at',
    ];
}
