<?php

namespace App\Libraries;

final class ScheduleTimeline
{
    /**
     * @param list<array<string, mixed>> $items
     * @return array{
     *   items:list<array<string, mixed>>,
     *   film_duration_ms:int,
     *   gap_duration_ms:int,
     *   total_duration_ms:int
     * }
     */
    public function calculate(array $items, bool $loop = false): array
    {
        $cursorMs = 0;
        $filmDurationMs = 0;
        $gapDurationMs = 0;
        $lastIndex = count($items) - 1;
        $timelineItems = [];

        foreach (array_values($items) as $index => $item) {
            $durationMs = max(0, (int) ($item['duration_override_ms'] ?? $item['durationMs'] ?? 0));
            $configuredGapMs = max(0, (int) ($item['gap_after_ms'] ?? $item['gapAfterMs'] ?? 0));
            $effectiveGapMs = ($index < $lastIndex || $loop) ? $configuredGapMs : 0;
            $startOffsetMs = $cursorMs;
            $contentEndOffsetMs = $startOffsetMs + $durationMs;
            $nextStartOffsetMs = $contentEndOffsetMs + $effectiveGapMs;

            $timelineItems[] = [
                ...$item,
                'start_offset_ms' => $startOffsetMs,
                'content_end_offset_ms' => $contentEndOffsetMs,
                'effective_gap_after_ms' => $effectiveGapMs,
                'next_start_offset_ms' => $nextStartOffsetMs,
            ];

            $filmDurationMs += $durationMs;
            $gapDurationMs += $effectiveGapMs;
            $cursorMs = $nextStartOffsetMs;
        }

        return [
            'items' => $timelineItems,
            'film_duration_ms' => $filmDurationMs,
            'gap_duration_ms' => $gapDurationMs,
            'total_duration_ms' => $cursorMs,
        ];
    }
}
