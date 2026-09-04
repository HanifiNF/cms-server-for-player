<?php

namespace App\Controllers\Web;

use App\Controllers\BaseController;
use App\Libraries\AssetExpiryService;
use App\Libraries\AssetTaxonomyService;
use App\Libraries\CollectionPage;
use App\Models\AssetModel;
use App\Models\AssetVersionModel;
use App\Models\DeviceAssetModel;
use App\Models\DeviceModel;
use App\Models\LocationModel;
use App\Models\UserModel;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Database;

class MediaLibraryController extends BaseController
{
    public function index(): string
    {
        (new AssetExpiryService())->expireDue();
        $data = $this->catalogContext();
        $data['catalogTotal'] = (clone $this->filteredCatalogQuery($data['admin'], $data['filters']))->countAllResults();
        return view('web/media_library', $data);
    }

    public function collection(): ResponseInterface
    {
        (new AssetExpiryService())->expireDue();
        $data = $this->catalogContext();
        $total = $this->filteredCatalogQuery($data['admin'], $data['filters'])->countAllResults();
        $page = CollectionPage::fromQuery((array) $this->request->getGet(), $total, 20, 100);
        $query = $this->filteredCatalogQuery($data['admin'], $data['filters']);
        $data['assets'] = $query->orderBy('assets.created_at', 'DESC')->findAll($page->perPage(), $page->offset());
        $assetIds = array_map(static fn (object $asset): int => (int) $asset->id, $data['assets']);
        $data['genreMap'] = (new AssetTaxonomyService())->mapForAssets($assetIds);
        $data['assignmentCounts'] = $this->countMap('device_assets', 'asset_id', 'studio_count', false, $assetIds);
        $data['scheduleCounts'] = $this->countMap('schedule_items', 'asset_id', 'schedule_count', true, $assetIds);
        $data['locationCounts'] = [];
        if ($assetIds !== []) {
            foreach (Database::connect()->table('device_assets da')->select('da.asset_id, COUNT(DISTINCT d.location_id) AS location_count', false)
                ->join('devices d', 'd.id = da.device_id')->whereIn('da.asset_id', $assetIds)->groupBy('da.asset_id')->get()->getResultArray() as $row) {
                $data['locationCounts'][(int) $row['asset_id']] = (int) $row['location_count'];
            }
        }
        $items = [];
        foreach ($data['assets'] as $asset) {
            $items[] = [
                'id' => (string) $asset->public_id,
                'html' => view('web/_media_library_card', [...$data, 'asset' => $asset]),
            ];
        }

        return $this->response->setJSON(['data' => $page->payload($items)]);
    }

    /** @return array<string,mixed> */
    private function catalogContext(): array
    {
        $currentUser = $this->admin();
        $isAdmin = $currentUser->role === 'admin';
        $taxonomy = new AssetTaxonomyService();
        $search = trim((string) $this->request->getGet('q'));
        $status = trim((string) $this->request->getGet('status'));
        $type = trim((string) $this->request->getGet('type'));
        $genre = trim((string) $this->request->getGet('genre'));
        $availability = trim((string) $this->request->getGet('availability'));
        $distributor = $isAdmin ? max(0, (int) $this->request->getGet('distributor')) : 0;
        if (! in_array($status, ['', 'draft', 'active', 'rejected', 'expired'], true)) $status = '';
        if (! in_array($type, ['', ...AssetTaxonomyService::TYPES], true)) $type = '';
        if (! in_array($availability, ['', 'available', 'unassigned'], true)) $availability = '';
        $statusCounts = ['total' => 0, 'draft' => 0, 'active' => 0, 'rejected' => 0, 'expired' => 0];
        $statusQuery = (new AssetModel())->select('status, COUNT(*) AS status_count', false);
        if (! $isAdmin) $statusQuery->where('created_by', $currentUser->id);
        foreach ($statusQuery->groupBy('status')->findAll() as $row) {
            $count = (int) $row->status_count;
            $statusCounts['total'] += $count;
            if (isset($statusCounts[$row->status])) $statusCounts[$row->status] = $count;
        }
        $expiry = new AssetExpiryService();

        return [
            'title' => 'Media Library', 'active' => 'library', 'admin' => $currentUser, 'isAdmin' => $isAdmin,
            'assets' => [], 'genres' => $taxonomy->genres(), 'activeGenres' => $taxonomy->genres(true), 'genreMap' => [],
            'assetTypes' => AssetTaxonomyService::TYPES, 'assignmentCounts' => [], 'locationCounts' => [],
            'totalActiveLocations' => (new LocationModel())->where('status', 'active')->countAllResults(),
            'scheduleCounts' => [], 'userNames' => [],
            'distributors' => $isAdmin ? (new UserModel())->where('role', 'distributor')->orderBy('name')->findAll() : [],
            'statusCounts' => $statusCounts, 'catalogToday' => $expiry->today(), 'today' => $expiry->today(),
            'filters' => compact('search', 'status', 'type', 'genre', 'availability', 'distributor'),
        ];
    }

    /** @param array{search:string,status:string,type:string,genre:string,availability:string,distributor:int} $filters */
    private function filteredCatalogQuery(object $currentUser, array $filters): AssetModel
    {
        $query = (new AssetModel())->select('assets.*');
        if ($currentUser->role !== 'admin') $query->where('assets.created_by', $currentUser->id);
        if ($filters['status'] !== '') $query->where('assets.status', $filters['status']);
        if ($filters['type'] !== '') $query->where('assets.asset_type', $filters['type']);
        if ($filters['distributor'] > 0) $query->where('assets.created_by', $filters['distributor']);
        if ($filters['genre'] !== '') {
            $query->whereIn('assets.id', Database::connect()->table('asset_genres')->select('asset_id')->where('genre_id', (int) $filters['genre']));
        }
        if ($filters['availability'] !== '') {
            $assignedIds = Database::connect()->table('device_assets')->select('asset_id')->where('asset_id IS NOT NULL');
            if ($filters['availability'] === 'available') $query->whereIn('assets.id', $assignedIds);
            else $query->whereNotIn('assets.id', $assignedIds);
        }
        if ($filters['search'] !== '') {
            $genreIds = Database::connect()->table('asset_genres ag')->select('ag.asset_id')->join('genres g', 'g.id = ag.genre_id')->like('g.name', $filters['search']);
            $query->groupStart()->like('assets.title', $filters['search'])->orLike('assets.filename', $filters['search'])
                ->orLike('assets.distributor_company', $filters['search'])->orLike('assets.asset_type', $filters['search'])
                ->orWhereIn('assets.id', $genreIds)->groupEnd();
        }
        return $query;
    }

    public function show(string $publicId): string|RedirectResponse
    {
        (new AssetExpiryService())->expireDue();
        $currentUser = $this->admin();
        $isAdmin = $currentUser->role === 'admin';
        $assetQuery = (new AssetModel())->where('public_id', $publicId);
        if (! $isAdmin) $assetQuery->where('created_by', $currentUser->id);
        $asset = $assetQuery->first();
        if ($asset === null) return redirect()->to('/control/library')->with('error', 'Media asset was not found.');
        $db = Database::connect();
        $assignments = $db->table('device_assets da')
            ->select('da.status, da.created_at AS assigned_at, da.last_reported_at, d.id AS device_id, d.public_id AS device_public_id, d.name AS device_name, d.status AS device_status, d.ldg_version, l.id AS location_id, l.public_id AS location_public_id, l.name AS location_name, l.status AS location_status')
            ->join('devices d', 'd.id = da.device_id')->join('locations l', 'l.id = d.location_id', 'left')
            ->where('da.asset_id', $asset->id)->orderBy('l.name')->orderBy('d.name')->get()->getResultArray();
        $assignmentTotal = count($assignments);
        $scheduleTotal = $db->table('schedule_items')->where('asset_id', $asset->id)->select('schedule_id')->distinct()->countAllResults();
        $versionTotal = (new AssetVersionModel())->where('asset_id', $asset->id)->countAllResults();
        $uploader = (new UserModel())->find((int) $asset->created_by);
        $names = $uploader === null ? [] : [(int) $uploader->id => $uploader->name];
        $taxonomy = new AssetTaxonomyService();
        $genreMap = $taxonomy->mapForAssets([(int) $asset->id]);
        $distributionLocations = [];
        $globalAssignableCount = 0;
        if ($isAdmin && $asset->status === 'active') {
            $distributionLocations = $this->distributionTree($asset, $assignments);
            foreach ($distributionLocations as $location) {
                foreach ($location['studios'] as $studio) {
                    if ($studio['assignable'] && ! $studio['assigned']) $globalAssignableCount++;
                }
            }
        }
        return view('web/media_library_detail', [
            'title' => $asset->title, 'active' => 'library', 'admin' => $currentUser, 'isAdmin' => $isAdmin,
            'asset' => $asset, 'genres' => $genreMap[(int) $asset->id] ?? [],
            'allGenres' => $taxonomy->genres(true), 'assetTypes' => AssetTaxonomyService::TYPES,
            'assignments' => $assignments, 'assignmentTotal' => $assignmentTotal,
            'distributionLocations' => $distributionLocations, 'globalAssignableCount' => $globalAssignableCount,
            'scheduleTotal' => $scheduleTotal, 'versionTotal' => $versionTotal,
            'userNames' => $names, 'catalogToday' => (new AssetExpiryService())->today(),
        ]);
    }

    public function versionCollection(string $publicId): ResponseInterface
    {
        $asset = $this->visibleAsset($publicId);
        if ($asset === null) return $this->response->setStatusCode(404)->setJSON(['error' => ['message' => 'Media asset was not found.']]);
        $total = (new AssetVersionModel())->where('asset_id', $asset->id)->countAllResults();
        $page = CollectionPage::fromQuery((array) $this->request->getGet(), $total, 10, 50);
        $versions = (new AssetVersionModel())->where('asset_id', $asset->id)->orderBy('revision', 'DESC')->findAll($page->perPage(), $page->offset());
        $userIds = array_values(array_unique(array_filter(array_map(static fn (object $version): int => (int) $version->submitted_by, $versions))));
        $names = [];
        if ($userIds !== []) foreach ((new UserModel())->whereIn('id', $userIds)->findAll() as $user) $names[(int) $user->id] = $user->name;
        $items = array_map(static fn (object $version): array => [
            'id' => (int) $version->id,
            'html' => view('web/_media_version_row', ['version' => $version, 'userNames' => $names]),
        ], $versions);
        return $this->response->setJSON(['data' => $page->payload($items)]);
    }

    public function assignmentCollection(string $publicId): ResponseInterface
    {
        $asset = $this->visibleAsset($publicId);
        if ($asset === null) return $this->response->setStatusCode(404)->setJSON(['error' => ['message' => 'Media asset was not found.']]);
        $total = (new DeviceAssetModel())->where('asset_id', $asset->id)->countAllResults();
        $page = CollectionPage::fromQuery((array) $this->request->getGet(), $total, 10, 50);
        $rows = Database::connect()->table('device_assets da')
            ->select('da.id, da.status, da.created_at AS assigned_at, d.name AS device_name, l.name AS location_name')
            ->join('devices d', 'd.id = da.device_id')->join('locations l', 'l.id = d.location_id', 'left')
            ->where('da.asset_id', $asset->id)->orderBy('l.name')->orderBy('d.name')
            ->limit($page->perPage(), $page->offset())->get()->getResultArray();
        $items = array_map(static fn (array $row): array => [
            'id' => (int) $row['id'], 'html' => view('web/_media_assignment_row', compact('row')),
        ], $rows);
        return $this->response->setJSON(['data' => $page->payload($items)]);
    }

    public function scheduleCollection(string $publicId): ResponseInterface
    {
        $asset = $this->visibleAsset($publicId);
        if ($asset === null) return $this->response->setStatusCode(404)->setJSON(['error' => ['message' => 'Media asset was not found.']]);
        $makeQuery = static fn () => Database::connect()->table('schedule_items si')->distinct()
            ->select('s.public_id, s.title, s.status, s.start_at, s.end_at, s.recurrence, d.public_id AS device_public_id, d.name AS device_name, l.name AS location_name')
            ->join('schedules s', 's.id = si.schedule_id')->join('schedule_targets st', 'st.schedule_id = s.id')
            ->join('devices d', 'd.id = st.device_id')->join('locations l', 'l.id = d.location_id', 'left')
            ->where('si.asset_id', $asset->id);
        $total = $makeQuery()->get()->getNumRows();
        $page = CollectionPage::fromQuery((array) $this->request->getGet(), $total, 10, 50);
        $rows = $makeQuery()->orderBy('s.start_at', 'DESC')->limit($page->perPage(), $page->offset())->get()->getResultArray();
        $items = array_map(static fn (array $row): array => [
            'id' => $row['public_id'] . ':' . $row['device_public_id'],
            'html' => view('web/_media_schedule_row', compact('row')),
        ], $rows);
        return $this->response->setJSON(['data' => $page->payload($items)]);
    }

    private function visibleAsset(string $publicId): ?object
    {
        $currentUser = $this->admin();
        $query = (new AssetModel())->where('public_id', $publicId);
        if ($currentUser->role !== 'admin') $query->where('created_by', $currentUser->id);
        return $query->first();
    }

    /**
     * @param list<array<string,mixed>> $assignments
     * @return list<array{public_id:?string,name:string,status:string,studios:list<array<string,mixed>>}>
     */
    private function distributionTree(object $asset, array $assignments): array
    {
        $assignedByDevice = [];
        foreach ($assignments as $assignment) $assignedByDevice[(int) $assignment['device_id']] = $assignment;

        $locations = [];
        foreach ((new LocationModel())->orderBy('name')->findAll() as $location) {
            $locations[(int) $location->id] = [
                'public_id' => (string) $location->public_id,
                'name' => (string) $location->name,
                'status' => (string) $location->status,
                'studios' => [],
            ];
        }
        $withoutLocation = ['public_id' => null, 'name' => 'No Location', 'status' => 'inactive', 'studios' => []];
        foreach ((new DeviceModel())->orderBy('name')->findAll() as $device) {
            $assignment = $assignedByDevice[(int) $device->id] ?? null;
            $locationActive = $device->location_id !== null && isset($locations[(int) $device->location_id])
                && $locations[(int) $device->location_id]['status'] === 'active';
            $compatible = $asset->encryption_format !== 'ldg-v1' || $device->ldg_version === 'ldg-v1';
            $studio = [
                'public_id' => (string) $device->public_id,
                'name' => (string) $device->name,
                'status' => (string) $device->status,
                'assigned' => $assignment !== null,
                'assignment_status' => $assignment['status'] ?? null,
                'compatible' => $compatible,
                'assignable' => $device->status === 'active' && $locationActive && $compatible,
            ];
            if ($device->location_id !== null && isset($locations[(int) $device->location_id])) {
                $locations[(int) $device->location_id]['studios'][] = $studio;
            } elseif ($assignment !== null || $device->status === 'active') {
                $withoutLocation['studios'][] = $studio;
            }
        }

        $tree = [];
        foreach ($locations as $location) {
            $hasAssignment = count(array_filter($location['studios'], static fn (array $studio): bool => $studio['assigned'])) > 0;
            if ($location['status'] === 'active' || $hasAssignment) $tree[] = $location;
        }
        if ($withoutLocation['studios'] !== []) $tree[] = $withoutLocation;
        return $tree;
    }

    /** @return array<int,int> */
    private function countMap(string $table, string $key, string $alias, bool $distinct = false, array $ids = []): array
    {
        if ($ids === []) return [];
        $expression = $distinct ? "COUNT(DISTINCT schedule_id) AS {$alias}" : "COUNT(*) AS {$alias}";
        $rows = Database::connect()->table($table)->select("{$key}, {$expression}", false)->whereIn($key, $ids)->groupBy($key)->get()->getResultArray();
        $map = [];
        foreach ($rows as $row) $map[(int) $row[$key]] = (int) $row[$alias];
        return $map;
    }

    private function admin(): object
    {
        return (new UserModel())->find((int) session()->get('cms_web_user_id'));
    }
}
