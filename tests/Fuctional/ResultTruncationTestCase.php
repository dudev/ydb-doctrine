<?php

namespace Dudev\YdbDoctrine\Tests\Fuctional;

/** Regression coverage for the YDB Table Service ~1000-row cap now failing loudly instead of silently truncating. */
final class ResultTruncationTestCase extends AbstractFunctionalCase
{
    private function seedTable(string $name, int $rows): void
    {
        $this->connection->executeStatement("CREATE TABLE $name (id Int32 NOT NULL, PRIMARY KEY(id))");

        // Committed explicitly - Session::query() never auto-commits on its own
        // (see ydb-doctrine-issue/8), and a later exception in this test would
        // otherwise roll back this seed data along with it.
        $this->connection->beginTransaction();
        $values = implode(',', array_map(static fn (int $i) => "($i)", range(1, $rows)));
        $this->connection->executeStatement("INSERT INTO $name (id) VALUES $values");
        $this->connection->commit();
    }

    public function testUnboundedSelectPastTheCapThrows(): void
    {
        $this->seedTable('tmp_truncation_unbounded', 1001);

        try {
            $this->expectExceptionMessageMatches('/truncated/');
            $this->connection->executeQuery('SELECT id FROM tmp_truncation_unbounded')->fetchAllAssociative();
        } finally {
            $this->connection->executeStatement('DROP TABLE tmp_truncation_unbounded');
        }
    }

    public function testLimitedSelectUnderTheCapIsUnaffected(): void
    {
        $this->seedTable('tmp_truncation_limited', 1001);

        try {
            $rows = $this->connection->executeQuery('SELECT id FROM tmp_truncation_limited LIMIT 10')
                ->fetchAllAssociative();

            $this->assertCount(10, $rows);
        } finally {
            $this->connection->executeStatement('DROP TABLE tmp_truncation_limited');
        }
    }
}
