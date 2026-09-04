<?php

namespace App\Controllers\Web;

use App\Controllers\BaseController;
use App\Libraries\AssetExpiryService;
use App\Libraries\AssetTaxonomyService;
use App\Libraries\CollectionPage;
use App\Libraries\DeviceEnrollmentService;
use App\Libraries\LocationService;
use App\Libraries\RealtimeOutboxService;
use App\Models\AssetModel;
use App\Models\DeviceAssetModel;
use App\Models\DeviceModel;
use App\Models\LocationModel;
use App\Models\UserModel;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Database;
use DateTimeZone;
use RuntimeException;
use Throwable;

class LocationController extends BaseController
{
    public function index(): string
    {
        $filters = $this->locationFilters();
        $total = (clone $this->filteredLocations($filters))->countAllResults();

        return view('web/locations', [
            'title' => 'Locations', 'active' => 'locations', 'admin' => $this->admin(),
            'locations' => [], 'locationTotal' => $total, 'filters' => $filters,
        ]);
    }

    public function collection(): ResponseInterface
    {
        $filters = $this->locationFilters();
        $total = $this->filteredLocations($filters)->countAllResults();
        $page = CollectionPage::fromQuery((array) $this->request->getGet(), $total, 20, 100);
        $query = $this->filteredLocations($filters);
        $locations = $query->orderBy('name')->findAll($page->perPage(), $page->offset());
        $locationIds = array_map(static fn (object $location): int => (int) $location->id, $locations);
        $devices = $locationIds === [] ? [] : (new DeviceModel())->whereIn('location_id', $locationIds)->orderBy('name')->findAll();
        $connection = new DeviceEnrollmentService();
        $byLocation = [];
        foreach ($devices as $device) {
            if ($device->location_id === null) continue;
            $byLocation[(int) $device->location_id][] = [
                'entity' => $device,
                'connection' => $device->status === 'active' ? $connection->connectionStatus($device) : $device->status,
            ];
        }
        $rows = [];
        foreach ($locations as $location) {
            $studios = $byLocation[(int) $location->id] ?? [];
            $rows[] = [
                'entity' => $location,
                'studios' => $studios,
                'total' => count($studios),
                'online' => count(array_filter($studios, static fn (array $item): bool => $item['connection'] === 'online')),
                'offline' => count(array_filter($studios, static fn (array $item): bool => $item['connection'] === 'offline')),
                'playing' => count(array_filter($studios, static fn (array $item): bool => $item['entity']->playback_state === 'playing')),
                'errors' => count(array_filter($studios, static fn (array $item): bool => $item['entity']->playback_state === 'error')),
            ];
        }
        $items = array_map(static fn (array $item): array => [
            'id' => (string) $item['entity']->public_id,
            'html' => view('web/_location_directory_card', compact('item')),
        ], $rows);

        return $this->response->setJSON(['data' => $page->payload($items)]);
    }

    public function create(): RedirectResponse
    {
        try {
            (new LocationService())->create($this->request->getPost());
            return redirect()->to('/control/locations')->with('success', 'Location created. Studios can now be assigned to it.');
        } catch (Throwable $error) {
            return redirect()->back()->withInput()->with('error', $error->getMessage());
        }
    }

    public function show(string $publicId): string|RedirectResponse
    {
        (new AssetExpiryService())->expireDue();
        $location = $this->location($publicId);
        if ($location === null) return redirect()->to('/control/locations')->with('error', 'Location was not found.');

        $operators = (new UserModel())->where('role', 'operator')->where('status', 'active')->orderBy('name')->findAll();
        $assignmentCounts = [];
        foreach ((new DeviceModel())->select('assigned_user_id, COUNT(*) AS studio_count')->where('assigned_user_id IS NOT NULL')->groupBy('assigned_user_id')->findAll() as $row) {
            $assignmentCounts[(int) $row->assigned_user_id] = (int) $row->studio_count;
        }
        $studioFilters = $this->studioFilters();
        $studioTotal = (clone $this->filteredStudios((int) $location->id, $studioFilters))->countAllResults();

        return view('web/location_detail', [
            'title' => $location->name, 'active' => 'locations', 'admin' => $this->admin(),
            'location' => $location, 'studios' => [], 'studioTotal' => $studioTotal, 'studioFilters' => $studioFilters, 'operators' => $operators,
            'assignmentCounts' => $assignmentCounts,
            'availableLocations' => (new LocationModel())->where('status', 'active')->orderBy('name')->findAll(),
            'assetTypes' => AssetTaxonomyService::TYPES,
        ]);
    }

    public function assetAssignmentCollection(string $publicId): ResponseInterface
    {
        (new AssetExpiryService())->expireDue();
        if ($this->location($publicId) === null) return $this->response->setStatusCode(404)->setJSON(['error' => ['message' => 'Location was not found.']]);
        $queryText = mb_substr(trim((string) $this->request->getGet('q')), 0, 120);
        $assetType = trim((string) $this->request->getGet('type'));
        if (! in_array($assetType, ['', ...AssetTaxonomyService::TYPES], true)) $assetType = '';
        $makeQuery = static function () use ($assetType, $queryText): AssetModel {
            $query = (new AssetModel())->where('status', 'active');
            if ($assetType !== '') $query->where('asset_type', $assetType);
            if ($queryText !== '') $query->groupStart()->like('title', $queryText)->orLike('filename', $queryText)
                ->orLike('distributor_company', $queryText)->orLike('genre', $queryText)->groupEnd();
            return $query;
        };
        $total = $makeQuery()->countAllResults();
        $page = CollectionPage::fromQuery((array) $this->request->getGet(), $total, 20, 100);
        $query = $makeQuery();
        $assets = $query->orderBy('title')->findAll($page->perPage(), $page->offset());
        $genres = (new AssetTaxonomyService())->mapForAssets(array_map(static fn (object $asset): int => (int) $asset->id, $assets));
        $items = array_map(static fn (object $asset): array => [
            'id' => (string) $asset->public_id,
            'html' => view('web/_studio_asset_option', ['asset' => $asset, 'genres' => $genres[(int) $asset->id] ?? []]),
        ], $assets);
        return $this->response->setJSON(['data' => $page->payload($items)]);
    }

    public function studioCollection(string $publicId): ResponseInterface
    {
        $location = $this->location($publicId);
        if ($location === null) return $this->response->setStatusCode(404)->setJSON(['error' => ['message' => 'Location was not found.']]);
        $filters = $this->studioFilters();
        $total = $this->filteredStudios((int) $location->id, $filters)->countAllResults();
        $page = CollectionPage::fromQuery((array) $this->request->getGet(), $total, 20, 100);
        $query = $this->filteredStudios((int) $location->id, $filters);
        $devices = $query->orderBy('name')->findAll($page->perPage(), $page->offset());
        $deviceIds = array_map(static fn (object $device): int => (int) $device->id, $devices);
        $operators = (new UserModel())->where('role', 'operator')->where('status', 'active')->orderBy('name')->findAll();
        $operatorNames = [];
        foreach ((new UserModel())->where('role', 'operator')->findAll() as $operator) $operatorNames[(int) $operator->id] = $operator->name;
        $assignmentCounts = [];
        foreach ((new DeviceModel())->select('assigned_user_id, COUNT(*) AS studio_count')->where('assigned_user_id IS NOT NULL')->groupBy('assigned_user_id')->findAll() as $row) {
            $assignmentCounts[(int) $row->assigned_user_id] = (int) $row->studio_count;
        }
        $assetCounts = [];
        $assignedAssets = [];
        if ($deviceIds !== []) {
            foreach ((new DeviceAssetModel())->select('device_id, COUNT(*) AS asset_count')->whereIn('device_id', $deviceIds)->groupBy('device_id')->findAll() as $row) {
                $assetCounts[(int) $row->device_id] = (int) $row->asset_count;
            }
            foreach (Database::connect()->table('device_assets da')->select('da.device_id, a.public_id')
                ->join('assets a', 'a.id = da.asset_id')->whereIn('da.device_id', $deviceIds)->get()->getResultArray() as $assignment) {
                $assignedAssets[(int) $assignment['device_id']][] = (string) $assignment['public_id'];
            }
        }
        $connection = new DeviceEnrollmentService();
        $availableLocations = (new LocationModel())->where('status', 'active')->orderBy('name')->findAll();
        $items = [];
        foreach ($devices as $device) {
            $item = [
                'entity' => $device,
                'connection' => $device->status === 'active' ? $connection->connectionStatus($device) : $device->status,
                'operatorName' => $operatorNames[(int) $device->assigned_user_id] ?? 'Unassigned',
                'assetCount' => $assetCounts[(int) $device->id] ?? 0,
                'assignedAssetIds' => $assignedAssets[(int) $device->id] ?? [],
            ];
            $items[] = ['id' => (string) $device->public_id, 'html' => view('web/_studio_management_row', compact('item', 'location', 'operators', 'assignmentCounts', 'availableLocations'))];
        }
        return $this->response->setJSON(['data' => $page->payload($items)]);
    }

    public function createStudio(string $publicId): RedirectResponse
    {
        $location = $this->location($publicId);
        if ($location === null) return redirect()->to('/control/locations')->with('error', 'Location was not found.');
        if ($location->status !== 'active') return $this->back($publicId, 'Inactive Locations cannot receive new Studios.');
        $name = trim((string) $this->request->getPost('name'));
        $timezone = trim((string) $this->request->getPost('timezone')) ?: $location->timezone;
        $assignedId = $this->operatorId($this->request->getPost('assigned_user_id'));
        if ($name === '' || mb_strlen($name) > 120) return $this->back($publicId, 'Studio name is required and must not exceed 120 characters.');
        if (! $this->validTimezone($timezone)) return $this->back($publicId, 'Timezone is invalid.');
        if ($assignedId === false) return $this->back($publicId, 'Choose an active operator.');

        try {
            $device = (new DeviceEnrollmentService())->createAssignableDevice($name, $timezone, $location->name, $assignedId, (int) $location->id);
            (new RealtimeOutboxService())->queueDevice((int) $device->id, 'studio.created');
            return redirect()->to($this->detailUrl($publicId))->with('success', 'Studio created. It is ready to be paired from its Player PC.');
        } catch (Throwable $error) {
            log_message('error', 'Location Studio creation failed: {message}', ['message' => $error->getMessage()]);
            return $this->back($publicId, 'The Studio could not be created.');
        }
    }

    public function updateStudio(string $publicId, string $devicePublicId): RedirectResponse
    {
        $device = $this->studio($publicId, $devicePublicId);
        if ($device === null) return $this->back($publicId, 'Studio was not found in this Location.');
        if ($device->status === 'revoked') return $this->back($publicId, 'A revoked Studio cannot be edited.');
        $name = trim((string) $this->request->getPost('name'));
        $timezone = trim((string) $this->request->getPost('timezone'));
        $targetPublicId = trim((string) $this->request->getPost('location_id')) ?: $publicId;
        $target = (new LocationModel())->where('public_id', $targetPublicId)->where('status', 'active')->first();
        if ($name === '' || mb_strlen($name) > 120) return $this->back($publicId, 'Studio name is required and must not exceed 120 characters.');
        if ($target === null) return $this->back($publicId, 'Choose an active Location.');
        $timezone = $timezone ?: $target->timezone;
        if (! $this->validTimezone($timezone)) return $this->back($publicId, 'Timezone is invalid.');

        try {
            if (! (new DeviceModel())->update($device->id, ['name' => $name, 'location_id' => $target->id, 'location' => $target->name, 'timezone' => $timezone])) throw new RuntimeException('Update failed.');
            (new RealtimeOutboxService())->queueDevice((int) $device->id, 'studio.updated');
            return redirect()->to($this->detailUrl($target->public_id))->with('success', 'Studio details updated.');
        } catch (Throwable $error) {
            return $this->back($publicId, 'Studio details could not be updated.');
        }
    }

    public function assignStudio(string $publicId, string $devicePublicId): RedirectResponse
    {
        $device = $this->studio($publicId, $devicePublicId);
        if ($device === null) return $this->back($publicId, 'Studio was not found in this Location.');
        if (! in_array($device->status, ['pending', 'active'], true)) return $this->back($publicId, 'A revoked Studio cannot be assigned. Reset its pairing first.');
        $assignedId = $this->operatorId($this->request->getPost('assigned_user_id'));
        if ($assignedId === false) return $this->back($publicId, 'Choose an active operator.');
        try {
            if (! (new DeviceModel())->update($device->id, ['assigned_user_id' => $assignedId])) throw new RuntimeException('Update failed.');
            (new RealtimeOutboxService())->queueDevice((int) $device->id, 'studio.assignment.changed');
            return redirect()->to($this->detailUrl($publicId))->with('success', $assignedId === null ? 'Studio is now unassigned.' : 'Studio operator updated.');
        } catch (Throwable $error) {
            return $this->back($publicId, 'Studio assignment could not be updated.');
        }
    }

    public function createOperator(string $publicId, string $devicePublicId): RedirectResponse
    {
        $device = $this->studio($publicId, $devicePublicId);
        if ($device === null) return $this->back($publicId, 'Studio was not found in this Location.');
        if (! in_array($device->status, ['pending', 'active'], true)) return $this->back($publicId, 'A revoked Studio cannot be assigned.');
        $name = trim((string) $this->request->getPost('name'));
        $email = mb_strtolower(trim((string) $this->request->getPost('email')));
        $password = (string) $this->request->getPost('password');
        $confirmation = (string) $this->request->getPost('password_confirmation');
        if ($name === '' || mb_strlen($name) > 120) return $this->back($publicId, 'Operator name is required and must not exceed 120 characters.');
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) return $this->back($publicId, 'Enter a valid operator email address.');
        if (mb_strlen($password) < 12) return $this->back($publicId, 'Operator password must contain at least 12 characters.');
        if ($password !== $confirmation) return $this->back($publicId, 'Operator password confirmation does not match.');
        if ((new UserModel())->where('email', $email)->first() !== null) return $this->back($publicId, 'That email address is already registered.');

        $db = Database::connect();
        try {
            $db->transStart();
            $userId = (new UserModel())->insert(['name' => $name, 'email' => $email, 'role' => 'operator', 'status' => 'active', 'password_hash' => password_hash($password, PASSWORD_ARGON2ID)], true);
            if ($userId === false || ! (new DeviceModel())->update($device->id, ['assigned_user_id' => $userId])) throw new RuntimeException('Create failed.');
            (new RealtimeOutboxService($db))->queueDevice((int) $device->id, 'studio.assignment.changed', ['operator_created' => true]);
            $db->transComplete();
            if (! $db->transStatus()) throw new RuntimeException('Transaction failed.');
            return redirect()->to($this->detailUrl($publicId))->with('success', 'Operator created and assigned. Pairing still happens from the Player PC.');
        } catch (Throwable $error) {
            $db->transRollback();
            return $this->back($publicId, 'The operator could not be created and assigned.');
        }
    }

    public function assignAssets(string $publicId, string $devicePublicId): RedirectResponse
    {
        (new AssetExpiryService())->expireDue();
        $location = $this->location($publicId);
        $device = $this->studio($publicId, $devicePublicId);
        if ($location === null || $device === null) return $this->back($publicId, 'Studio was not found in this Location.');
        if ($location->status !== 'active' || $device->status !== 'active') {
            return $this->back($publicId, 'Assets can only be assigned to an active, paired Studio in an active Location.');
        }

        $requested = $this->postedPublicIds('asset_ids');
        if ($requested === []) return $this->back($publicId, 'Choose at least one asset.');
        $available = [];
        foreach ((new AssetModel())->where('status', 'active')->findAll() as $asset) {
            $available[(string) $asset->public_id] = $asset;
        }
        $selected = [];
        foreach ($requested as $assetPublicId) {
            if (isset($available[$assetPublicId])) $selected[(int) $available[$assetPublicId]->id] = $available[$assetPublicId];
        }
        if ($selected === []) return $this->back($publicId, 'No active, unexpired asset matched this selection.');

        $model = new DeviceAssetModel();
        $existing = [];
        foreach ($model->where('device_id', $device->id)->findAll() as $assignment) {
            $existing[(string) $assignment->media_key] = $assignment;
        }

        $db = Database::connect();
        $assigned = 0;
        $alreadyAssigned = 0;
        $incompatible = 0;
        $db->transBegin();
        try {
            foreach ($selected as $asset) {
                if ($asset->encryption_format === 'ldg-v1' && $device->ldg_version !== 'ldg-v1') {
                    $incompatible++;
                    continue;
                }
                $mediaKey = 'managed:' . $asset->public_id;
                $current = $existing[$mediaKey] ?? null;
                if ($current !== null && $current->status !== 'removal_pending') {
                    $alreadyAssigned++;
                    continue;
                }
                $values = [
                    'device_id' => $device->id, 'asset_id' => $asset->id, 'media_key' => $mediaKey,
                    'source' => 'managed', 'title' => $asset->title, 'filename' => $asset->filename,
                    'relative_path' => $asset->filename, 'size_bytes' => $asset->size_bytes,
                    'duration_ms' => $asset->duration_ms, 'sha256' => $asset->sha256,
                    'status' => 'missing', 'last_reported_at' => gmdate('Y-m-d H:i:s'),
                ];
                $saved = $current ? $model->update($current->id, $values) : $model->insert($values, false);
                if ($saved === false) throw new RuntimeException('Studio asset assignment save failed.');
                $assigned++;
            }
            if ($assigned > 0) {
                $updated = $db->table('devices')->where('id', $device->id)
                    ->set('asset_revision', 'asset_revision + 1', false)
                    ->set('updated_at', gmdate('Y-m-d H:i:s'))->update();
                if (! $updated) throw new RuntimeException('Studio asset revision update failed.');
                (new RealtimeOutboxService($db))->queueDevice((int) $device->id, 'asset.revision.changed', [
                    'assigned_count' => $assigned, 'source' => 'studio.asset.assignment',
                ]);
            }
            if ($db->transStatus() === false) throw new RuntimeException('Studio asset assignment transaction failed.');
            $db->transCommit();
        } catch (Throwable $error) {
            $db->transRollback();
            log_message('error', 'Studio bulk asset assignment failed: {message}', ['message' => $error->getMessage()]);
            return $this->back($publicId, 'The selected assets could not be assigned. No partial assignment was kept.');
        }

        $parts = ["{$assigned} asset(s) assigned"];
        if ($alreadyAssigned > 0) $parts[] = "{$alreadyAssigned} already assigned";
        if ($incompatible > 0) $parts[] = "{$incompatible} incompatible asset(s) skipped";
        return redirect()->to($this->detailUrl($publicId))->with('success', implode('; ', $parts) . '.');
    }

    public function resetStudioPairing(string $publicId, string $devicePublicId): RedirectResponse
    {
        $device = $this->studio($publicId, $devicePublicId);
        if ($device === null) return $this->back($publicId, 'Studio was not found in this Location.');
        if (! in_array($device->status, ['active', 'revoked'], true)) return $this->back($publicId, 'This Studio is already waiting for pairing.');
        try {
            (new DeviceEnrollmentService())->resetPairing($device);
            (new RealtimeOutboxService())->queueDevice((int) $device->id, 'studio.pairing.reset');
            return redirect()->to($this->detailUrl($publicId))->with('success', 'Pairing reset. The assigned operator can pair this Studio from a Player PC.');
        } catch (Throwable $error) {
            return $this->back($publicId, 'Studio pairing could not be reset.');
        }
    }

    public function revokeStudio(string $publicId, string $devicePublicId): RedirectResponse
    {
        $device = $this->studio($publicId, $devicePublicId);
        if ($device === null) return $this->back($publicId, 'Studio was not found in this Location.');
        if ($device->status !== 'active') return $this->back($publicId, 'Only an active Studio can be revoked.');
        try {
            (new DeviceEnrollmentService())->revoke($device);
            (new RealtimeOutboxService())->queueDevice((int) $device->id, 'studio.revoked');
            return redirect()->to($this->detailUrl($publicId))->with('success', 'Studio revoked. Its Player will return to pairing when it contacts the CMS.');
        } catch (Throwable $error) {
            return $this->back($publicId, 'The Studio could not be revoked.');
        }
    }

    public function deleteStudio(string $publicId, string $devicePublicId): RedirectResponse
    {
        $device = $this->studio($publicId, $devicePublicId);
        if ($device === null) return $this->back($publicId, 'Studio was not found in this Location.');
        if (! in_array($device->status, ['pending', 'revoked'], true)) return $this->back($publicId, 'Reset or revoke an active Studio before deleting it.');
        try {
            (new RealtimeOutboxService())->queueDevice((int) $device->id, 'studio.deleted');
            if (! (new DeviceModel())->delete($device->id)) throw new RuntimeException('Delete failed.');
            return redirect()->to($this->detailUrl($publicId))->with('success', 'Studio permanently deleted.');
        } catch (Throwable $error) {
            return $this->back($publicId, 'The Studio could not be deleted.');
        }
    }

    public function update(string $publicId): RedirectResponse
    {
        $location = (new LocationModel())->where('public_id', $publicId)->first();
        if ($location === null) return redirect()->to('/control/locations')->with('error', 'Location was not found.');
        try {
            (new LocationService())->update($location, $this->request->getPost());
            return redirect()->to('/control/locations')->with('success', 'Location updated. Connected Players will receive the new name on heartbeat.');
        } catch (Throwable $error) {
            return redirect()->to('/control/locations')->with('error', $error->getMessage())->with('modal', 'edit-location-' . $publicId);
        }
    }

    public function status(string $publicId): RedirectResponse
    {
        $location = (new LocationModel())->where('public_id', $publicId)->first();
        if ($location === null) return redirect()->to('/control/locations')->with('error', 'Location was not found.');
        try {
            (new LocationService())->setStatus($location, (string) $this->request->getPost('status'));
            return redirect()->to('/control/locations')->with('success', 'Location status updated.');
        } catch (Throwable $error) {
            return redirect()->to('/control/locations')->with('error', $error->getMessage());
        }
    }

    public function delete(string $publicId): RedirectResponse
    {
        $location = (new LocationModel())->where('public_id', $publicId)->first();
        if ($location === null) return redirect()->to('/control/locations')->with('error', 'Location was not found.');
        try {
            (new LocationService())->delete($location);
            return redirect()->to('/control/locations')->with('success', 'Unused Location permanently deleted.');
        } catch (Throwable $error) {
            return redirect()->to('/control/locations')->with('error', $error->getMessage());
        }
    }

    /** @return array{q:string,status:string} */
    private function locationFilters(): array
    {
        $status = trim((string) $this->request->getGet('status'));
        if (! in_array($status, ['', 'active', 'inactive'], true)) $status = '';
        return ['q' => mb_substr(trim((string) $this->request->getGet('q')), 0, 120), 'status' => $status];
    }

    /** @param array{q:string,status:string} $filters */
    private function filteredLocations(array $filters): LocationModel
    {
        $query = new LocationModel();
        if ($filters['q'] !== '') {
            $query->groupStart()->like('name', $filters['q'])->orLike('code', $filters['q'])->orLike('address', $filters['q'])->groupEnd();
        }
        if ($filters['status'] !== '') $query->where('status', $filters['status']);
        return $query;
    }

    /** @return array{q:string,status:string} */
    private function studioFilters(): array
    {
        $status = trim((string) $this->request->getGet('studio_status'));
        if (! in_array($status, ['', 'pending', 'active', 'revoked'], true)) $status = '';
        return ['q' => mb_substr(trim((string) $this->request->getGet('studio_q')), 0, 120), 'status' => $status];
    }

    /** @param array{q:string,status:string} $filters */
    private function filteredStudios(int $locationId, array $filters): DeviceModel
    {
        $query = (new DeviceModel())->where('location_id', $locationId);
        if ($filters['q'] !== '') $query->groupStart()->like('name', $filters['q'])->orLike('public_id', $filters['q'])->groupEnd();
        if ($filters['status'] !== '') $query->where('status', $filters['status']);
        return $query;
    }

    private function admin(): object
    {
        return (new UserModel())->find((int) session()->get('cms_web_user_id'));
    }

    private function location(string $publicId): ?object
    {
        return (new LocationModel())->where('public_id', $publicId)->first();
    }

    private function studio(string $locationPublicId, string $devicePublicId): ?object
    {
        $location = $this->location($locationPublicId);
        if ($location === null) return null;
        return (new DeviceModel())->where('public_id', $devicePublicId)->where('location_id', $location->id)->first();
    }

    private function operatorId(mixed $value): int|null|false
    {
        $id = (int) $value;
        if ($id <= 0) return null;
        return (new UserModel())->where('id', $id)->where('role', 'operator')->where('status', 'active')->first() === null ? false : $id;
    }

    private function validTimezone(string $timezone): bool
    {
        return in_array($timezone, DateTimeZone::listIdentifiers(), true);
    }

    /** @return list<string> */
    private function postedPublicIds(string $field): array
    {
        $values = $this->request->getPost($field);
        if (! is_array($values)) return [];
        $ids = [];
        foreach (array_slice($values, 0, 250) as $value) {
            $id = trim((string) $value);
            if ($id !== '' && mb_strlen($id) <= 80) $ids[$id] = $id;
        }
        return array_values($ids);
    }

    private function detailUrl(string $publicId): string
    {
        return '/control/locations/' . rawurlencode($publicId);
    }

    private function back(string $publicId, string $error): RedirectResponse
    {
        return redirect()->to($this->detailUrl($publicId))->with('error', $error);
    }
}
