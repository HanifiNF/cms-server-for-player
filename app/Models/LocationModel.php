<?php

namespace App\Models;

use App\Entities\Location;
use CodeIgniter\Model;

class LocationModel extends Model
{
    protected $table = 'locations';
    protected $primaryKey = 'id';
    protected $returnType = Location::class;
    protected $useTimestamps = true;
    protected $allowedFields = ['public_id', 'name', 'code', 'address', 'timezone', 'status'];
}
