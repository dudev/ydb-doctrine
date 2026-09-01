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
     * call: Table::session() hands out a *different* session from its pool on
     * every call (see Table::takeSession()), so beginTransaction()/commit()/
     * statement execution would otherwise silently run against unrelated
     * sessions - confirmed live this leaves transactions dangling (never
     * actually committed on the session that opened them) until YDB's
     * MaxTxPerSession cap is hit. One real session per DBAL Connection,
     * matching how every other DBAL driver behaves, fixes both the
     * transaction-coherency bug and the session leak.
     */
    private Session $session;

    public function __construct(
        private Ydb $ydb
    ) {
        $this->session = $this->ydb->table()->session();
        $this->session->keepAlive();
    }

    /**
     * Table::$session_pool (see YdbPlatform\Ydb\Table::session()/takeSession())
     * is a process-wide static pool: a session is only ever handed back out as
     * "idle" once something calls Session::release()/delete() on it, and
     * nothing did for the lifetime of this class until now - DBAL's own
     * Connection::close() (called by consumers, e.g. in test tearDown()) only
     * drops its local reference, it never reaches the driver at all. Left
     * unfixed, every YdbConnection ever created permanently pins one real YDB
     * session as "busy" for the rest of the process, and confirmed live that
     * enough of those accumulating in one PHPUnit run - each a candidate for
     * the pool to (incorrectly, since nothing marked it reusable) keep handing
     * back out - eventually trips YDB's MaxTxPerSession cap on whichever one
     * gets reused. Deleting the session once this connection itself is done
     * for closes it out cleanly instead.
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
        return new YdbStatement($sql, $this->session);
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
    }

    public function commit(): void
    {
        $this->session->commit();
    }

    public function rollBack(): void
    {
        try {
            $this->session->rollBack();
        } catch (\Throwable) {
        }
    }

    public function getNativeConnection(): Ydb
    {
        return $this->ydb;
    }
}
