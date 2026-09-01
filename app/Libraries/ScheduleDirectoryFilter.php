<?php

namespace App\Libraries;

use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * Canonical, occurrence-aware filtering for the CMS schedule directory.
 * Cross-category filters use AND; values inside one category use OR.
 */
final class ScheduleDirectoryFilter
{
    private ScheduleRecurrence $recurrence;

    public function __construct(?ScheduleRecurrence $recurrence = null)
    {
        $this->recurrence = $recurrence ?? new ScheduleRecurrence();
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function normalize(array $input): array
    {
        $statuses = $this->values($input['status'] ?? []);
        $statuses = array_values(array_intersect($statuses, ['active', 'upcoming', 'completed', 'disabled']));
        $periods = $this->values($input['period'] ?? []);
        $periods = array_values(array_intersect($periods, ['morning', 'noon', 'night']));

        return [
            'q' => mb_substr(trim((string) ($input['q'] ?? '')), 0, 120),
            'location_ids' => $this->values($input['location_ids'] ?? []),
            'device_ids' => $this->values($input['device_ids'] ?? []),
            'asset_ids' => $this->values($input['asset_ids'] ?? []),
            'date_from' => $this->date((string) ($input['date_from'] ?? '')),
            'date_to' => $this->date((string) ($input['date_to'] ?? '')),
            'period' => $periods,
            'status' => $statuses,
        ];
    }

    /** @param list<array<string, mixed>> $rows @param array<string, mixed> $filters @return list<array<string, mixed>> */
    public function apply(array $rows, array $filters): array
    {
        return array_values(array_filter($rows, fn (array $row): bool => $this->matches($row, $filters)));
    }

    /** @param array<string, mixed> $row @param array<string, mixed> $filters */
    public function matches(array $row, array $filters): bool
    {
        if ($filters['status'] !== [] && ! in_array((string) ($row['display_status'] ?? ''), $filters['status'], true)) return false;

        $targets = (array) ($row['targets'] ?? []);
        $items = (array) ($row['items'] ?? []);
        if ($filters['location_ids'] !== [] || $filters['device_ids'] !== []) {
            $targetMatch = false;
            foreach ($targets as $target) {
                if (in_array((string) ($target['location_public_id'] ?? ''), $filters['location_ids'], true)
                    || in_array((string) ($target['public_id'] ?? ''), $filters['device_ids'], true)) {
                    $targetMatch = true;
                    break;
                }
            }
            if (! $targetMatch) return false;
        }

        if ($filters['asset_ids'] !== []) {
            $assetMatch = false;
            foreach ($items as $item) {
                if (in_array((string) ($item['asset_public_id'] ?? ''), $filters['asset_ids'], true)) {
                    $assetMatch = true;
                    break;
                }
            }
            if (! $assetMatch) return false;
        }

        if ($filters['q'] !== '' && ! str_contains($this->searchText($row), mb_strtolower($filters['q']))) return false;

        $occurrences = $this->relevantOccurrences($row, $filters['date_from'], $filters['date_to']);
        if (($filters['date_from'] !== null || $filters['date_to'] !== null) && $occurrences === []) return false;
        if ($filters['period'] !== [] && ! $this->matchesPeriod($row, $occurrences, $filters['period'], $filters['asset_ids'])) return false;

        return true;
    }

    /** @param array<string, mixed> $row */
    private function searchText(array $row): string
    {
        $parts = [(string) ($row['title'] ?? ''), (string) ($row['description'] ?? '')];
        foreach ((array) ($row['targets'] ?? []) as $target) {
            $parts[] = (string) ($target['name'] ?? '');
            $parts[] = (string) ($target['location_name'] ?? $target['location'] ?? '');
        }
        foreach ((array) ($row['items'] ?? []) as $item) $parts[] = (string) ($item['title_snapshot'] ?? '');
        try {
            $timezone = new DateTimeZone((string) ($row['timezone'] ?? 'Asia/Jakarta'));
            $start = (new DateTimeImmutable((string) $row['start_at'], new DateTimeZone('UTC')))->setTimezone($timezone);
            $parts[] = $start->format('l D F M Y-m-d d/m/Y H:i');
        } catch (Throwable) {
            $parts[] = (string) ($row['start_at'] ?? '');
        }
        return mb_strtolower(implode(' ', $parts));
    }

    /** @param array<string, mixed> $row @return list<array{start:DateTimeImmutable,end:DateTimeImmutable}> */
    private function relevantOccurrences(array $row, ?string $from, ?string $to): array
    {
        try { $timezone = new DateTimeZone((string) ($row['timezone'] ?? 'Asia/Jakarta')); }
        catch (Throwable) { $timezone = new DateTimeZone('Asia/Jakarta'); }

        if ($from === null && $to === null) {
            $start = new DateTimeImmutable((string) $row['start_at'], new DateTimeZone('UTC'));
            $end = new DateTimeImmutable((string) $row['end_at'], new DateTimeZone('UTC'));
            return [['start' => $start, 'end' => $end]];
        }
        $from ??= $to;
        $to ??= $from;
        if ($from === null || $to === null) return [];
        if ($from > $to) [$from, $to] = [$to, $from];
        $windowStart = (new DateTimeImmutable($from . ' 00:00:00', $timezone))->setTimezone(new DateTimeZone('UTC'));
        $windowEnd = (new DateTimeImmutable($to . ' 00:00:00', $timezone))->modify('+1 day')->setTimezone(new DateTimeZone('UTC'));
        return $this->recurrence->occurrences($row, $windowStart, $windowEnd);
    }

    /**
     * @param array<string, mixed> $row
     * @param list<array{start:DateTimeImmutable,end:DateTimeImmutable}> $occurrences
     * @param list<string> $periods
     * @param list<string> $assetIds
     */
    private function matchesPeriod(array $row, array $occurrences, array $periods, array $assetIds): bool
    {
        if ($occurrences === []) {
            $occurrences = $this->relevantOccurrences($row, null, null);
        }
        foreach ($occurrences as $occurrence) {
            $segments = [];
            if ($assetIds !== []) {
                foreach ((array) ($row['items'] ?? []) as $item) {
                    if (! in_array((string) ($item['asset_public_id'] ?? ''), $assetIds, true)) continue;
                    $segments[] = [
                        $this->addMilliseconds($occurrence['start'], (int) ($item['start_offset_ms'] ?? 0)),
                        $this->addMilliseconds($occurrence['start'], (int) ($item['content_end_offset_ms'] ?? 0)),
                    ];
                }
            } else {
                $segments[] = [$occurrence['start'], $occurrence['end']];
            }
            foreach ($segments as [$start, $end]) {
                if ($this->segmentMatchesPeriod($start, $end, (string) ($row['timezone'] ?? 'Asia/Jakarta'), $periods)) return true;
            }
        }
        return false;
    }

    /** @param list<string> $periods */
    private function segmentMatchesPeriod(DateTimeImmutable $startUtc, DateTimeImmutable $endUtc, string $timezoneName, array $periods): bool
    {
        try { $timezone = new DateTimeZone($timezoneName); }
        catch (Throwable) { $timezone = new DateTimeZone('Asia/Jakarta'); }
        $localStart = $startUtc->setTimezone($timezone);
        $localEnd = $endUtc->setTimezone($timezone);
        $cursor = $localStart->setTime(0, 0);
        $last = $localEnd->setTime(0, 0);
        $boundaries = ['morning' => [0, 9], 'noon' => [9, 15], 'night' => [15, 24]];
        for (; $cursor <= $last; $cursor = $cursor->modify('+1 day')) {
            foreach ($periods as $period) {
                [$fromHour, $toHour] = $boundaries[$period];
                $bucketStart = $cursor->modify('+' . $fromHour . ' hours')->setTimezone(new DateTimeZone('UTC'));
                $bucketEnd = $cursor->modify('+' . $toHour . ' hours')->setTimezone(new DateTimeZone('UTC'));
                if ($startUtc < $bucketEnd && $endUtc > $bucketStart) return true;
            }
        }
        return false;
    }

    private function addMilliseconds(DateTimeImmutable $value, int $milliseconds): DateTimeImmutable
    {
        return DateTimeImmutable::createFromFormat('U.u', sprintf('%.6F', (float) $value->format('U.u') + ($milliseconds / 1000)), new DateTimeZone('UTC'))
            ?: $value->modify('+' . $milliseconds . ' milliseconds');
    }

    /** @return list<string> */
    private function values(mixed $value): array
    {
        if (! is_array($value)) $value = $value === null || $value === '' ? [] : [$value];
        $values = array_map(static fn ($item): string => mb_substr(trim((string) $item), 0, 120), $value);
        return array_values(array_unique(array_filter($values, static fn (string $item): bool => $item !== '')));
    }

    private function date(string $value): ?string
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) return null;
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $date !== false && $date->format('Y-m-d') === $value ? $value : null;
    }
}
