<?php

namespace App\Models;

use CodeIgniter\Model;

class MediaWorkspaceSettingModel extends Model
{
    protected $table = 'media_workspace_settings';
    protected $primaryKey = 'id';
    protected $returnType = 'object';
    protected $useTimestamps = true;
    protected $allowedFields = [
        'workspace_id', 'root_path', 'last_tested_at', 'last_test_status',
        'last_test_message', 'configured_by',
    ];
}
