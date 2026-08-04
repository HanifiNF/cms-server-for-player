<?php

namespace App\Models;

use App\Entities\AuthSession;
use CodeIgniter\Model;

class AuthSessionModel extends Model
{
    protected $table = 'auth_sessions';
    protected $primaryKey = 'id';
    protected $returnType = AuthSession::class;
    protected $useTimestamps = true;
    protected $allowedFields = [
        'public_id', 'user_id', 'token_hash', 'expires_at', 'last_used_at',
        'revoked_at', 'ip_address', 'user_agent',
    ];
}
