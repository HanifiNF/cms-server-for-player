<?php

namespace App\Entities;

use CodeIgniter\Entity\Entity;

class User extends Entity
{
    protected $dates = ['last_login_at', 'created_at', 'updated_at'];
}
