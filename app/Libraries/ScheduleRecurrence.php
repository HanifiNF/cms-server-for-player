<?php

namespace App\Libraries;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Recurrence calculations shared by validation, status labels, and payloads.
 * Weekdays use ISO-8601 values: 1 = Monday, 7 = Sunday.
 */
final class ScheduleRecurrence
{
    /** @param array<string, mixed> $schedule @return array<string, mixed>|null */
    public function config(array $schedule): ?array
    {
        if (($schedule['recurrence'] ?? 'one_time') === 'one_time') return null;
        $raw = $schedule['recurrence_config'] ?? null;
        if (is_array($raw)) return $raw;
        if (! is_string($raw) || trim($raw) === '') return [];
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    /** @param array<string, mixed> $schedule */
    public function isExpired(array $schedule, ?DateTimeImmutable $nowUtc = null): bool
    {
        $nowUtc ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));
        if (($schedule['recurrence'] ?? 'one_time') === 'one_time') {
            return $this->utc((string) $schedule['end_at']) <= $nowUtc;
        }
        $config = $this->config($schedule) ?? [];
        if (empty($config['until'])) return false;
        $timezone = $this->timezone((string) ($schedule['timezone'] ?? 'Asia/Jakarta'));
        $untilEnd = new DateTimeImmutable((string) $config['until'] . ' 23:59:59.999999', $timezone);
        return $untilEnd->setTimezone(new DateTimeZone('UTC')) < $nowUtc;
    }

    /**
     * Returns occurrences that can overlap the supplied UTC window.
     *
     * @param array<string, mixed> $schedule
     * @return list<array{start:DateTimeImmutable,end:DateTimeImmutable}>
     */
    public function occurrences(array $schedule, DateTimeImmutable $windowStartUtc, DateTimeImmutable $windowEndUtc): array
    {
        $startUtc = $this->utc((string) $schedule['start_at']);
        $endUtc = $this->utc((string) $schedule['end_at']);
        $durationSeconds = max(0.001, (float) $endUtc->format('U.u') - (float) $startUtc->format('U.u'));
        $type = (string) ($schedule['recurrence'] ?? 'one_time');
        if ($type === 'one_time') {
            return $startUtc < $windowEndUtc && $endUtc > $windowStartUtc
                ? [['start' => $startUtc, 'end' => $endUtc]] : [];
        }

        $timezone = $this->timezone((string) ($schedule['timezone'] ?? 'Asia/Jakarta'));
        $anchor = $startUtc->setTimezone($timezone);
        $config = $this->config($schedule) ?? [];
        $weekdays = array_values(array_unique(array_map('intval', (array) ($config['daysOfWeek'] ?? []))));
        if ($type === 'weekly' && $weekdays === []) $weekdays = [(int) $anchor->format('N')];
        $until = ! empty($config['until'])
            ? new DateTimeImmutable((string) $config['until'] . ' 23:59:59.999999', $timezone)
            : null;

        // Include the previous local date so an occurrence lasting across midnight is found.
        $cursor = $windowStartUtc->setTimezone($timezone)->setTime(0, 0)->sub(new DateInterval('P1D'));
        $anchorDay = $anchor->setTime(0, 0);
        if ($cursor < $anchorDay) $cursor = $anchorDay;
        $lastDay = $windowEndUtc->setTimezone($timezone)->setTime(23, 59, 59)->add(new DateInterval('P1D'));
        $result = [];
        for (; $cursor <= $lastDay; $cursor = $cursor->add(new DateInterval('P1D'))) {
            if ($type === 'weekly' && ! in_array((int) $cursor->format('N'), $weekdays, true)) continue;
            if ($type !== 'daily' && $type !== 'weekly') break;
            $localStart = $cursor->setTime(
                (int) $anchor->format('H'),
                (int) $anchor->format('i'),
                (int) $anchor->format('s'),
                (int) $anchor->format('u')
            );
            if ($localStart < $anchor || ($until !== null && $localStart > $until)) continue;
            $occurrenceStart = $localStart->setTimezone(new DateTimeZone('UTC'));
            $occurrenceEnd = $this->addSeconds($occurrenceStart, $durationSeconds);
            if ($occurrenceStart < $windowEndUtc && $occurrenceEnd > $windowStartUtc) {
                $result[] = ['start' => $occurrenceStart, 'end' => $occurrenceEnd];
            }
        }
        return $result;
    }

    /** @param array<string, mixed> $left @param array<string, mixed> $right */
    public function overlaps(array $left, array $right): bool
    {
        $leftRecurring = ($left['recurrence'] ?? 'one_time') !== 'one_time';
        $rightRecurring = ($right['recurrence'] ?? 'one_time') !== 'one_time';
        if (! $leftRecurring) {
            $windowStart = $this->utc((string) $left['start_at'])->sub(new DateInterval('P1D'));
            $windowEnd = $this->utc((string) $left['end_at'])->add(new DateInterval('P1D'));
        } elseif (! $rightRecurring) {
            $windowStart = $this->utc((string) $right['start_at'])->sub(new DateInterval('P1D'));
            $windowEnd = $this->utc((string) $right['end_at'])->add(new DateInterval('P1D'));
        } else {
            $windowStart = max($this->utc((string) $left['start_at']), $this->utc((string) $right['start_at']));
            $windowEnd = $windowStart->add(new DateInterval('P15D'));
        }
        $leftOccurrences = $this->occurrences($left, $windowStart, $windowEnd);
        $rightOccurrences = $this->occurrences($right, $windowStart, $windowEnd);
        foreach ($leftOccurrences as $a) {
            foreach ($rightOccurrences as $b) {
                if ($a['start'] < $b['end'] && $a['end'] > $b['start']) return true;
            }
        }
        return false;
    }

    private function utc(string $value): DateTimeImmutable
    {
        return (new DateTimeImmutable($value, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('UTC'));
    }

    private function timezone(string $name): DateTimeZone
    {
        try { return new DateTimeZone($name); } catch (\Throwable) { return new DateTimeZone('Asia/Jakarta'); }
    }

    private function addSeconds(DateTimeImmutable $value, float $seconds): DateTimeImmutable
    {
        return DateTimeImmutable::createFromFormat('U.u', sprintf('%.6F', (float) $value->format('U.u') + $seconds), new DateTimeZone('UTC'))
            ?: $value->modify('+' . (int) ceil($seconds) . ' seconds');
    }
}
