<?php

namespace App\Models;

use App\Entities\User;
use CodeIgniter\Model;

class UserModel extends Model
{
    protected $table = 'users';
    protected $primaryKey = 'id';
    protected $returnType = User::class;
    protected $useTimestamps = true;
    protected $allowedFields = ['email', 'name', 'password_hash', 'role', 'status', 'last_login_at'];
}
