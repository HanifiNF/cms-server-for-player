<?php

namespace App\Libraries;

use App\Entities\Device;
use App\Models\AssetModel;
use App\Models\DeviceAssetModel;
use App\Models\DeviceModel;
use App\Models\ScheduleModel;
use App\Models\LocationModel;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\RawSql;
use Config\Database;
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;
use Throwable;

class ScheduleService
{
    private BaseConnection $db;
    private ScheduleRecurrence $recurrence;
    private ScheduleTimeline $timeline;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
        $this->recurrence = new ScheduleRecurrence();
        $this->timeline = new ScheduleTimeline();
    }

    /** @return list<array<string, mixed>> */
    public function readyMediaByDevice(): array
    {
        (new AssetExpiryService($this->db))->expireDue();
        $assetPublicIds = [];
        $activeAssetIds = [];
        $catalogAssets = (new AssetModel())->findAll();
        $genreMap = (new AssetTaxonomyService($this->db))->mapForAssets(array_map(static fn ($asset): int => (int) $asset->id, $catalogAssets));
        $assetMetadata = [];
        foreach ($catalogAssets as $asset) {
            $assetPublicIds[(int) $asset->id] = $asset->public_id;
            if ($asset->status === 'active') $activeAssetIds[(int) $asset->id] = true;
            $assetMetadata[(int) $asset->id] = [
                'title' => $asset->title,
                'filename' => $asset->filename,
                'storageFilename' => basename(str_replace('\\', '/', (string) $asset->storage_key)),
                'type' => $asset->asset_type ?: 'featured',
                'genres' => array_column($genreMap[(int) $asset->id] ?? [], 'name'),
                'expiresOn' => $asset->expires_on?->format('Y-m-d'),
                'posterUrl' => $asset->poster_storage_key
                    ? site_url('control/assets/' . rawurlencode((string) $asset->public_id) . '/poster')
                    : null,
            ];
        }

        $activeLocations = [];
        foreach ((new LocationModel())->where('status', 'active')->orderBy('name')->findAll() as $location) {
            $activeLocations[(int) $location->id] = $location;
        }
        $result = [];
        foreach ((new DeviceModel())->where('status', 'active')->orderBy('location')->orderBy('name')->findAll() as $device) {
            if ($device->location_id !== null && ! isset($activeLocations[(int) $device->location_id])) continue;
            $location = $device->location_id !== null ? ($activeLocations[(int) $device->location_id] ?? null) : null;
            $media = [];
            foreach ((new DeviceAssetModel())->where('device_id', $device->id)->where('status', 'ready')->orderBy('title')->findAll() as $item) {
                if ($item->asset_id !== null && ! isset($activeAssetIds[(int) $item->asset_id])) continue;
                $durationMs = max(0, (int) $item->duration_ms);
                if ($durationMs <= 0 || $item->media_key === null || $item->media_key === '') continue;
                $media[] = [
                    'mediaKey' => $item->media_key,
                    'assetId' => $item->asset_id !== null ? ($assetPublicIds[(int) $item->asset_id] ?? null) : null,
                    'title' => $item->asset_id !== null ? ($assetMetadata[(int) $item->asset_id]['title'] ?? $item->title) : $item->title,
                    'filename' => $item->asset_id !== null ? ($assetMetadata[(int) $item->asset_id]['filename'] ?? $item->filename) : $item->filename,
                    'storageFilename' => $item->asset_id !== null ? ($assetMetadata[(int) $item->asset_id]['storageFilename'] ?? null) : null,
                    'source' => $item->source,
                    'durationMs' => $durationMs,
                    'type' => $item->asset_id !== null ? ($assetMetadata[(int) $item->asset_id]['type'] ?? 'featured') : 'local',
                    'genres' => $item->asset_id !== null ? ($assetMetadata[(int) $item->asset_id]['genres'] ?? []) : [],
                    'expiresOn' => $item->asset_id !== null ? ($assetMetadata[(int) $item->asset_id]['expiresOn'] ?? null) : null,
                    'posterUrl' => $item->asset_id !== null ? ($assetMetadata[(int) $item->asset_id]['posterUrl'] ?? null) : null,
                ];
            }
            $result[] = [
                'id' => $device->public_id,
                'name' => $device->name,
                'location' => $location?->name ?: $device->location,
                'locationId' => $location?->public_id ?: 'unassigned',
                'locationCode' => $location?->code ?: '',
                'timezone' => $device->timezone ?: 'Asia/Jakarta',
                'media' => $media,
            ];
        }
        return $result;
    }

    /** @return list<array<string, mixed>> */
    public function listForWeb(): array
    {
        $rows = $this->db->table('schedules')->orderBy('start_at', 'DESC')->get()->getResultArray();
        if ($rows === []) return [];
        $scheduleIds = array_map(static fn (array $row): int => (int) $row['id'], $rows);
        $targets = [];
        foreach ($this->db->table('schedule_targets st')
            ->select('st.schedule_id, d.id AS device_id, d.public_id, d.name, d.location, d.location_id, l.public_id AS location_public_id, l.name AS location_name')
            ->join('devices d', 'd.id = st.device_id')->join('locations l', 'l.id = d.location_id', 'left')
            ->whereIn('st.schedule_id', $scheduleIds)->orderBy('d.location')->orderBy('d.name')->get()->getResultArray() as $target) {
            $targets[(int) $target['schedule_id']][] = $target;
        }
        $items = [];
        foreach ($this->db->table('schedule_items si')
            ->select('si.*, a.public_id AS asset_public_id, a.status AS asset_status')
            ->join('assets a', 'a.id = si.asset_id', 'left')->whereIn('si.schedule_id', $scheduleIds)
            ->orderBy('si.schedule_id')->orderBy('si.position', 'ASC')->get()->getResultArray() as $item) {
            $items[(int) $item['schedule_id']][] = $item;
        }

        return array_map(fn (array $row): array => $this->hydrateForWeb(
            $row,
            $targets[(int) $row['id']] ?? [],
            $items[(int) $row['id']] ?? [],
        ), $rows);
    }

    /**
     * @param array<string, mixed> $input
     * @return array{rows:list<array<string,mixed>>,all:list<array<string,mixed>>,filters:array<string,mixed>,options:array<string,mixed>,total:int,page:int,per_page:int,pages:int}
     */
    public function directory(array $input, int $perPage = 20, bool $includeOptions = true): array
    {
        $filter = new ScheduleDirectoryFilter($this->recurrence);
        $filters = $filter->normalize($input);
        $allRows = $this->listForWeb();
        $filtered = $filter->apply($allRows, $filters);
        $total = count($filtered);
        $perPage = max(5, min(100, $perPage));
        $pages = max(1, (int) ceil($total / $perPage));
        $page = max(1, min($pages, (int) ($input['page'] ?? 1)));

        return [
            'rows' => array_slice($filtered, ($page - 1) * $perPage, $perPage),
            'all' => $filtered,
            'filters' => $filters,
            'options' => $includeOptions ? $this->directoryOptionsForWeb() : ['locations' => [], 'assets' => []],
            'total' => $total,
            'total_all' => count($allRows),
            'page' => $page,
            'per_page' => $perPage,
            'pages' => $pages,
        ];
    }

    /**
     * Builds the filter choices without hydrating every schedule timeline.
     *
     * @return array{locations:list<array<string,mixed>>,assets:list<array<string,mixed>>}
     */
    public function directoryOptionsForWeb(): array
    {
        $targetsBySchedule = [];
        $locations = [];
        foreach ($this->db->table('schedule_targets st')
            ->select('st.schedule_id, d.public_id AS device_id, d.name AS device_name, d.location AS legacy_location, l.public_id AS location_id, l.name AS location_name')
            ->join('devices d', 'd.id = st.device_id')
            ->join('locations l', 'l.id = d.location_id', 'left')
            ->orderBy('l.name')->orderBy('d.name')->get()->getResultArray() as $target) {
            $scheduleId = (int) $target['schedule_id'];
            $deviceId = (string) $target['device_id'];
            $locationName = (string) ($target['location_name'] ?: $target['legacy_location'] ?: 'No Location');
            $locationId = (string) ($target['location_id'] ?: 'legacy:' . sha1($locationName));
            $targetsBySchedule[$scheduleId]['location_ids'][] = $locationId;
            $targetsBySchedule[$scheduleId]['device_ids'][] = $deviceId;
            $locations[$locationId] ??= ['id' => $locationId, 'name' => $locationName, 'studios' => []];
            $locations[$locationId]['studios'][$deviceId] = ['id' => $deviceId, 'name' => (string) $target['device_name']];
        }

        $assets = [];
        foreach ($this->db->table('schedule_items si')
            ->select('si.schedule_id, a.public_id AS asset_id, si.title_snapshot')
            ->join('assets a', 'a.id = si.asset_id', 'left')->where('a.public_id IS NOT NULL')
            ->get()->getResultArray() as $item) {
            $assetId = (string) $item['asset_id'];
            $target = $targetsBySchedule[(int) $item['schedule_id']] ?? ['location_ids' => [], 'device_ids' => []];
            $assets[$assetId] ??= ['id' => $assetId, 'title' => (string) ($item['title_snapshot'] ?: 'Untitled'), 'location_ids' => [], 'device_ids' => []];
            $assets[$assetId]['location_ids'] = array_values(array_unique([...$assets[$assetId]['location_ids'], ...($target['location_ids'] ?? [])]));
            $assets[$assetId]['device_ids'] = array_values(array_unique([...$assets[$assetId]['device_ids'], ...($target['device_ids'] ?? [])]));
        }

        foreach ($locations as &$location) {
            $location['studios'] = array_values($location['studios']);
            usort($location['studios'], static fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']));
        }
        unset($location);
        usort($locations, static fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']));
        usort($assets, static fn (array $a, array $b): int => strcasecmp($a['title'], $b['title']));

        return ['locations' => array_values($locations), 'assets' => array_values($assets)];
    }

    public function scheduleCountForWeb(): int
    {
        return $this->db->table('schedules')->countAllResults();
    }

    /** @return array<string, mixed>|null */
    public function findForWeb(string $publicId): ?array
    {
        $row = $this->db->table('schedules')->where('public_id', $publicId)->get()->getRowArray();
        if ($row === null) return null;
        $row['targets'] = $this->targetsForSchedule((int) $row['id']);
        $first = $row['targets'][0] ?? null;
        $row['device_public_id'] = $first['public_id'] ?? null;
        $row['device_name'] = $first['name'] ?? 'No target';
        $row['device_location'] = $first['location'] ?? null;
        $timeline = $this->timeline->calculate(
            $this->itemsForSchedule((int) $row['id']),
            (bool) $row['loop_enabled'],
        );
        $row['items'] = $timeline['items'];
        $row['timeline'] = $timeline;
        return $row;
    }

    /** @param array<string, mixed> $input */
    public function create(array $input, int $createdBy): string
    {
        $normalized = $this->normalize($input);
        $publicId = $this->uuidV4();
        $this->persist(null, $publicId, $normalized, $createdBy);
        return $publicId;
    }

    /** @param array<string, mixed> $input */
    public function update(string $publicId, array $input, int $createdBy): void
    {
        $schedule = (new ScheduleModel())->where('public_id', $publicId)->first();
        if ($schedule === null) throw new RuntimeException('Schedule was not found.');
        $normalized = $this->normalize($input, (int) $schedule->id);
        $this->persist((int) $schedule->id, $publicId, $normalized, $createdBy);
    }

    public function setEnabled(string $publicId, bool $enabled): void
    {
        (new AssetExpiryService($this->db))->expireDue();
        $schedule = (new ScheduleModel())->where('public_id', $publicId)->first();
        if ($schedule === null) throw new RuntimeException('Schedule was not found.');
        $deviceIds = $this->targetDeviceIds((int) $schedule->id);
        if ($enabled) {
            $unavailableItems = $this->db->table('schedule_items si')->join('assets a', 'a.id = si.asset_id')
                ->where('si.schedule_id', $schedule->id)->where('a.status !=', 'active')->countAllResults();
            if ($unavailableItems > 0) {
                throw new ScheduleValidationException(['status' => 'This schedule contains an expired or inactive film and cannot be enabled.']);
            }
            $candidate = $this->scheduleArray((int) $schedule->id);
            foreach ($deviceIds as $deviceId) {
                $conflict = $candidate === null ? null : $this->findConflict($deviceId, $candidate, (int) $schedule->id);
                if ($conflict !== null) {
                    throw new ScheduleValidationException(['status' => 'This schedule overlaps "' . $conflict['title'] . '" and cannot be enabled.']);
                }
            }
        }
        $this->db->transBegin();
        try {
            $this->db->table('schedules')->where('id', $schedule->id)->update([
                'status' => $enabled ? 'active' : 'disabled',
                'revision' => new RawSql('revision + 1'),
                'updated_at' => gmdate('Y-m-d H:i:s'),
            ]);
            foreach ($deviceIds as $deviceId) $this->bumpDeviceRevision($deviceId);
            $this->finishTransaction();
        } catch (Throwable $error) {
            $this->db->transRollback();
            throw $error;
        }
    }

    public function delete(string $publicId): void
    {
        $schedule = (new ScheduleModel())->where('public_id', $publicId)->first();
        if ($schedule === null) throw new RuntimeException('Schedule was not found.');
        $deviceIds = $this->targetDeviceIds((int) $schedule->id);
        $this->db->transBegin();
        try {
            if (! (new ScheduleModel())->delete($schedule->id)) throw new RuntimeException('Schedule could not be deleted.');
            foreach ($deviceIds as $deviceId) $this->bumpDeviceRevision($deviceId);
            $this->finishTransaction();
        } catch (Throwable $error) {
            $this->db->transRollback();
            throw $error;
        }
    }

    /** @param list<string> $publicIds @return array{changed:int,devices:int} */
    public function disableMany(array $publicIds): array
    {
        $rows = $this->bulkSchedules($publicIds);
        $deviceIds = [];
        $changed = 0;
        $this->db->transBegin();
        try {
            foreach ($rows as $row) {
                if ((string) $row['status'] === 'disabled') continue;
                $scheduleDeviceIds = $this->targetDeviceIds((int) $row['id']);
                $this->db->table('schedules')->where('id', $row['id'])->update([
                    'status' => 'disabled',
                    'revision' => new RawSql('revision + 1'),
                    'updated_at' => gmdate('Y-m-d H:i:s'),
                ]);
                foreach ($scheduleDeviceIds as $deviceId) $deviceIds[$deviceId] = true;
                $changed++;
            }
            foreach (array_keys($deviceIds) as $deviceId) $this->bumpDeviceRevision((int) $deviceId);
            $this->finishTransaction();
        } catch (Throwable $error) {
            $this->db->transRollback();
            throw $error;
        }
        return ['changed' => $changed, 'devices' => count($deviceIds)];
    }

    /** @param list<string> $publicIds @return array{changed:int,devices:int} */
    public function deleteMany(array $publicIds): array
    {
        $rows = $this->bulkSchedules($publicIds);
        $deviceIds = [];
        $this->db->transBegin();
        try {
            foreach ($rows as $row) {
                foreach ($this->targetDeviceIds((int) $row['id']) as $deviceId) $deviceIds[$deviceId] = true;
                if (! (new ScheduleModel())->delete((int) $row['id'])) throw new RuntimeException('A selected schedule could not be deleted.');
            }
            foreach (array_keys($deviceIds) as $deviceId) $this->bumpDeviceRevision((int) $deviceId);
            $this->finishTransaction();
        } catch (Throwable $error) {
            $this->db->transRollback();
            throw $error;
        }
        return ['changed' => count($rows), 'devices' => count($deviceIds)];
    }

    /** @return array{revision:int,schedules:list<array<string,mixed>>} */
    public function playerPayload(Device $device): array
    {
        (new AssetExpiryService($this->db))->expireDue();
        $device = (new DeviceModel())->find($device->id) ?? $device;
        $rows = $this->db->table('schedules s')
            ->select('s.*')->join('schedule_targets st', 'st.schedule_id = s.id')
            ->where('st.device_id', $device->id)->where('s.status', 'active')
            ->orderBy('s.start_at', 'ASC')->get()->getResultArray();
        $schedules = [];
        foreach ($rows as $row) {
            if ($this->recurrence->isExpired($row)) continue;
            $playlist = [];
            foreach ($this->itemsForSchedule((int) $row['id']) as $item) {
                if ($item['asset_id'] !== null && $item['asset_status'] !== 'active') continue;
                $entry = [
                    'mediaKey' => $item['media_key'],
                    'title' => $item['title_snapshot'],
                    'sourceDurationMs' => max(0, (int) $item['duration_override_ms']),
                    'durationMs' => max(0, (int) $item['duration_override_ms'] - (int) ($item['playback_start_offset_ms'] ?? 0)),
                    'startOffsetMs' => (int) ($item['playback_start_offset_ms'] ?? 0),
                    'gapAfterMs' => (int) ($item['gap_after_ms'] ?? 0),
                    'volumePercent' => (int) ($item['volume_percent'] ?? 100),
                    'order' => (int) $item['position'],
                ];
                if ($item['asset_public_id'] !== null) $entry['assetId'] = $item['asset_public_id'];
                $playlist[] = $entry;
            }
            if ($playlist === []) continue;
            $schedules[] = [
                'id' => $row['public_id'],
                'title' => $row['title'],
                'revision' => (int) $row['revision'],
                'priority' => (int) $row['priority'],
                'startTime' => $this->scheduleAtom($row, 'start_at'),
                'endTime' => $this->scheduleAtom($row, 'end_at'),
                'recurrence' => $this->playerRecurrence($row),
                'enabled' => true,
                'loop' => (bool) $row['loop_enabled'],
                'playlist' => $playlist,
            ];
        }
        return ['revision' => (int) $device->schedule_revision, 'schedules' => $schedules];
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    private function normalize(array $input, ?int $excludeScheduleId = null): array
    {
        $expiryService = new AssetExpiryService($this->db);
        $expiryService->expireDue();
        $errors = [];
        $title = trim((string) ($input['title'] ?? ''));
        if ($title === '' || mb_strlen($title) > 255) $errors['title'] = 'Title is required and must not exceed 255 characters.';

        $requestedDeviceIds = is_array($input['device_ids'] ?? null) ? $input['device_ids'] : [];
        if ($requestedDeviceIds === [] && trim((string) ($input['device_id'] ?? '')) !== '') {
            $requestedDeviceIds = [(string) $input['device_id']];
        }
        $requestedDeviceIds = array_values(array_unique(array_filter(array_map(
            static fn ($value): string => trim((string) $value),
            array_slice($requestedDeviceIds, 0, 100),
        ))));
        $foundDevices = [];
        if ($requestedDeviceIds !== []) {
            foreach ((new DeviceModel())->whereIn('public_id', $requestedDeviceIds)->where('status', 'active')->findAll() as $candidateDevice) {
                $foundDevices[(string) $candidateDevice->public_id] = $candidateDevice;
            }
        }
        $devices = [];
        foreach ($requestedDeviceIds as $devicePublicId) {
            $candidateDevice = $foundDevices[$devicePublicId] ?? null;
            if ($candidateDevice !== null && $candidateDevice->location_id !== null) {
                $location = (new LocationModel())->find((int) $candidateDevice->location_id);
                if ($location === null || $location->status !== 'active') $candidateDevice = null;
            }
            if ($candidateDevice !== null) $devices[] = $candidateDevice;
        }
        if ($devices === [] || count($devices) !== count($requestedDeviceIds)) $errors['device_id'] = 'Choose one or more active Studios in active Locations.';
        $timezoneName = trim((string) ($input['timezone'] ?? '')) ?: ($devices[0]->timezone ?? 'Asia/Jakarta');
        try {
            $timezone = new DateTimeZone($timezoneName);
        } catch (Throwable) {
            $errors['timezone'] = 'Choose a valid Schedule timezone.';
            $timezoneName = 'Asia/Jakarta';
            $timezone = new DateTimeZone('Asia/Jakarta');
        }

        $startInput = trim((string) ($input['start_at'] ?? ''));
        $start = $this->parseLocalStart($startInput, $timezone);
        if ($start === null) {
            $errors['start_at'] = 'Choose a valid start date and time.';
        }

        $recurrenceType = strtolower(trim((string) ($input['recurrence'] ?? 'one_time')));
        if (! in_array($recurrenceType, ['one_time', 'daily', 'weekly'], true)) {
            $errors['recurrence'] = 'Choose one-time, daily, or weekly.';
            $recurrenceType = 'one_time';
        }
        $daysInput = is_array($input['days_of_week'] ?? null) ? $input['days_of_week'] : [];
        $daysOfWeek = array_values(array_unique(array_filter(
            array_map('intval', $daysInput),
            static fn (int $day): bool => $day >= 1 && $day <= 7
        )));
        sort($daysOfWeek);
        if ($recurrenceType === 'weekly' && $daysOfWeek === []) {
            $errors['days_of_week'] = 'Choose at least one weekday for a weekly schedule.';
        }
        $untilInput = trim((string) ($input['recurrence_until'] ?? ''));
        $until = null;
        if ($recurrenceType !== 'one_time' && $untilInput !== '') {
            $until = DateTimeImmutable::createFromFormat('!Y-m-d', $untilInput, $timezone);
            $untilErrors = DateTimeImmutable::getLastErrors();
            if ($until === false || ($untilErrors !== false && ($untilErrors['warning_count'] > 0 || $untilErrors['error_count'] > 0))) {
                $errors['recurrence_until'] = 'Choose a valid recurrence end date.';
                $until = null;
            }
        }

        $keys = is_array($input['media_keys'] ?? null) ? array_values($input['media_keys']) : [];
        $durations = is_array($input['duration_ms'] ?? null) ? array_values($input['duration_ms']) : [];
        $playbackStartOffsets = is_array($input['playback_start_offset_ms'] ?? null) ? array_values($input['playback_start_offset_ms']) : [];
        $gaps = is_array($input['gap_after_ms'] ?? null) ? array_values($input['gap_after_ms']) : [];
        $volumes = is_array($input['volume_percent'] ?? null) ? array_values($input['volume_percent']) : [];
        $loopEnabled = isset($input['loop_enabled']) && (string) $input['loop_enabled'] === '1';
        if ($keys === []) $errors['playlist'] = 'Add at least one Ready media item.';
        if (count($keys) > 100) $errors['playlist'] = 'A playlist may contain at most 100 items.';

        $ready = null;
        foreach ($devices as $device) {
            $deviceReady = [];
            foreach ((new DeviceAssetModel())->where('device_id', $device->id)->where('status', 'ready')->findAll() as $asset) {
                $deviceReady[(string) $asset->media_key] = $asset;
            }
            $ready = $ready === null ? $deviceReady : array_intersect_key($ready, $deviceReady);
        }
        $ready ??= [];
        $items = [];
        $expirationDates = [];
        foreach ($keys as $index => $value) {
            $key = trim((string) $value);
            $asset = $ready[$key] ?? null;
            if ($asset === null) {
                $errors["playlist.{$index}"] = 'A selected media item is not Ready on every selected Studio.';
                continue;
            }
            $titleSnapshot = (string) $asset->title;
            if ($asset->asset_id !== null) {
                $catalogAsset = (new AssetModel())->find((int) $asset->asset_id);
                if ($catalogAsset === null || $catalogAsset->status !== 'active') {
                    $errors["playlist.{$index}"] = 'A selected managed film is expired or no longer active.';
                    continue;
                }
                if ($catalogAsset->expires_on !== null) {
                    $expirationDates[] = $catalogAsset->expires_on->format('Y-m-d');
                }
                $titleSnapshot = (string) $catalogAsset->title;
            }
            $duration = filter_var($durations[$index] ?? null, FILTER_VALIDATE_INT);
            $duration = $duration === false ? 0 : (int) $duration;
            if ($duration <= 0) $duration = (int) $asset->duration_ms;
            if ($duration <= 0 || $duration > 86400000) {
                $errors["duration.{$index}"] = 'Each duration must be between 1 millisecond and 24 hours.';
                continue;
            }
            $playbackStartOffsetMs = filter_var($playbackStartOffsets[$index] ?? 0, FILTER_VALIDATE_INT);
            $playbackStartOffsetMs = $playbackStartOffsetMs === false ? -1 : (int) $playbackStartOffsetMs;
            if ($playbackStartOffsetMs < 0 || $playbackStartOffsetMs >= $duration) {
                $errors["playback_start_offset.{$index}"] = 'Film start position must be at least 0 and earlier than its duration.';
                continue;
            }
            $gapAfterMs = filter_var($gaps[$index] ?? 0, FILTER_VALIDATE_INT);
            $gapAfterMs = $gapAfterMs === false ? -1 : (int) $gapAfterMs;
            if ($gapAfterMs < 0 || $gapAfterMs > 86400000) {
                $errors["gap.{$index}"] = 'Each film gap must be between 0 milliseconds and 24 hours.';
                continue;
            }
            $volumePercent = filter_var($volumes[$index] ?? 100, FILTER_VALIDATE_INT);
            $volumePercent = $volumePercent === false ? -1 : (int) $volumePercent;
            if ($volumePercent < 0 || $volumePercent > 100) {
                $errors["volume.{$index}"] = 'Each film volume must be between 0 and 100 percent.';
                continue;
            }
            $items[] = [
                'position' => $index,
                'asset_id' => $asset->asset_id !== null ? (int) $asset->asset_id : null,
                'media_key' => $key,
                'title_snapshot' => $titleSnapshot,
                'duration_override_ms' => $duration,
                'playback_start_offset_ms' => $playbackStartOffsetMs,
                'gap_after_ms' => $gapAfterMs,
                'volume_percent' => $volumePercent,
            ];
        }
        $timeline = $this->timeline->calculate($items, $loopEnabled);
        $totalDurationMs = $timeline['total_duration_ms'];
        if ($totalDurationMs <= 0) $errors['playlist_duration'] = 'The playlist must have a valid total duration.';
        if ($totalDurationMs > 86400000) $errors['playlist_duration'] = 'A recurring playlist may not exceed 24 hours in total.';

        $earliestExpiration = null;
        if ($expirationDates !== []) {
            sort($expirationDates);
            $earliestExpiration = $expirationDates[0];
            if ($recurrenceType !== 'one_time' && $untilInput === '') {
                $until = DateTimeImmutable::createFromFormat('!Y-m-d', $earliestExpiration, $timezone) ?: null;
            }
        }

        if ($errors !== []) throw new ScheduleValidationException($errors);
        if ($until !== null && $until->format('Y-m-d') < $start->format('Y-m-d')) {
            throw new ScheduleValidationException(['recurrence_until' => 'The recurrence end date cannot be before its first occurrence.']);
        }
        $startUtc = $start->setTimezone(new DateTimeZone('UTC'));
        $endUtc = $startUtc->modify('+' . $totalDurationMs . ' milliseconds');
        if ($earliestExpiration !== null) {
            $deadline = $expiryService->deadlineUtc($earliestExpiration);
            if ($recurrenceType === 'one_time' && $endUtc > $deadline) {
                throw new ScheduleValidationException(['playlist' => "This schedule ends after a film expires on {$earliestExpiration}."]);
            }
            if ($recurrenceType !== 'one_time') {
                if ($until === null) {
                    throw new ScheduleValidationException(['recurrence_until' => "A recurring schedule containing an expiring film must end by {$earliestExpiration}."]);
                }
                $lastStart = new DateTimeImmutable(
                    $until->format('Y-m-d') . ' ' . $start->format('H:i:s.u'),
                    $timezone,
                );
                $lastEnd = $lastStart->setTimezone(new DateTimeZone('UTC'))->modify('+' . $totalDurationMs . ' milliseconds');
                if ($lastEnd > $deadline) {
                    throw new ScheduleValidationException(['recurrence_until' => "The final occurrence would pass the film expiry date {$earliestExpiration}."]);
                }
            }
        }
        if ($recurrenceType === 'one_time' && $endUtc <= new DateTimeImmutable('now', new DateTimeZone('UTC'))) {
            throw new ScheduleValidationException(['start_at' => 'The schedule must end in the future.']);
        }

        $recurrenceConfig = $recurrenceType === 'one_time' ? null : [
            'daysOfWeek' => $recurrenceType === 'weekly' ? $daysOfWeek : [],
            'until' => $until?->format('Y-m-d'),
        ];
        $candidate = [
            'start_at' => $startUtc->format('Y-m-d H:i:s.u'),
            'end_at' => $endUtc->format('Y-m-d H:i:s.u'),
            'timezone' => $timezoneName,
            'recurrence' => $recurrenceType,
            'recurrence_config' => $recurrenceConfig,
        ];
        foreach ($devices as $device) {
            $conflictRow = $this->findConflict((int) $device->id, $candidate, $excludeScheduleId);
            if ($conflictRow !== null) {
                throw new ScheduleValidationException(['start_at' => 'This time overlaps schedule "' . $conflictRow['title'] . '" on Studio "' . $device->name . '".']);
            }
        }

        return [
            'title' => $title, 'description' => trim((string) ($input['description'] ?? '')) ?: null,
            'devices' => $devices, 'timezone' => $timezoneName,
            'start_at' => $startUtc->format('Y-m-d H:i:s.u'), 'end_at' => $endUtc->format('Y-m-d H:i:s.u'),
            'recurrence' => $recurrenceType, 'recurrence_config' => $recurrenceConfig,
            'priority' => max(-100, min(100, (int) ($input['priority'] ?? 0))),
            'loop_enabled' => $loopEnabled,
            'items' => $items,
        ];
    }

    /** @param array<string, mixed> $data */
    private function persist(?int $scheduleId, string $publicId, array $data, int $createdBy): void
    {
        $oldDeviceIds = $scheduleId === null ? [] : $this->targetDeviceIds($scheduleId);
        $existingStatus = $scheduleId === null ? 'active' : (string) ($this->db->table('schedules')->select('status')->where('id', $scheduleId)->get()->getRowArray()['status'] ?? 'active');
        $deviceIds = array_values(array_unique(array_map(static fn ($device): int => (int) $device->id, $data['devices'])));
        $this->db->transBegin();
        try {
            $values = [
                'public_id' => $publicId, 'title' => $data['title'], 'description' => $data['description'],
                'start_at' => $data['start_at'], 'end_at' => $data['end_at'], 'timezone' => $data['timezone'],
                'recurrence' => $data['recurrence'],
                'recurrence_config' => $data['recurrence_config'] === null ? null : json_encode($data['recurrence_config'], JSON_THROW_ON_ERROR),
                'status' => $existingStatus,
                'priority' => $data['priority'], 'loop_enabled' => $data['loop_enabled'], 'created_by' => $createdBy,
            ];
            $model = new ScheduleModel();
            if ($scheduleId === null) {
                $values['revision'] = 1;
                $scheduleId = $model->insert($values, true);
                if (! is_int($scheduleId)) throw new RuntimeException('Schedule could not be created.');
            } else {
                unset($values['public_id']);
                $values['revision'] = new RawSql('revision + 1');
                if (! $this->db->table('schedules')->where('id', $scheduleId)->update($values)) throw new RuntimeException('Schedule could not be updated.');
                $this->db->table('schedule_items')->where('schedule_id', $scheduleId)->delete();
                $this->db->table('schedule_targets')->where('schedule_id', $scheduleId)->delete();
            }
            $createdAt = gmdate('Y-m-d H:i:s');
            $targets = array_map(static fn (int $deviceId): array => [
                'schedule_id' => $scheduleId, 'device_id' => $deviceId, 'created_at' => $createdAt,
            ], $deviceIds);
            if ($targets === [] || ! $this->db->table('schedule_targets')->insertBatch($targets)) throw new RuntimeException('Schedule targets could not be saved.');
            foreach ($data['items'] as $item) {
                $this->db->table('schedule_items')->insert([
                    'schedule_id' => $scheduleId, ...$item,
                    'created_at' => gmdate('Y-m-d H:i:s'), 'updated_at' => gmdate('Y-m-d H:i:s'),
                ]);
            }
            foreach (array_values(array_unique([...$oldDeviceIds, ...$deviceIds])) as $deviceId) $this->bumpDeviceRevision($deviceId);
            $this->finishTransaction();
        } catch (Throwable $error) {
            $this->db->transRollback();
            throw $error;
        }
    }

    /** @return list<array<string, mixed>> */
    private function itemsForSchedule(int $scheduleId): array
    {
        return $this->db->table('schedule_items si')
            ->select('si.*, a.public_id AS asset_public_id, a.status AS asset_status')
            ->join('assets a', 'a.id = si.asset_id', 'left')
            ->where('si.schedule_id', $scheduleId)->orderBy('si.position', 'ASC')->get()->getResultArray();
    }

    /** @return list<int> */
    private function targetDeviceIds(int $scheduleId): array
    {
        return array_values(array_unique(array_map(
            static fn (array $row): int => (int) $row['device_id'],
            $this->db->table('schedule_targets')->select('device_id')->where('schedule_id', $scheduleId)->get()->getResultArray(),
        )));
    }

    /** @return list<array{device_id:int,public_id:string,name:string,location:string,location_id:?int}> */
    private function targetsForSchedule(int $scheduleId): array
    {
        return $this->db->table('schedule_targets st')
            ->select('d.id AS device_id, d.public_id, d.name, d.location, d.location_id, l.public_id AS location_public_id, l.name AS location_name')
            ->join('devices d', 'd.id = st.device_id')
            ->join('locations l', 'l.id = d.location_id', 'left')
            ->where('st.schedule_id', $scheduleId)->orderBy('d.location')->orderBy('d.name')->get()->getResultArray();
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function hydrateForWeb(array $row, ?array $targets = null, ?array $items = null): array
    {
        $row['targets'] = $targets ?? $this->targetsForSchedule((int) $row['id']);
        foreach ($row['targets'] as &$target) {
            $target['location'] = (string) ($target['location_name'] ?: $target['location']);
        }
        unset($target);
        $first = $row['targets'][0] ?? null;
        $row['device_public_id'] = $first['public_id'] ?? null;
        $row['device_name'] = $first['name'] ?? 'No target';
        $row['device_location'] = $first['location'] ?? null;
        $row['target_count'] = count($row['targets']);
        $row['location_count'] = count(array_unique(array_filter(array_column($row['targets'], 'location'))));
        $timeline = $this->timeline->calculate($items ?? $this->itemsForSchedule((int) $row['id']), (bool) $row['loop_enabled']);
        $row['items'] = $timeline['items'];
        $row['timeline'] = $timeline;
        $row['display_status'] = $this->displayStatus($row);
        return $row;
    }

    /** @param list<array<string, mixed>> $rows @return array{locations:list<array<string,mixed>>,assets:list<array<string,mixed>>} */
    private function directoryOptions(array $rows): array
    {
        $locations = [];
        $assets = [];
        foreach ($rows as $row) {
            $locationIds = [];
            $deviceIds = [];
            foreach ((array) $row['targets'] as $target) {
                $deviceId = (string) ($target['public_id'] ?? '');
                $locationId = (string) ($target['location_public_id'] ?? '');
                if ($deviceId !== '') $deviceIds[] = $deviceId;
                if ($locationId === '') $locationId = 'legacy:' . sha1((string) ($target['location'] ?? 'No Location'));
                $locationIds[] = $locationId;
                $locations[$locationId] ??= ['id' => $locationId, 'name' => (string) ($target['location'] ?? 'No Location'), 'studios' => []];
                if ($deviceId !== '') $locations[$locationId]['studios'][$deviceId] = ['id' => $deviceId, 'name' => (string) ($target['name'] ?? 'Studio')];
            }
            foreach ((array) $row['items'] as $item) {
                $assetId = (string) ($item['asset_public_id'] ?? '');
                if ($assetId === '') continue;
                $assets[$assetId] ??= ['id' => $assetId, 'title' => (string) ($item['title_snapshot'] ?? 'Untitled'), 'location_ids' => [], 'device_ids' => []];
                $assets[$assetId]['location_ids'] = array_values(array_unique([...$assets[$assetId]['location_ids'], ...$locationIds]));
                $assets[$assetId]['device_ids'] = array_values(array_unique([...$assets[$assetId]['device_ids'], ...$deviceIds]));
            }
        }
        foreach ($locations as &$location) {
            $location['studios'] = array_values($location['studios']);
            usort($location['studios'], static fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']));
        }
        unset($location);
        usort($locations, static fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']));
        usort($assets, static fn (array $a, array $b): int => strcasecmp($a['title'], $b['title']));
        return ['locations' => array_values($locations), 'assets' => array_values($assets)];
    }

    /** @param list<string> $publicIds @return list<array<string, mixed>> */
    private function bulkSchedules(array $publicIds): array
    {
        $publicIds = array_values(array_unique(array_filter(array_map(static fn ($id): string => trim((string) $id), $publicIds))));
        if ($publicIds === []) throw new ScheduleValidationException(['schedules' => 'Choose at least one schedule.']);
        if (count($publicIds) > 100) throw new ScheduleValidationException(['schedules' => 'Choose at most 100 schedules at once.']);
        $rows = $this->db->table('schedules')->whereIn('public_id', $publicIds)->get()->getResultArray();
        if (count($rows) !== count($publicIds)) throw new ScheduleValidationException(['schedules' => 'One or more selected schedules no longer exist. Refresh and try again.']);
        return $rows;
    }

    /** @param array<string, mixed> $schedule */
    private function displayStatus(array $schedule): string
    {
        if ($schedule['status'] !== 'active') return 'disabled';
        if (($schedule['recurrence'] ?? 'one_time') !== 'one_time') {
            if ($this->recurrence->isExpired($schedule)) return 'completed';
            $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
            $occurrences = $this->recurrence->occurrences(
                $schedule,
                $now->modify('-1 day'),
                $now->modify('+1 day')
            );
            foreach ($occurrences as $occurrence) {
                if ($occurrence['start'] <= $now && $occurrence['end'] > $now) return 'active';
            }
            return 'upcoming';
        }
        $now = time();
        $start = strtotime((string) $schedule['start_at'] . ' UTC');
        $end = strtotime((string) $schedule['end_at'] . ' UTC');
        if ($now < $start) return 'upcoming';
        if ($now < $end) return 'active';
        return 'completed';
    }

    /** @param array<string, mixed> $candidate @return array<string, mixed>|null */
    private function findConflict(int $deviceId, array $candidate, ?int $excludeScheduleId = null): ?array
    {
        $query = $this->db->table('schedules s')->select('s.*')
            ->join('schedule_targets st', 'st.schedule_id = s.id')
            ->where('st.device_id', $deviceId)->where('s.status', 'active');
        if ($excludeScheduleId !== null) $query->where('s.id !=', $excludeScheduleId);
        foreach ($query->get()->getResultArray() as $existing) {
            if ($this->recurrence->isExpired($existing)) continue;
            if ($this->recurrence->overlaps($candidate, $existing)) return $existing;
        }
        return null;
    }

    /** @return array<string, mixed>|null */
    private function scheduleArray(int $scheduleId): ?array
    {
        return $this->db->table('schedules')->where('id', $scheduleId)->get()->getRowArray();
    }

    /** @param array<string, mixed> $schedule @return array<string, mixed>|null */
    private function playerRecurrence(array $schedule): ?array
    {
        $type = (string) ($schedule['recurrence'] ?? 'one_time');
        if ($type === 'one_time') return null;
        $config = $this->recurrence->config($schedule) ?? [];
        $result = [
            'freq' => $type,
            'daysOfWeek' => $type === 'weekly' ? array_values(array_map('intval', (array) ($config['daysOfWeek'] ?? []))) : [],
        ];
        if (! empty($config['until'])) {
            $timezone = new DateTimeZone((string) ($schedule['timezone'] ?: 'Asia/Jakarta'));
            $result['until'] = (new DateTimeImmutable((string) $config['until'] . ' 23:59:59', $timezone))->format(DATE_ATOM);
        } else {
            $result['until'] = null;
        }
        return $result;
    }

    private function bumpDeviceRevision(int $deviceId): void
    {
        if ($deviceId <= 0) return;
        $this->db->table('devices')->where('id', $deviceId)->update([
            'schedule_revision' => new RawSql('schedule_revision + 1'),
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ]);
        (new RealtimeOutboxService($this->db))->queueDevice($deviceId, 'schedule.revision.changed');
    }

    private function finishTransaction(): void
    {
        if ($this->db->transStatus() === false) throw new RuntimeException('Schedule database transaction failed.');
        $this->db->transCommit();
    }

    /** @param array<string, mixed> $schedule */
    private function scheduleAtom(array $schedule, string $field): string
    {
        $utc = new DateTimeImmutable((string) $schedule[$field], new DateTimeZone('UTC'));
        if (($schedule['recurrence'] ?? 'one_time') === 'one_time') return $utc->setTimezone(new DateTimeZone('UTC'))->format(DATE_ATOM);
        try { $timezone = new DateTimeZone((string) ($schedule['timezone'] ?: 'Asia/Jakarta')); }
        catch (Throwable) { $timezone = new DateTimeZone('Asia/Jakarta'); }
        return $utc->setTimezone($timezone)->format(DATE_ATOM);
    }

    private function parseLocalStart(string $value, DateTimeZone $timezone): ?DateTimeImmutable
    {
        foreach (['!Y-m-d\TH:i:s', '!Y-m-d\TH:i'] as $format) {
            $candidate = DateTimeImmutable::createFromFormat($format, $value, $timezone);
            $dateErrors = DateTimeImmutable::getLastErrors();
            if ($candidate !== false && ($dateErrors === false || ($dateErrors['warning_count'] === 0 && $dateErrors['error_count'] === 0))) {
                return $candidate;
            }
        }
        return null;
    }

    private function uuidV4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return sprintf('%s-%s-%s-%s-%s', substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 12, 4), substr($hex, 16, 4), substr($hex, 20));
    }
}
