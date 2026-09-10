<?php

namespace Dudev\YdbDoctrine\Tests\Fuctional;

use Doctrine\DBAL\Types\Types;
use Dudev\YdbDoctrine\Driver\YdbDriver;
use Dudev\YdbDoctrine\YdbConnection;

/** Regression coverage for standalone statements outside an explicit transaction never being durably committed. */
final class TransactionDurabilityTestCase extends AbstractFunctionalCase
{
    private const TABLE = 'tmp_durability_check';

    public function testStandaloneInsertOutsideAnExplicitTransactionIsDurablyCommitted(): void
    {
        $this->connection->executeStatement('CREATE TABLE ' . self::TABLE . ' (id Int32 NOT NULL, PRIMARY KEY(id))');

        try {
            $this->connection->insert(self::TABLE, ['id' => 1], ['id' => Types::INTEGER]);

            $this->assertSame(1, $this->countFromASeparateProcess());
        } finally {
            $this->connection->executeStatement('DROP TABLE ' . self::TABLE);
        }
    }

    public function testExplicitTransactionStillCommitsOnlyOnCommit(): void
    {
        $this->connection->executeStatement('CREATE TABLE ' . self::TABLE . ' (id Int32 NOT NULL, PRIMARY KEY(id))');

        try {
            $this->connection->beginTransaction();
            $this->connection->insert(self::TABLE, ['id' => 1], ['id' => Types::INTEGER]);
            $this->connection->insert(self::TABLE, ['id' => 2], ['id' => Types::INTEGER]);
            $this->connection->commit();

            $this->assertSame(2, $this->countFromASeparateProcess());
        } finally {
            $this->connection->executeStatement('DROP TABLE ' . self::TABLE);
        }
    }

    public function testRolledBackTransactionCommitsNothing(): void
    {
        $this->connection->executeStatement('CREATE TABLE ' . self::TABLE . ' (id Int32 NOT NULL, PRIMARY KEY(id))');

        try {
            $this->connection->beginTransaction();
            $this->connection->insert(self::TABLE, ['id' => 1], ['id' => Types::INTEGER]);
            $this->connection->rollBack();

            $this->assertSame(0, $this->countFromASeparateProcess());
        } finally {
            $this->connection->executeStatement('DROP TABLE ' . self::TABLE);
        }
    }

    /**
     * A second YdbConnection in *this* process is not a reliable check -
     * Table's session pool is process-wide static and can silently reuse the
     * same underlying SDK session, masking real cross-session visibility. A
     * genuinely separate PHP process is the only trustworthy way to check it.
     */
    private function countFromASeparateProcess(): int
    {
        $script = sprintf(
            'require %s; $c = new %s(["url" => %s], new %s()); echo (int) $c->fetchOne(%s);',
            var_export(__DIR__ . '/../../vendor/autoload.php', true),
            YdbConnection::class,
            var_export($_ENV['YDB_URL'], true),
            YdbDriver::class,
            var_export('SELECT COUNT(*) FROM ' . self::TABLE, true),
        );

        return (int) shell_exec('php -d display_errors=0 -r ' . escapeshellarg($script));
    }
}
