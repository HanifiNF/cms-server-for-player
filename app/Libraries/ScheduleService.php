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

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
        $this->recurrence = new ScheduleRecurrence();
    }

    /** @return list<array<string, mixed>> */
    public function readyMediaByDevice(): array
    {
        (new AssetExpiryService($this->db))->expireDue();
        $assetPublicIds = [];
        $activeAssetIds = [];
        foreach ((new AssetModel())->findAll() as $asset) {
            $assetPublicIds[(int) $asset->id] = $asset->public_id;
            if ($asset->status === 'active') $activeAssetIds[(int) $asset->id] = true;
        }

        $activeLocationIds = array_map(static fn ($location): int => (int) $location->id, (new LocationModel())->where('status', 'active')->findAll());
        $result = [];
        foreach ((new DeviceModel())->where('status', 'active')->orderBy('location')->orderBy('name')->findAll() as $device) {
            if ($device->location_id !== null && ! in_array((int) $device->location_id, $activeLocationIds, true)) continue;
            $media = [];
            foreach ((new DeviceAssetModel())->where('device_id', $device->id)->where('status', 'ready')->orderBy('title')->findAll() as $item) {
                if ($item->asset_id !== null && ! isset($activeAssetIds[(int) $item->asset_id])) continue;
                $durationMs = max(0, (int) $item->duration_ms);
                if ($durationMs <= 0 || $item->media_key === null || $item->media_key === '') continue;
                $media[] = [
                    'mediaKey' => $item->media_key,
                    'assetId' => $item->asset_id !== null ? ($assetPublicIds[(int) $item->asset_id] ?? null) : null,
                    'title' => $item->title,
                    'filename' => $item->filename,
                    'source' => $item->source,
                    'durationMs' => $durationMs,
                ];
            }
            $result[] = [
                'id' => $device->public_id,
                'name' => $device->name,
                'location' => $device->location,
                'timezone' => $device->timezone ?: 'Asia/Jakarta',
                'media' => $media,
            ];
        }
        return $result;
    }

    /** @return list<array<string, mixed>> */
    public function listForWeb(): array
    {
        $rows = $this->db->table('schedules s')
            ->select('s.*, d.public_id AS device_public_id, d.name AS device_name, d.location AS device_location')
            ->join('schedule_targets st', 'st.schedule_id = s.id')
            ->join('devices d', 'd.id = st.device_id')
            ->orderBy('s.start_at', 'DESC')->get()->getResultArray();

        foreach ($rows as &$row) {
            $row['items'] = $this->itemsForSchedule((int) $row['id']);
            $row['display_status'] = $this->displayStatus($row);
        }
        unset($row);
        return $rows;
    }

    /** @return array<string, mixed>|null */
    public function findForWeb(string $publicId): ?array
    {
        $row = $this->db->table('schedules s')
            ->select('s.*, d.public_id AS device_public_id, d.name AS device_name, d.location AS device_location')
            ->join('schedule_targets st', 'st.schedule_id = s.id')
            ->join('devices d', 'd.id = st.device_id')
            ->where('s.public_id', $publicId)->get()->getRowArray();
        if ($row === null) return null;
        $row['items'] = $this->itemsForSchedule((int) $row['id']);
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
        $deviceId = (int) ($this->db->table('schedule_targets')->select('device_id')->where('schedule_id', $schedule->id)->get()->getRowArray()['device_id'] ?? 0);
        if ($enabled) {
            $unavailableItems = $this->db->table('schedule_items si')->join('assets a', 'a.id = si.asset_id')
                ->where('si.schedule_id', $schedule->id)->where('a.status !=', 'active')->countAllResults();
            if ($unavailableItems > 0) {
                throw new ScheduleValidationException(['status' => 'This schedule contains an expired or inactive film and cannot be enabled.']);
            }
            $candidate = $this->scheduleArray((int) $schedule->id);
            $conflict = $candidate === null ? null : $this->findConflict($deviceId, $candidate, (int) $schedule->id);
            if ($conflict !== null) {
                throw new ScheduleValidationException(['status' => 'This schedule overlaps "' . $conflict['title'] . '" and cannot be enabled.']);
            }
        }
        $this->db->transBegin();
        try {
            $this->db->table('schedules')->where('id', $schedule->id)->update([
                'status' => $enabled ? 'active' : 'disabled',
                'revision' => new RawSql('revision + 1'),
                'updated_at' => gmdate('Y-m-d H:i:s'),
            ]);
            $this->bumpDeviceRevision($deviceId);
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
        $deviceId = (int) ($this->db->table('schedule_targets')->select('device_id')->where('schedule_id', $schedule->id)->get()->getRowArray()['device_id'] ?? 0);
        $this->db->transBegin();
        try {
            if (! (new ScheduleModel())->delete($schedule->id)) throw new RuntimeException('Schedule could not be deleted.');
            $this->bumpDeviceRevision($deviceId);
            $this->finishTransaction();
        } catch (Throwable $error) {
            $this->db->transRollback();
            throw $error;
        }
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
                    'durationMs' => (int) $item['duration_override_ms'],
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

        $devicePublicId = trim((string) ($input['device_id'] ?? ''));
        $device = (new DeviceModel())->where('public_id', $devicePublicId)->where('status', 'active')->first();
        if ($device !== null && $device->location_id !== null) {
            $location = (new LocationModel())->find((int) $device->location_id);
            if ($location === null || $location->status !== 'active') $device = null;
        }
        if ($device === null) $errors['device_id'] = 'Choose an active Studio.';
        $timezoneName = $device?->timezone ?: 'Asia/Jakarta';
        try {
            $timezone = new DateTimeZone($timezoneName);
        } catch (Throwable) {
            $timezone = new DateTimeZone('Asia/Jakarta');
        }

        $startInput = trim((string) ($input['start_at'] ?? ''));
        $start = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $startInput, $timezone);
        $dateErrors = DateTimeImmutable::getLastErrors();
        if ($start === false || ($dateErrors !== false && ($dateErrors['warning_count'] > 0 || $dateErrors['error_count'] > 0))) {
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
        if ($keys === []) $errors['playlist'] = 'Add at least one Ready media item.';
        if (count($keys) > 100) $errors['playlist'] = 'A playlist may contain at most 100 items.';

        $ready = [];
        if ($device !== null) {
            foreach ((new DeviceAssetModel())->where('device_id', $device->id)->where('status', 'ready')->findAll() as $asset) {
                $ready[(string) $asset->media_key] = $asset;
            }
        }
        $items = [];
        $expirationDates = [];
        $totalDurationMs = 0;
        foreach ($keys as $index => $value) {
            $key = trim((string) $value);
            $asset = $ready[$key] ?? null;
            if ($asset === null) {
                $errors["playlist.{$index}"] = 'A selected media item is no longer Ready on this Studio.';
                continue;
            }
            if ($asset->asset_id !== null) {
                $catalogAsset = (new AssetModel())->find((int) $asset->asset_id);
                if ($catalogAsset === null || $catalogAsset->status !== 'active') {
                    $errors["playlist.{$index}"] = 'A selected managed film is expired or no longer active.';
                    continue;
                }
                if ($catalogAsset->expires_on !== null) {
                    $expirationDates[] = $catalogAsset->expires_on->format('Y-m-d');
                }
            }
            $duration = filter_var($durations[$index] ?? null, FILTER_VALIDATE_INT);
            $duration = $duration === false ? 0 : (int) $duration;
            if ($duration <= 0) $duration = (int) $asset->duration_ms;
            if ($duration <= 0 || $duration > 86400000) {
                $errors["duration.{$index}"] = 'Each duration must be between 1 millisecond and 24 hours.';
                continue;
            }
            $totalDurationMs += $duration;
            $items[] = [
                'position' => $index,
                'asset_id' => $asset->asset_id !== null ? (int) $asset->asset_id : null,
                'media_key' => $key,
                'title_snapshot' => $asset->title,
                'duration_override_ms' => $duration,
            ];
        }
        if ($totalDurationMs <= 0) $errors['playlist_duration'] = 'The playlist must have a valid total duration.';
        if ($totalDurationMs > 86400000) $errors['playlist_duration'] = 'A recurring playlist may not exceed 24 hours in total.';

        if ($errors !== []) throw new ScheduleValidationException($errors);
        if ($until !== null && $until->format('Y-m-d') < $start->format('Y-m-d')) {
            throw new ScheduleValidationException(['recurrence_until' => 'The recurrence end date cannot be before its first occurrence.']);
        }
        $startUtc = $start->setTimezone(new DateTimeZone('UTC'));
        $endUtc = $startUtc->modify('+' . $totalDurationMs . ' milliseconds');
        if ($expirationDates !== []) {
            sort($expirationDates);
            $earliestExpiration = $expirationDates[0];
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
        $conflictRow = $this->findConflict((int) $device->id, $candidate, $excludeScheduleId);
        if ($conflictRow !== null) {
            throw new ScheduleValidationException(['start_at' => 'This time overlaps schedule "' . $conflictRow['title'] . '" on the same Studio.']);
        }

        return [
            'title' => $title, 'description' => trim((string) ($input['description'] ?? '')) ?: null,
            'device' => $device, 'timezone' => $timezoneName,
            'start_at' => $startUtc->format('Y-m-d H:i:s.u'), 'end_at' => $endUtc->format('Y-m-d H:i:s.u'),
            'recurrence' => $recurrenceType, 'recurrence_config' => $recurrenceConfig,
            'priority' => max(-100, min(100, (int) ($input['priority'] ?? 0))),
            'loop_enabled' => isset($input['loop_enabled']) && (string) $input['loop_enabled'] === '1',
            'items' => $items,
        ];
    }

    /** @param array<string, mixed> $data */
    private function persist(?int $scheduleId, string $publicId, array $data, int $createdBy): void
    {
        $oldDeviceId = $scheduleId === null ? 0 : (int) ($this->db->table('schedule_targets')->select('device_id')->where('schedule_id', $scheduleId)->get()->getRowArray()['device_id'] ?? 0);
        $existingStatus = $scheduleId === null ? 'active' : (string) ($this->db->table('schedules')->select('status')->where('id', $scheduleId)->get()->getRowArray()['status'] ?? 'active');
        $deviceId = (int) $data['device']->id;
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
            $this->db->table('schedule_targets')->insert(['schedule_id' => $scheduleId, 'device_id' => $deviceId, 'created_at' => gmdate('Y-m-d H:i:s')]);
            foreach ($data['items'] as $item) {
                $this->db->table('schedule_items')->insert([
                    'schedule_id' => $scheduleId, ...$item,
                    'created_at' => gmdate('Y-m-d H:i:s'), 'updated_at' => gmdate('Y-m-d H:i:s'),
                ]);
            }
            $this->bumpDeviceRevision($deviceId);
            if ($oldDeviceId > 0 && $oldDeviceId !== $deviceId) $this->bumpDeviceRevision($oldDeviceId);
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

    private function uuidV4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return sprintf('%s-%s-%s-%s-%s', substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 12, 4), substr($hex, 16, 4), substr($hex, 20));
    }
}
