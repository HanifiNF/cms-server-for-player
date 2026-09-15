<?php

namespace App\Controllers\Api;

use App\Controllers\BaseController;
use App\Libraries\ExternalEncryptionJobService;
use App\Libraries\OperatorAuthException;
use App\Libraries\OperatorAuthService;
use App\Models\ExternalEncryptionJobModel;
use CodeIgniter\HTTP\ResponseInterface;
use RuntimeException;
use Throwable;

class ExternalEncryptionController extends BaseController
{
    public function index(): ResponseInterface
    {
        try {
            $auth = $this->operator();
            (new ExternalEncryptionJobService())->cleanupExpired();
            $jobs = (new ExternalEncryptionJobModel())->where('owner_user_id', $auth['user']->id)
                ->whereNotIn('status', ['cancelled', 'expired'])->orderBy('created_at', 'DESC')->findAll(100);
            return $this->ok(array_map(fn (object $job): array => $this->summary($job), $jobs));
        } catch (Throwable $error) { return $this->failure($error); }
    }

    public function claim(string $publicId): ResponseInterface
    {
        try {
            $this->assertSecureKeyTransport();
            $auth = $this->operator();
            $job = $this->find($publicId);
            $claimed = (new ExternalEncryptionJobService())->claim($job, (int) $auth['user']->id);
            return $this->ok([
                'job' => $this->summary($claimed['job']), 'job_token' => $claimed['token'], 'data_key' => $claimed['key'],
                'ldg' => ['format' => 'ldg-v1', 'header_size' => 128, 'chunk_size' => ExternalEncryptionJobService::CHUNK_SIZE],
            ]);
        } catch (Throwable $error) { return $this->failure($error); }
    }

    public function recover(string $publicId): ResponseInterface { return $this->claim($publicId); }

    public function progress(string $publicId): ResponseInterface
    {
        try {
            $service = new ExternalEncryptionJobService();
            $job = $service->authenticateJob($publicId, $this->request->getHeaderLine('Authorization'));
            return $this->ok($this->summary($service->progress($job, $this->request->getJSON(true) ?? [])));
        } catch (Throwable $error) { return $this->failure($error); }
    }

    public function finalize(string $publicId): ResponseInterface
    {
        try {
            $service = new ExternalEncryptionJobService();
            $job = $service->authenticateJob($publicId, $this->request->getHeaderLine('Authorization'));
            $asset = $service->finalize($job, $this->request->getJSON(true) ?? []);
            return $this->ok(['asset_id' => $asset->public_id, 'status' => $asset->status]);
        } catch (Throwable $error) { return $this->failure($error); }
    }

    public function cancel(string $publicId): ResponseInterface
    {
        try {
            $auth = $this->operator(); $job = $this->find($publicId);
            if ((int) $job->owner_user_id !== (int) $auth['user']->id) throw new RuntimeException('This job belongs to another operator.');
            if ((string) $job->status === 'completed') throw new RuntimeException('A completed job cannot be cancelled.');
            (new ExternalEncryptionJobModel())->update((int) $job->id, ['status' => 'cancelled', 'job_token_hash' => null]);
            return $this->ok(['cancelled' => true]);
        } catch (Throwable $error) { return $this->failure($error); }
    }

    private function operator(): array
    {
        return (new OperatorAuthService())->authenticate($this->request->getHeaderLine('Authorization'), ['admin', 'distributor']);
    }

    private function find(string $publicId): object
    {
        $job = (new ExternalEncryptionJobModel())->where('public_id', $publicId)->first();
        if ($job === null) throw new RuntimeException('Encryption job was not found.');
        return $job;
    }

    private function assertSecureKeyTransport(): void
    {
        if ($this->request->isSecure()) return;
        $host = strtolower(explode(':', $this->request->getUri()->getHost())[0]);
        if (ENVIRONMENT !== 'production' && in_array($host, ['localhost', '127.0.0.1', '::1'], true)) return;
        throw new RuntimeException('Encryption keys may only be claimed over HTTPS.');
    }

    private function summary(object $job): array
    {
        $metadata = json_decode((string) $job->metadata_json, true) ?: [];
        return [
            'id' => $job->public_id, 'asset_id' => $job->result_public_id, 'revision' => (int) $job->revision,
            'status' => $job->status, 'title' => (string) ($metadata['title'] ?? 'Untitled'),
            'stage' => $job->progress_stage, 'processed_bytes' => (int) $job->processed_bytes,
            'total_bytes' => (int) $job->total_bytes, 'percent' => (float) $job->progress_percent,
            'error' => $job->error_message, 'expires_at' => $job->expires_at, 'completed_at' => $job->completed_at,
        ];
    }

    private function ok(mixed $data): ResponseInterface { return $this->response->setHeader('Cache-Control', 'no-store')->setJSON(['data' => $data]); }

    private function failure(Throwable $error): ResponseInterface
    {
        $status = $error instanceof OperatorAuthException ? $error->httpStatus : (str_contains(strtolower($error->getMessage()), 'not found') ? 404 : 422);
        return $this->response->setStatusCode($status)->setJSON(['error' => ['message' => $error->getMessage()]]);
    }
}
