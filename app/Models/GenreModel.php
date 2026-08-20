<?php

namespace App\Models;

use App\Entities\Genre;
use CodeIgniter\Model;

class GenreModel extends Model
{
    protected $table = 'genres';
    protected $primaryKey = 'id';
    protected $returnType = Genre::class;
    protected $useTimestamps = true;
    protected $allowedFields = ['public_id', 'name', 'slug', 'status', 'created_by'];
}
