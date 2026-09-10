<?php

namespace App\Commands;

use App\Libraries\ResumableUploadService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Throwable;

class CleanupMediaUploads extends BaseCommand
{
    protected $group = 'CMS';
    protected $name = 'uploads:cleanup';
    protected $description = 'Deletes resumable media uploads inactive for more than 24 hours.';

    public function run(array $params): void
    {
        try {
            $count = (new ResumableUploadService())->cleanupExpired();
            CLI::write("Removed {$count} expired media upload session(s).", 'green');
        } catch (Throwable $error) {
            CLI::error('Upload cleanup failed: ' . $error->getMessage());
        }
    }
}
