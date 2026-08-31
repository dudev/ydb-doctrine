<?php

namespace Dudev\YdbDoctrine;

use Doctrine\DBAL\Cache\ArrayResult;
use Doctrine\DBAL\Driver\Result;
use YdbPlatform\Ydb\QueryResult;

class YdbResult implements Result
{
    private ArrayResult $result;

    public function __construct(
        private QueryResult $queryResult
    ) {
        $columnNames = array_column($this->queryResult->columns(), 'name');
        $rows = array_values(array_map('array_values', $this->queryResult->rows()));
        $this->result = new ArrayResult($columnNames, $rows);
    }

    public function fetchNumeric(): array|false
    {
        return $this->result->fetchNumeric();
    }

    public function fetchAssociative(): array|false
    {
        return $this->result->fetchAssociative();
    }

    public function fetchOne(): mixed
    {
        return $this->queryResult->value();
    }

    public function fetchAllNumeric(): array
    {
        return $this->result->fetchAllNumeric();
    }

    public function fetchAllAssociative(): array
    {
        return $this->result->fetchAllAssociative();
    }

    public function fetchFirstColumn(): array
    {
        return $this->result->fetchFirstColumn();
    }

    public function rowCount(): int
    {
        return $this->queryResult->rowCount();
    }

    public function columnCount(): int
    {
        return $this->queryResult->columnCount();
    }

    public function free(): void
    {
        $this->result->free();
    }
}
