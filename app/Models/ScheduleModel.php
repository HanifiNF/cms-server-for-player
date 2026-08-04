<?php

namespace App\Models;

use App\Entities\Schedule;
use CodeIgniter\Model;

class ScheduleModel extends Model
{
    protected $table = 'schedules';
    protected $primaryKey = 'id';
    protected $returnType = Schedule::class;
    protected $useTimestamps = true;
    protected $allowedFields = [
        'public_id', 'title', 'description', 'start_at', 'end_at', 'timezone',
        'recurrence', 'recurrence_config', 'status', 'priority', 'loop_enabled',
        'revision', 'created_by',
    ];
}
