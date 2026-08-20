<?php

namespace App\Models;

use CodeIgniter\Model;

class AssetGenreModel extends Model
{
    protected $table = 'asset_genres';
    protected $primaryKey = ['asset_id', 'genre_id'];
    protected $returnType = 'array';
    protected $allowedFields = ['asset_id', 'genre_id'];
    protected $useTimestamps = false;
}
