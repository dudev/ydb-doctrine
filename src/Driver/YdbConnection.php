<?php

namespace Dudev\YdbDoctrine\Driver;

use Dudev\YdbDoctrine\Parser\YdbUriParser;
use Dudev\YdbDoctrine\YdbStatement;
use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;
use Psr\Log\LoggerInterface;
use YdbPlatform\Ydb\Session;
use YdbPlatform\Ydb\Ydb;

final class YdbConnection implements Connection
{
    /**
     * Pinned once for this connection's whole lifetime, not re-fetched per
     * call: Table::session() hands out a *different* pooled session every
     * call, so begin/commit/statements would otherwise silently run against
     * unrelated sessions.
     */
    private Session $session;

    private bool $inTransaction = false;

    public function __construct(
        private Ydb $ydb
    ) {
        $this->session = $this->ydb->table()->session();
        $this->session->keepAlive();
    }

    /**
     * DBAL's Connection::close() only drops its local reference and never
     * reaches the driver, so nothing else releases this session back to
     * Table's process-wide pool - delete it here instead.
     */
    public function __destruct()
    {
        try {
            $this->session->delete();
        } catch (\Throwable) {
        }
    }

    public static function makeConnectionByUrl(string $dbUri, LoggerInterface $logger = null): YdbConnection
    {
        $config = (new YdbUriParser())->parse($dbUri);
        $ydb = new Ydb($config, $logger);

        return new YdbConnection($ydb);
    }

    public function getYdb(): Ydb
    {
        return $this->ydb;
    }

    public function getServerVersion(): string
    {
        return Ydb::VERSION;
    }

    public function prepare(string $sql): Statement
    {
        return new YdbStatement($sql, $this->session, fn (): bool => $this->inTransaction);
    }

    public function query(string $sql): Result
    {
        return $this->prepare($sql)->execute();
    }

    public function quote(string $value): string
    {
        $value = \addslashes(\addslashes($value));

        return "'$value'";
    }

    public function exec(string $sql): int|string
    {
        return $this->query($sql)->rowCount();
    }

    public function lastInsertId(): int|string
    {
        throw new \Exception();
    }

    public function beginTransaction(): void
    {
        $this->session->beginTransaction();
        $this->inTransaction = true;
    }

    public function commit(): void
    {
        $this->session->commit();
        $this->inTransaction = false;
    }

    public function rollBack(): void
    {
        try {
            $this->session->rollBack();
        } catch (\Throwable) {
        } finally {
            $this->inTransaction = false;
        }
    }

    public function getNativeConnection(): Ydb
    {
        return $this->ydb;
    }
}
