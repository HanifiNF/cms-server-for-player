<?php

namespace App\Libraries;

final class MediaProcessingProgress
{
    /** @var array<string,array{0:float,1:float}> */
    private const RANGES = [
        'probing' => [0.0, 2.0],
        'hashing' => [2.0, 27.0],
        'encrypting' => [27.0, 82.0],
        'storing' => [82.0, 98.0],
        'cataloging' => [98.0, 100.0],
    ];

    private float $lastWrite = 0.0;
    private string $lastStage = '';

    public function __construct(
        private readonly ResumableUploadService $uploads,
        private readonly object $session,
    ) {}

    public function report(string $stage, int $processed = 0, int $total = 0, bool $force = false): void
    {
        [$start, $end] = self::RANGES[$stage] ?? [0.0, 100.0];
        $ratio = $total > 0 ? max(0.0, min(1.0, $processed / $total)) : 0.0;
        $percent = $start + (($end - $start) * $ratio);
        $now = microtime(true);
        if (! $force && $stage === $this->lastStage && $processed < $total && $now - $this->lastWrite < 0.25) return;
        $this->uploads->updateProgress($this->session, $stage, $processed, $total, $percent);
        $this->lastStage = $stage;
        $this->lastWrite = $now;
    }
}
