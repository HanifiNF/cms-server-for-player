<?php

namespace App\Controllers\Web;

use App\Controllers\BaseController;
use App\Libraries\AssetTaxonomyService;
use App\Libraries\ExternalEncryptionJobService;
use CodeIgniter\HTTP\RedirectResponse;
use RuntimeException;
use Throwable;

class ExternalEncryptionController extends BaseController
{
    public function create(): RedirectResponse
    {
        try {
            $taxonomy = new AssetTaxonomyService();
            $genres = $taxonomy->validateGenreIds($this->request->getPost('genre_ids'));
            $title = trim((string) $this->request->getPost('title'));
            if ($title === '' || mb_strlen($title) > 255) throw new RuntimeException('Title is required for an external encryption job.');
            $assetType = trim((string) $this->request->getPost('asset_type'));
            if (! in_array($assetType, AssetTaxonomyService::TYPES, true)) throw new RuntimeException('Choose a valid asset type.');
            $year = trim((string) $this->request->getPost('production_year'));
            $metadata = [
                'title' => $title, 'asset_type' => $assetType, 'genre_ids' => $genres,
                'synopsis' => $this->nullable('synopsis', 5000), 'language' => $this->nullable('language', 80),
                'subtitles' => $this->nullable('subtitles', 160), 'age_rating' => $this->nullable('age_rating', 20),
                'production_year' => $year === '' ? null : (int) $year, 'release_date' => $this->nullable('release_date', 10),
                'expires_on' => $this->nullable('expires_on', 10), 'distributor_company' => $this->nullable('distributor_company', 180),
            ];
            $job = (new ExternalEncryptionJobService())->create((int) session()->get('cms_web_user_id'), $metadata);
            return redirect()->to('/control/library')->with('success', 'External encryption job created: ' . $job->public_id . '. Open Encryption Tool and sign in to process it.');
        } catch (Throwable $error) {
            return redirect()->to('/control/library')->withInput()->with('error', $error->getMessage());
        }
    }

    private function nullable(string $field, int $limit): ?string
    {
        $value = trim((string) $this->request->getPost($field));
        if ($value === '') return null;
        if (mb_strlen($value) > $limit) throw new RuntimeException(ucfirst(str_replace('_', ' ', $field)) . ' is too long.');
        return $value;
    }
}
