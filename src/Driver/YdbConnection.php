<?php

namespace Dudev\YdbDoctrine\Driver;

use Dudev\YdbDoctrine\Parser\YdbUriParser;
use Dudev\YdbDoctrine\YdbStatement;
use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;
use Psr\Log\LoggerInterface;
use YdbPlatform\Ydb\Table;
use YdbPlatform\Ydb\Ydb;

final class YdbConnection implements Connection
{
    private Table $table;

    public function __construct(
        private Ydb $ydb
    ) {
        $this->table = $this->ydb->table();
        $this->table->session()->keepAlive();
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
        return new YdbStatement($sql, $this->table);
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
        $this->table->session()->beginTransaction();
    }

    public function commit(): void
    {
        $this->table->session()->commit();
    }

    public function rollBack(): void
    {
        try {
            $this->table->session()->rollBack();
        } catch (\Throwable) {
        }
    }

    public function getNativeConnection(): Ydb
    {
        return $this->ydb;
    }
}
