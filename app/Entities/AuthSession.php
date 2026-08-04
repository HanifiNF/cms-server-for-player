<?php

namespace App\Entities;

use CodeIgniter\Entity\Entity;

class AuthSession extends Entity
{
    protected $dates = ['expires_at', 'last_used_at', 'revoked_at', 'created_at', 'updated_at'];
}
