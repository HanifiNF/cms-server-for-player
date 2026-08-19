<?php

namespace App\Commands;

use App\Libraries\AssetExpiryService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Throwable;

class ExpireAssets extends BaseCommand
{
    protected $group = 'CMS';
    protected $name = 'assets:expire';
    protected $description = 'Expires films past their valid-through date and requests removal from assigned Players.';

    public function run(array $params): void
    {
        try {
            $result = (new AssetExpiryService())->expireDue();
            CLI::write(sprintf(
                'Expiry check %s: %d film(s) expired, %d assignment(s) queued for removal across %d Player(s).',
                $result['date'], $result['expired'], $result['assignments'], $result['devices'],
            ), 'green');
        } catch (Throwable $error) {
            CLI::error('Asset expiry failed: ' . $error->getMessage());
        }
    }
}
