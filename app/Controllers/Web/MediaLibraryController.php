<?php

namespace App\Controllers\Web;

use App\Controllers\BaseController;
use App\Libraries\AssetExpiryService;
use App\Libraries\AssetTaxonomyService;
use App\Models\AssetModel;
use App\Models\AssetVersionModel;
use App\Models\DeviceModel;
use App\Models\LocationModel;
use App\Models\UserModel;
use CodeIgniter\HTTP\RedirectResponse;
use Config\Database;

class MediaLibraryController extends BaseController
{
    public function index(): string
    {
        $expiry = new AssetExpiryService();
        $expiry->expireDue();
        $currentUser = $this->admin();
        $isAdmin = $currentUser->role === 'admin';
        $assetQuery = (new AssetModel())->orderBy('created_at', 'DESC');
        if (! $isAdmin) $assetQuery->where('created_by', $currentUser->id);
        $scopedAssets = $assetQuery->findAll();
        $taxonomy = new AssetTaxonomyService();
        $genreMap = $taxonomy->mapForAssets(array_map(static fn ($asset): int => (int) $asset->id, $scopedAssets));
        $assignmentCounts = $this->countMap('device_assets', 'asset_id', 'studio_count');
        $locationCounts = [];
        foreach (Database::connect()->table('device_assets da')->select('da.asset_id, COUNT(DISTINCT d.location_id) AS location_count', false)
            ->join('devices d', 'd.id = da.device_id')->where('da.asset_id IS NOT NULL')->groupBy('da.asset_id')->get()->getResultArray() as $row) {
            $locationCounts[(int) $row['asset_id']] = (int) $row['location_count'];
        }
        $scheduleCounts = $this->countMap('schedule_items', 'asset_id', 'schedule_count', true);
        $users = [];
        $distributors = [];
        foreach ((new UserModel())->findAll() as $user) {
            $users[(int) $user->id] = $user->name;
            if ($user->role === 'distributor') $distributors[] = $user;
        }
        $statusCounts = ['total' => count($scopedAssets), 'draft' => 0, 'active' => 0, 'rejected' => 0, 'expired' => 0];
        foreach ($scopedAssets as $asset) {
            if (isset($statusCounts[$asset->status])) $statusCounts[$asset->status]++;
        }

        $search = trim((string) $this->request->getGet('q'));
        $status = trim((string) $this->request->getGet('status'));
        $type = trim((string) $this->request->getGet('type'));
        $genre = trim((string) $this->request->getGet('genre'));
        $availability = trim((string) $this->request->getGet('availability'));
        $distributor = $isAdmin ? max(0, (int) $this->request->getGet('distributor')) : 0;
        if (! in_array($status, ['', 'draft', 'active', 'rejected', 'expired'], true)) $status = '';
        if (! in_array($type, ['', ...AssetTaxonomyService::TYPES], true)) $type = '';
        if (! in_array($availability, ['', 'available', 'unassigned'], true)) $availability = '';
        $filtered = array_values(array_filter($scopedAssets, static function ($asset) use ($search, $status, $type, $genre, $availability, $distributor, $genreMap, $assignmentCounts): bool {
            if ($status !== '' && $asset->status !== $status) return false;
            if ($type !== '' && ($asset->asset_type ?: 'featured') !== $type) return false;
            if ($distributor > 0 && (int) $asset->created_by !== $distributor) return false;
            $assetGenres = $genreMap[(int) $asset->id] ?? [];
            if ($genre !== '' && ! in_array($genre, array_map(static fn (array $item): string => (string) $item['id'], $assetGenres), true)) return false;
            $assigned = ($assignmentCounts[(int) $asset->id] ?? 0) > 0;
            if ($availability === 'available' && ! $assigned) return false;
            if ($availability === 'unassigned' && $assigned) return false;
            if ($search === '') return true;
            return mb_stripos(implode(' ', [$asset->title, $asset->filename, $asset->distributor_company, $asset->asset_type, ...array_column($assetGenres, 'name')]), $search) !== false;
        }));

        return view('web/media_library', [
            'title' => 'Media Library', 'active' => 'library', 'admin' => $currentUser, 'isAdmin' => $isAdmin,
            'assets' => $filtered, 'genres' => $taxonomy->genres(), 'activeGenres' => $taxonomy->genres(true), 'genreMap' => $genreMap,
            'assetTypes' => AssetTaxonomyService::TYPES, 'assignmentCounts' => $assignmentCounts,
            'locationCounts' => $locationCounts,
            'totalActiveLocations' => (new LocationModel())->where('status', 'active')->countAllResults(),
            'scheduleCounts' => $scheduleCounts, 'userNames' => $users, 'distributors' => $distributors,
            'statusCounts' => $statusCounts, 'catalogToday' => $expiry->today(), 'today' => $expiry->today(),
            'filters' => compact('search', 'status', 'type', 'genre', 'availability', 'distributor'),
        ]);
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
        $assignmentLocationGroups = $this->assignmentLocationGroups($assignments);
        $assignmentPages = max(1, (int) ceil(count($assignmentLocationGroups) / 5));
        $assignmentPage = max(1, min($assignmentPages, (int) ($this->request->getGet('assignment_page') ?: 1)));
        $assignmentPageGroups = array_slice($assignmentLocationGroups, ($assignmentPage - 1) * 5, 5);
        $schedules = $db->table('schedule_items si')->distinct()
            ->select('s.public_id, s.title, s.status, s.start_at, s.end_at, s.recurrence, d.name AS device_name, l.name AS location_name')
            ->join('schedules s', 's.id = si.schedule_id')->join('schedule_targets st', 'st.schedule_id = s.id')
            ->join('devices d', 'd.id = st.device_id')->join('locations l', 'l.id = d.location_id', 'left')
            ->where('si.asset_id', $asset->id)->orderBy('s.start_at', 'DESC')->get()->getResultArray();
        $names = [];
        foreach ((new UserModel())->findAll() as $user) $names[(int) $user->id] = $user->name;
        $taxonomy = new AssetTaxonomyService();
        $genreMap = $taxonomy->mapForAssets([(int) $asset->id]);
        $versions = (new AssetVersionModel())->where('asset_id', $asset->id)->orderBy('revision', 'DESC')->findAll();
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
            'assignments' => $assignments, 'assignmentPageGroups' => $assignmentPageGroups,
            'assignmentTotal' => $assignmentTotal, 'assignmentPage' => $assignmentPage, 'assignmentPages' => $assignmentPages,
            'distributionLocations' => $distributionLocations, 'globalAssignableCount' => $globalAssignableCount,
            'schedules' => $schedules,
            'versions' => $versions, 'userNames' => $names, 'catalogToday' => (new AssetExpiryService())->today(),
        ]);
    }

    /**
     * @param list<array<string,mixed>> $assignments
     * @return list<array{name:string,studio_count:int,active_count:int,missing_count:int,pending_count:int,studios:list<array<string,mixed>>}>
     */
    private function assignmentLocationGroups(array $assignments): array
    {
        $groups = [];
        foreach ($assignments as $assignment) {
            $key = (string) ($assignment['location_public_id'] ?: 'no-location');
            if (! isset($groups[$key])) {
                $groups[$key] = [
                    'name' => (string) ($assignment['location_name'] ?: 'No Location'),
                    'studio_count' => 0, 'active_count' => 0,
                    'missing_count' => 0, 'pending_count' => 0, 'studios' => [],
                ];
            }
            $groups[$key]['studio_count']++;
            if ($assignment['device_status'] === 'active') $groups[$key]['active_count']++;
            if ($assignment['status'] === 'missing') $groups[$key]['missing_count']++;
            if ($assignment['status'] === 'removal_pending') $groups[$key]['pending_count']++;
            $groups[$key]['studios'][] = $assignment;
        }
        return array_values($groups);
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
    private function countMap(string $table, string $key, string $alias, bool $distinct = false): array
    {
        $expression = $distinct ? "COUNT(DISTINCT schedule_id) AS {$alias}" : "COUNT(*) AS {$alias}";
        $rows = Database::connect()->table($table)->select("{$key}, {$expression}", false)->where("{$key} IS NOT NULL")->groupBy($key)->get()->getResultArray();
        $map = [];
        foreach ($rows as $row) $map[(int) $row[$key]] = (int) $row[$alias];
        return $map;
    }

    private function admin(): object
    {
        return (new UserModel())->find((int) session()->get('cms_web_user_id'));
    }
}
