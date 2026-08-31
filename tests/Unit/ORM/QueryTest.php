<?php

namespace Dudev\YdbDoctrine\Tests\Unit\ORM;

use Dudev\YdbDoctrine\ORM\Query;
use PHPUnit\Framework\TestCase;

class QueryTest extends TestCase
{
    public function testInstanceOf(): void
    {
        $query = $this->createMock(Query::class);
        // Trivially true from the mock's declared type - that's the point: this
        // guards the structural fact that Query really extends the real
        // Doctrine\ORM\Query directly (no Parser/Query monkey-patch needed, see
        // Query.php), which would only ever break by editing the class hierarchy.
        // @phpstan-ignore method.alreadyNarrowedType, instanceof.alwaysTrue
        $this->assertTrue($query instanceof \Doctrine\ORM\Query);
    }
}
