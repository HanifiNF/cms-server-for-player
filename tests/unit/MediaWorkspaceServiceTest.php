<?php

use App\Libraries\MediaWorkspaceService;
use App\Libraries\ResumableUploadService;
use App\Models\MediaUploadSessionModel;
use App\Models\MediaWorkspaceSettingModel;
use App\Models\UserModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/** @internal */
final class MediaWorkspaceServiceTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $namespace = 'App';
    /** @var list<string> */
    private array $directories = [];
    private int $ownerId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ownerId = (new UserModel())->insert([
            'email' => 'workspace-owner@example.com', 'name' => 'Workspace Owner',
            'password_hash' => password_hash('Workspace-Test-Password-2026!', PASSWORD_ARGON2ID),
            'role' => 'admin', 'status' => 'active',
        ], true);
    }

    protected function tearDown(): void
    {
        (new MediaWorkspaceSettingModel())->where('id >', 0)->delete();
        foreach (array_reverse($this->directories) as $directory) $this->removeDirectory($directory);
        parent::tearDown();
    }

    public function testConfiguredWorkspaceCreatesAndResolvesEveryWorkingDirectory(): void
    {
        $root = $this->temporaryDirectory();
        $service = new MediaWorkspaceService(new MediaWorkspaceSettingModel());
        $status = $service->configure($root, $this->ownerId);

        $this->assertTrue($status['configured']);
        $this->assertTrue($status['available']);
        $this->assertFileExists($root . DIRECTORY_SEPARATOR . MediaWorkspaceService::MARKER);
        foreach (['upload_staging', 'storage_staging', 'storage_cache', 'php_upload_tmp'] as $area) {
            $this->assertDirectoryExists($service->path($area));
            $this->assertStringStartsWith((string) realpath($root), $service->path($area));
        }
    }

    public function testDifferentWorkspaceIsBlockedWhileAResumableSessionIsActive(): void
    {
        $first = $this->temporaryDirectory();
        $second = $this->temporaryDirectory();
        $service = new MediaWorkspaceService(new MediaWorkspaceSettingModel());
        $service->configure($first, $this->ownerId);
        $uploads = new ResumableUploadService(new MediaUploadSessionModel(), $service->path('upload_staging'), 4, 60, 0, static fn (): int => 1000000);
        $uploads->initiate([
            'owner_user_id' => $this->ownerId, 'purpose' => 'asset',
            'fingerprint' => str_repeat('a', 64), 'filename' => 'active.mp4',
            'mime_type' => 'video/mp4', 'size_bytes' => 6,
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('active media uploads');
        $service->configure($second, $this->ownerId);
    }

    public function testSameMarkerCanReconnectWorkspaceAtANewPathDuringActiveUpload(): void
    {
        $first = $this->temporaryDirectory();
        $reconnected = $this->temporaryDirectory();
        $service = new MediaWorkspaceService(new MediaWorkspaceSettingModel());
        $service->configure($first, $this->ownerId);
        copy($first . DIRECTORY_SEPARATOR . MediaWorkspaceService::MARKER, $reconnected . DIRECTORY_SEPARATOR . MediaWorkspaceService::MARKER);
        $uploads = new ResumableUploadService(new MediaUploadSessionModel(), $service->path('upload_staging'), 4, 60, 0, static fn (): int => 1000000);
        $session = $uploads->initiate([
            'owner_user_id' => $this->ownerId, 'purpose' => 'asset',
            'fingerprint' => str_repeat('b', 64), 'filename' => 'reconnect.mp4',
            'mime_type' => 'video/mp4', 'size_bytes' => 6,
        ]);
        $reconnectedStaging = $reconnected . DIRECTORY_SEPARATOR . 'upload-staging';
        mkdir($reconnectedStaging, 0775, true);
        copy($uploads->sourcePath($session), $reconnectedStaging . DIRECTORY_SEPARATOR . $session->staging_name);

        $status = $service->configure($reconnected, $this->ownerId);
        $this->assertSame((string) realpath($reconnected), $status['root_path']);
        $this->assertStringStartsWith((string) realpath($reconnected), $service->path('upload_staging'));
    }

    public function testConfiguredWorkspaceDoesNotFallBackWhenItsMarkerDisappears(): void
    {
        $root = $this->temporaryDirectory();
        $service = new MediaWorkspaceService(new MediaWorkspaceSettingModel());
        $service->configure($root, $this->ownerId);
        unlink($root . DIRECTORY_SEPARATOR . MediaWorkspaceService::MARKER);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('marker does not match');
        $service->path('storage_cache');
    }

    private function temporaryDirectory(): string
    {
        $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'cms-workspace-' . bin2hex(random_bytes(6));
        mkdir($directory, 0775, true);
        $this->directories[] = $directory;
        return $directory;
    }

    private function removeDirectory(string $directory): void
    {
        if (! is_dir($directory)) return;
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        @rmdir($directory);
    }
}
