<?php

namespace Dudev\YdbDoctrine\Tests\Unit\YdbDoctrine\Platform;

use Dudev\YdbDoctrine\Platform\ServerVersion;
use PHPUnit\Framework\TestCase;

class ServerVersionTest extends TestCase
{
    /** @return iterable<string, array{string, int, int}> */
    public static function versionsSeenOnRealServers(): iterable
    {
        yield 'local image, four parts' => ['24.4.4.12', 24, 4];
        yield 'local image, 26.3' => ['26.3.1.17', 26, 3];
        yield 'managed, same build as 26.3.1.17' => ['stable-26-3-1-17', 26, 3];
        yield 'local image, short stable' => ['stable-25-4-1', 25, 4];
        yield 'two parts' => ['25.1', 25, 1];
        yield 'hotfix suffix' => ['stable-24-3-11-hotfix-13', 24, 3];
    }

    /** @dataProvider versionsSeenOnRealServers */
    public function testParse(string $raw, int $major, int $minor): void
    {
        $version = ServerVersion::tryParse($raw);

        $this->assertNotNull($version);
        $this->assertSame([$raw, $major, $minor], [$version->raw, $version->major, $version->minor]);
    }

    public function testBranchNamesAreNewerThanAnyRelease(): void
    {
        foreach (['main', 'trunk', 'edge', 'nightly'] as $branch) {
            $this->assertTrue(ServerVersion::tryParse($branch)?->isAtLeast(99, 9), $branch);
        }
    }

    public function testUnrecognisedStringIsUnknown(): void
    {
        $this->assertNull(ServerVersion::tryParse(''));
        $this->assertNull(ServerVersion::tryParse('latest'));
        $this->assertNull(ServerVersion::tryParse('v25'));
    }

    public function testIsAtLeast(): void
    {
        $version = ServerVersion::tryParse('25.1.4');

        $this->assertNotNull($version);
        $this->assertTrue($version->isAtLeast(25, 1));
        $this->assertTrue($version->isAtLeast(24, 9));
        $this->assertTrue($version->isAtLeast(25));
        $this->assertFalse($version->isAtLeast(25, 2));
        $this->assertFalse($version->isAtLeast(26));
    }
}
