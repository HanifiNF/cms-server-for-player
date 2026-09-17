<?php

namespace App\Controllers\Web;

use App\Controllers\BaseController;
use App\Libraries\AssetTaxonomyService;
use App\Libraries\ExternalEncryptionJobService;
use CodeIgniter\HTTP\ResponseInterface;
use RuntimeException;
use Throwable;

class ExternalEncryptionController extends BaseController
{
    private const POSTER_MAX_BYTES = 10485760;

    public function create(): ResponseInterface
    {
        try {
            $input = $this->request->getPost();
            $input['creation_key'] ??= bin2hex(random_bytes(16));
            $job = (new ExternalEncryptionJobService())->saveMetadata((int) session()->get('cms_web_user_id'), $input, $this->request->getFile('poster'));
            if ($this->request->isAJAX()) {
                session()->setFlashdata('success', 'External encryption job created: ' . $job->public_id . '. Open Encryption Tool and sign in to process it.');
                return $this->response->setJSON([
                    'data' => ['job_id' => $job->public_id, 'redirect' => '/control/library'],
                    'csrf' => ['name' => csrf_token(), 'hash' => csrf_hash()],
                ]);
            }
            return redirect()->to('/control/library')->with('success', 'External encryption job created: ' . $job->public_id . '. Open Encryption Tool and sign in to process it.');
        } catch (Throwable $error) {
            if ($this->request->isAJAX()) {
                return $this->response->setStatusCode(422)->setJSON([
                    'error' => ['message' => $error->getMessage()],
                    'csrf' => ['name' => csrf_token(), 'hash' => csrf_hash()],
                ]);
            }
            return redirect()->to('/control/library')->withInput()->with('error', $error->getMessage());
        }
    }

}
