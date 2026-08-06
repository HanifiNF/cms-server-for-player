<?php

namespace App\Libraries;

use Closure;
use RuntimeException;
use Throwable;

final class MediaMetadataService
{
    /** @var list<string> */
    private array $candidates;

    /** @var Closure(string, string): string */
    private Closure $runner;

    /**
     * @param list<string>|null $candidates
     * @param callable(string, string): string|null $runner
     */
    public function __construct(?array $candidates = null, ?callable $runner = null)
    {
        $this->candidates = $candidates ?? $this->defaultCandidates();
        $this->runner = $runner === null
            ? Closure::fromCallable([$this, 'runFfprobe'])
            : Closure::fromCallable($runner);
    }

    public function detectDurationMs(string $filePath): int
    {
        if (! is_file($filePath)) throw new RuntimeException('Media file does not exist.');
        foreach ($this->candidates as $candidate) {
            if ($candidate === '') continue;
            if ($this->looksLikePath($candidate) && ! is_file($candidate)) continue;
            try {
                $output = ($this->runner)($candidate, $filePath);
                $seconds = (float) trim($output);
                if (is_finite($seconds) && $seconds > 0) return max(1, (int) round($seconds * 1000));
            } catch (Throwable $exception) {
                log_message('debug', 'Duration probe candidate failed: {message}', ['message' => $exception->getMessage()]);
            }
        }
        return 0;
    }

    /** @return list<string> */
    private function defaultCandidates(): array
    {
        $configured = trim((string) env('media.ffprobePath', ''));
        $environment = trim((string) getenv('FFPROBE_PATH'));
        $profile = trim((string) getenv('USERPROFILE'));
        return array_values(array_unique(array_filter([
            $configured,
            $environment,
            $profile !== '' ? $profile . DIRECTORY_SEPARATOR . 'scoop' . DIRECTORY_SEPARATOR . 'shims' . DIRECTORY_SEPARATOR . 'ffprobe.exe' : '',
            'ffprobe',
        ])));
    }

    private function looksLikePath(string $candidate): bool
    {
        return str_contains($candidate, '/') || str_contains($candidate, '\\');
    }

    private function runFfprobe(string $executable, string $filePath): string
    {
        $pipes = [];
        $process = proc_open([
            $executable, '-v', 'error', '-show_entries', 'format=duration',
            '-of', 'default=noprint_wrappers=1:nokey=1', $filePath,
        ], [
            0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'],
        ], $pipes, null, null, ['bypass_shell' => true, 'suppress_errors' => true]);
        if (! is_resource($process)) throw new RuntimeException('ffprobe could not be started.');
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);
        if ($exitCode !== 0) throw new RuntimeException(trim((string) $stderr) ?: "ffprobe exited with code {$exitCode}.");
        return (string) $stdout;
    }
}
