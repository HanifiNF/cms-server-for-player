<?php

namespace App\Models;

use CodeIgniter\Model;

class MediaUploadSessionModel extends Model
{
    protected $table = 'media_upload_sessions';
    protected $primaryKey = 'id';
    protected $returnType = 'object';
    protected $useTimestamps = true;
    protected $allowedFields = [
        'public_id', 'owner_user_id', 'purpose', 'target_asset_id', 'fingerprint',
        'filename', 'mime_type', 'size_bytes', 'last_modified_ms', 'chunk_size_bytes',
        'received_bytes', 'next_chunk_index', 'status', 'staging_name', 'poster_name',
        'processing_stage', 'processing_bytes', 'processing_total_bytes', 'processing_percent',
        'processing_eta_seconds', 'processing_started_at', 'processing_heartbeat_at',
        'metadata_json', 'error_message', 'result_public_id', 'expires_at',
    ];
}
