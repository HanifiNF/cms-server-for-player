<?php

namespace Tests\Unit;

use App\Entities\StorageProfile;
use CodeIgniter\Test\CIUnitTestCase;

final class StorageProfileEntityTest extends CIUnitTestCase
{
    /** @dataProvider databaseBooleanProvider */
    public function testDefaultFlagHandlesDatabaseBooleanRepresentations(mixed $databaseValue, bool $expected): void
    {
        $profile = new StorageProfile(['is_default' => $databaseValue]);

        $this->assertSame($expected, $profile->is_default);
    }

    /** @return iterable<string, array{mixed, bool}> */
    public static function databaseBooleanProvider(): iterable
    {
        yield 'postgres true' => ['t', true];
        yield 'postgres false' => ['f', false];
        yield 'native true' => [true, true];
        yield 'native false' => [false, false];
        yield 'sqlite one' => [1, true];
        yield 'sqlite zero' => [0, false];
        yield 'text true' => ['true', true];
        yield 'text false' => ['false', false];
    }
}
