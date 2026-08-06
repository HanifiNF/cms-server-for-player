<?php

use App\Libraries\MediaMetadataService;
use CodeIgniter\Test\CIUnitTestCase;

/** @internal */
final class MediaMetadataServiceTest extends CIUnitTestCase
{
    public function testDurationIsConvertedFromFfprobeSecondsToMilliseconds(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'cms-media-');
        file_put_contents($file, 'test');
        try {
            $service = new MediaMetadataService(['fake-ffprobe'], static fn (string $executable, string $path): string => '12.345');
            $this->assertSame(12345, $service->detectDurationMs($file));
        } finally {
            if (is_file($file)) unlink($file);
        }
    }

    public function testUnavailableProbeLeavesDurationPending(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'cms-media-');
        file_put_contents($file, 'test');
        try {
            $service = new MediaMetadataService(['fake-ffprobe'], static function (): string {
                throw new RuntimeException('not available');
            });
            $this->assertSame(0, $service->detectDurationMs($file));
        } finally {
            if (is_file($file)) unlink($file);
        }
    }
}
