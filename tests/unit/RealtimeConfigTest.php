<?php

namespace Tests\Unit;

use CodeIgniter\Test\CIUnitTestCase;
use Config\Realtime;

final class RealtimeConfigTest extends CIUnitTestCase
{
    public function testPlayerUrlIsExposedOnlyWhenRealtimeIsEnabled(): void
    {
        $config = new Realtime();
        $config->publicUrl = 'https://realtime.example.com';
        $config->enabled = false;
        $this->assertNull($config->playerUrl());

        $config->enabled = true;
        $this->assertSame('https://realtime.example.com', $config->playerUrl());
    }
}
