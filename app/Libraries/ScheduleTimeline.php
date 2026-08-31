<?php

namespace App\Libraries;

use InvalidArgumentException;

final class ScheduleTimeline
{
    public const MAX_GAP_MS = 86_400_000;

    public function gapFromBoundary(int $contentEndMs, int $requestedBoundaryMs): int
    {
        $gapMs = $requestedBoundaryMs - $contentEndMs;
        if ($gapMs < 0) {
            throw new InvalidArgumentException('A timeline boundary cannot be earlier than the film content end.');
        }
        if ($gapMs > self::MAX_GAP_MS) {
            throw new InvalidArgumentException('A film gap may not exceed 24 hours.');
        }
        return $gapMs;
    }

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
            $sourceDurationMs = max(0, (int) ($item['duration_override_ms'] ?? $item['sourceDurationMs'] ?? $item['durationMs'] ?? 0));
            $playbackStartOffsetMs = max(0, (int) ($item['playback_start_offset_ms'] ?? $item['startOffsetMs'] ?? 0));
            $durationMs = max(0, $sourceDurationMs - $playbackStartOffsetMs);
            $configuredGapMs = max(0, (int) ($item['gap_after_ms'] ?? $item['gapAfterMs'] ?? 0));
            $startOffsetMs = $cursorMs;
            $contentEndOffsetMs = $startOffsetMs + $durationMs;
            $effectiveGapMs = ($index < $lastIndex || $loop)
                ? $this->gapFromBoundary($contentEndOffsetMs, $contentEndOffsetMs + $configuredGapMs)
                : 0;
            $nextStartOffsetMs = $contentEndOffsetMs + $effectiveGapMs;

            $timelineItems[] = [
                ...$item,
                'playback_start_offset_ms' => $playbackStartOffsetMs,
                'effective_duration_ms' => $durationMs,
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
