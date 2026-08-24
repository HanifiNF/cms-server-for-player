<?php

namespace App\Controllers\Web;

use App\Controllers\BaseController;
use App\Libraries\StorageManager;
use App\Libraries\StorageCredentialService;
use App\Libraries\Storage\FtpsStorageDriver;
use App\Models\StorageProfileModel;
use App\Models\UserModel;
use CodeIgniter\HTTP\RedirectResponse;
use Config\Database;
use RuntimeException;
use Throwable;

class StorageController extends BaseController
{
    public function index(): string
    {
        $profiles = [];
        $db = Database::connect();
        $manager = new StorageManager();
        foreach ((new StorageProfileModel())->orderBy('is_default', 'DESC')->orderBy('name')->findAll() as $profile) {
            try { $location = $manager->displayLocation($profile); }
            catch (Throwable $error) { $location = 'Unavailable: ' . $error->getMessage(); }
            $credentialUsername = '';
            if ($profile->driver === 'ftps') {
                try { $credentialUsername = (new StorageCredentialService())->decrypt($profile->credentials_encrypted ?? null)['username'] ?? ''; }
                catch (Throwable) { $credentialUsername = ''; }
            }
            $profiles[] = [
                'entity' => $profile,
                'assetCount' => $db->table('assets')->where('storage_profile_id', $profile->id)->countAllResults(),
                'versionCount' => $db->table('asset_versions')->where('storage_profile_id', $profile->id)->countAllResults(),
                'location' => $location,
                'config' => is_array($decoded = json_decode((string) $profile->config, true)) ? $decoded : [],
                'credentialUsername' => $credentialUsername,
            ];
        }
        return view('web/storage', ['title' => 'Storage Settings', 'active' => 'storage', 'admin' => $this->admin(), 'profiles' => $profiles]);
    }

    public function create(): RedirectResponse
    {
        $name = trim((string) $this->request->getPost('name'));
        $driver = trim((string) $this->request->getPost('driver'));
        try {
            if ($name === '' || mb_strlen($name) > 120) throw new RuntimeException('Storage name is required and must not exceed 120 characters.');
            if (! in_array($driver, ['local', 'ftps'], true)) throw new RuntimeException('That storage driver is not installed yet.');
            if ((new StorageProfileModel())->where('name', $name)->first() !== null) throw new RuntimeException('A storage profile already uses that name.');
            $publicId = $this->uuidV4();
            [$config, $credentials] = $this->profileConfiguration($driver, $publicId);
            $model = new StorageProfileModel();
            $id = $model->insert([
                'public_id' => $publicId, 'name' => $name, 'driver' => $driver,
                'status' => 'active', 'is_default' => false,
                'config' => json_encode($config, JSON_UNESCAPED_SLASHES),
                'credentials_encrypted' => $credentials === [] ? null : (new StorageCredentialService())->encrypt($credentials),
                'created_by' => (int) session()->get('cms_web_user_id'),
            ], true);
            if (! is_int($id)) throw new RuntimeException('The storage profile could not be created.');
            $test = (new StorageManager())->test($model->find($id));
            $model->update($id, ['last_tested_at' => gmdate('Y-m-d H:i:s'), 'last_test_status' => $test['ok'] ? 'healthy' : 'failed', 'last_test_message' => $test['message']]);
            return redirect()->to('/control/storage')->with($test['ok'] ? 'success' : 'error', $test['ok'] ? 'Storage profile created and its write access was verified.' : 'Storage profile created, but the connection test failed: ' . $test['message']);
        } catch (Throwable $error) {
            return redirect()->to('/control/storage')->withInput()->with('error', $error->getMessage())->with('modal', 'create-storage-modal');
        }
    }

    public function makeDefault(string $publicId): RedirectResponse
    {
        $model = new StorageProfileModel();
        $profile = $model->where('public_id', $publicId)->first();
        if ($profile === null || $profile->status !== 'active') return $this->backWithError('Choose an active storage profile.');
        $test = (new StorageManager())->test($profile);
        if (! $test['ok']) return $this->backWithError('The profile cannot become default because its connection test failed: ' . $test['message']);
        $db = Database::connect();
        try {
            $db->transBegin();
            $db->table('storage_profiles')->set('is_default', false)->update();
            if (! $model->update($profile->id, ['is_default' => true, 'last_tested_at' => gmdate('Y-m-d H:i:s'), 'last_test_status' => 'healthy', 'last_test_message' => $test['message']]) || $db->transStatus() === false) throw new RuntimeException('Default storage could not be changed.');
            $db->transCommit();
            return redirect()->to('/control/storage')->with('success', $profile->name . ' is now used for new uploads. Existing assets were not moved.');
        } catch (Throwable $error) {
            $db->transRollback();
            return $this->backWithError($error->getMessage());
        }
    }

    public function status(string $publicId): RedirectResponse
    {
        $model = new StorageProfileModel();
        $profile = $model->where('public_id', $publicId)->first();
        $status = trim((string) $this->request->getPost('status'));
        if ($profile === null || ! in_array($status, ['active', 'disabled'], true)) return $this->backWithError('The storage status change is invalid.');
        if ($profile->is_default && $status === 'disabled') return $this->backWithError('Set another active profile as default before disabling this one.');
        if (! $model->update($profile->id, ['status' => $status])) return $this->backWithError('Storage status could not be changed.');
        return redirect()->to('/control/storage')->with('success', 'Storage profile status updated.');
    }

    public function test(string $publicId): RedirectResponse
    {
        $model = new StorageProfileModel();
        $profile = $model->where('public_id', $publicId)->first();
        if ($profile === null) return $this->backWithError('Storage profile was not found.');
        try { $test = (new StorageManager())->test($profile); }
        catch (Throwable $error) { $test = ['ok' => false, 'message' => $error->getMessage()]; }
        $model->update($profile->id, ['last_tested_at' => gmdate('Y-m-d H:i:s'), 'last_test_status' => $test['ok'] ? 'healthy' : 'failed', 'last_test_message' => mb_substr($test['message'], 0, 500)]);
        return redirect()->to('/control/storage')->with($test['ok'] ? 'success' : 'error', $profile->name . ($test['ok'] ? ': ' : ' test failed: ') . $test['message']);
    }

    public function update(string $publicId): RedirectResponse
    {
        $model = new StorageProfileModel();
        $profile = $model->where('public_id', $publicId)->first();
        if ($profile === null || $profile->driver !== 'ftps') return $this->backWithError('Only an FTPS profile can be configured here.');
        try {
            $oldConfig = json_decode((string) $profile->config, true);
            if (! is_array($oldConfig)) throw new RuntimeException('The existing FTPS configuration is invalid.');
            $oldCredentials = (new StorageCredentialService())->decrypt($profile->credentials_encrypted ?? null);
            [$config, $credentials] = $this->profileConfiguration('ftps', (string) $profile->public_id, $oldCredentials);
            $db = Database::connect();
            $references = $db->table('assets')->where('storage_profile_id', $profile->id)->countAllResults()
                + $db->table('asset_versions')->where('storage_profile_id', $profile->id)->countAllResults();
            foreach (['host', 'mode', 'port', 'remote_root'] as $field) {
                if ($references > 0 && (string) ($oldConfig[$field] ?? '') !== (string) ($config[$field] ?? '')) {
                    throw new RuntimeException('Host, mode, port, and remote root cannot change while assets or revisions still reference this profile.');
                }
            }
            $runtimeConfig = $config;
            $runtimeConfig['_profile_id'] = (string) $profile->public_id;
            $test = (new FtpsStorageDriver($runtimeConfig, $credentials))->testConnection();
            if (! $test['ok']) throw new RuntimeException('Updated FTPS settings were not saved because the connection test failed: ' . $test['message']);
            if (! $model->update($profile->id, [
                'config' => json_encode($config, JSON_UNESCAPED_SLASHES),
                'credentials_encrypted' => (new StorageCredentialService())->encrypt($credentials),
                'last_tested_at' => gmdate('Y-m-d H:i:s'), 'last_test_status' => 'healthy',
                'last_test_message' => $test['message'],
            ])) throw new RuntimeException('The FTPS profile could not be updated.');
            return redirect()->to('/control/storage')->with('success', 'FTPS configuration and encrypted credentials updated after a successful connection test.');
        } catch (Throwable $error) {
            return redirect()->to('/control/storage')->with('error', $error->getMessage());
        }
    }

    public function delete(string $publicId): RedirectResponse
    {
        $model = new StorageProfileModel();
        $profile = $model->where('public_id', $publicId)->first();
        if ($profile === null) return $this->backWithError('Storage profile was not found.');
        if ($profile->is_default) return $this->backWithError('The default storage profile cannot be deleted.');
        $db = Database::connect();
        $references = $db->table('assets')->where('storage_profile_id', $profile->id)->countAllResults() + $db->table('asset_versions')->where('storage_profile_id', $profile->id)->countAllResults();
        if ($references > 0) return $this->backWithError('This profile is still referenced by assets or revision history and cannot be deleted.');
        if (! $model->delete($profile->id)) return $this->backWithError('Storage profile could not be deleted.');
        return redirect()->to('/control/storage')->with('success', 'Unused storage profile deleted. Stored files were not removed automatically.');
    }

    private function backWithError(string $message): RedirectResponse { return redirect()->to('/control/storage')->with('error', $message); }
    private function admin(): object { return (new UserModel())->find((int) session()->get('cms_web_user_id')); }

    private function uuidV4(): string
    {
        $bytes = random_bytes(16); $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40); $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return sprintf('%s-%s-%s-%s-%s', substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 12, 4), substr($hex, 16, 4), substr($hex, 20));
    }

    /** @return array{array<string, mixed>, array<string, string>} */
    /** @param array<string, string> $existingCredentials */
    private function profileConfiguration(string $driver, string $publicId, array $existingCredentials = []): array
    {
        if ($driver === 'local') {
            $root = trim(str_replace('\\', '/', (string) $this->request->getPost('root')), '/');
            if ($root === '' || str_contains($root, '..') || ! preg_match('#^[A-Za-z0-9._/-]+$#', $root)) throw new RuntimeException('Use a safe path relative to the CMS writable directory.');
            return [['root' => $root], []];
        }
        $cacheGb = trim((string) $this->request->getPost('cache_max_gb'));
        $cacheBytes = filter_var($cacheGb, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1024]]);
        if ($cacheBytes === false) throw new RuntimeException('FTPS cache capacity must be between 1 and 1024 GB.');
        $config = FtpsStorageDriver::normalizeConfig([
            '_profile_id' => $publicId,
            'host' => $this->request->getPost('host'),
            'mode' => $this->request->getPost('mode'),
            'port' => $this->request->getPost('port'),
            'remote_root' => $this->request->getPost('remote_root'),
            'passive' => $this->request->getPost('passive') !== null,
            'connect_timeout' => $this->request->getPost('connect_timeout'),
            'transfer_timeout' => $this->request->getPost('transfer_timeout'),
            'cache_ttl_seconds' => $this->request->getPost('cache_ttl_seconds'),
            'cache_max_bytes' => (int) $cacheBytes * 1073741824,
            'ca_bundle' => $this->request->getPost('ca_bundle'),
            'client_certificate' => $this->request->getPost('client_certificate'),
            'client_key' => $this->request->getPost('client_key'),
            'pinned_public_key' => $this->request->getPost('pinned_public_key'),
        ]);
        unset($config['_profile_id']);
        $submittedPassword = (string) $this->request->getPost('password');
        $submittedKeyPassword = (string) $this->request->getPost('client_key_password');
        $credentials = [
            'username' => trim((string) $this->request->getPost('username')) ?: (string) ($existingCredentials['username'] ?? ''),
            'password' => $submittedPassword !== '' ? $submittedPassword : (string) ($existingCredentials['password'] ?? ''),
            'client_key_password' => $submittedKeyPassword !== '' ? $submittedKeyPassword : (string) ($existingCredentials['client_key_password'] ?? ''),
        ];
        if ($credentials['username'] === '' || $credentials['password'] === '') throw new RuntimeException('FTPS username and password are required.');
        return [$config, $credentials];
    }
}
