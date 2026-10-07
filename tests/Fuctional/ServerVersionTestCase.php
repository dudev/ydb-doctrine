<?php

namespace Dudev\YdbDoctrine\Tests\Fuctional;

use Dudev\YdbDoctrine\Platform\ServerVersion;

class ServerVersionTestCase extends AbstractFunctionalCase
{
    public function testConnectionReportsTheServersOwnVersion(): void
    {
        $raw = $this->connection->getServerVersion();

        $this->assertNotNull(ServerVersion::tryParse($raw), "unrecognised version format: $raw");
        $this->assertNotSame(\YdbPlatform\Ydb\Ydb::VERSION, $raw, 'that is the PHP SDK version, not the server\'s');
    }

    public function testVersionIsAskedOncePerConnection(): void
    {
        $first = $this->connection->getServerVersion();

        $this->assertSame($first, $this->connection->getServerVersion());
    }
}
