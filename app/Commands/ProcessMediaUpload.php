<?php

namespace App\Commands;

use App\Libraries\MediaUploadFinalizer;
use App\Libraries\ResumableUploadService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Throwable;

final class ProcessMediaUpload extends BaseCommand
{
    protected $group = 'CMS';
    protected $name = 'uploads:process';
    protected $description = 'Processes one completed resumable media upload in the background.';
    protected $usage = 'uploads:process <session-id>';

    public function run(array $params): void
    {
        $publicId = trim((string) ($params[0] ?? ''));
        if (! preg_match('/^[a-f0-9-]{36}$/', $publicId)) {
            CLI::error('A valid upload session ID is required.');
            return;
        }

        $uploads = new ResumableUploadService();
        $session = $uploads->find($publicId);
        if ($session === null) {
            CLI::error('Upload session was not found.');
            return;
        }
        if ($session->status === 'completed') return;

        $processingStarted = false;
        try {
            @set_time_limit(0);
            $session = $uploads->beginProcessing($session);
            if ($session->status === 'completed') return;
            $processingStarted = true;
            $result = (new MediaUploadFinalizer())->process($uploads, $session);
            $uploads->complete($session, $result['public_id']);
            CLI::write('Media upload processing completed.', 'green');
        } catch (Throwable $error) {
            $latest = $uploads->find($publicId);
            if ($processingStarted && $latest !== null && $latest->status === 'processing') $uploads->fail($latest, $error->getMessage());
            log_message('error', 'Background media processing failed for {session}: {message}', ['session' => $publicId, 'message' => $error->getMessage()]);
            CLI::error($error->getMessage());
        }
    }
}
