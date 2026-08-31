<?php

namespace Dudev\YdbDoctrine;

use Dudev\YdbDoctrine\Driver\YdbDriver;
use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\ParameterType;

/** @phpstan-import-type Params from DriverManager */
final class YdbConnection extends Connection
{
    /** @param Params $params */
    public function __construct(#[\SensitiveParameter] array $params, Driver $driver, ?Configuration $config = null)
    {
        if (! $driver instanceof YdbDriver) {
            throw new \InvalidArgumentException('The driver must be an instance of YdbDriver');
        }

        parent::__construct($params, $driver, $config);
    }

    public function insert(string $table, array $data, array $types = []): int|string
    {
        if (0 === count($data)) {
            // executeStatement() is documented to return int|numeric-string (matching
            // this method's own parent docblock); phpstan just infers its native
            // int|string signature here instead of that refinement.
            // @phpstan-ignore return.type
            return $this->executeStatement('INSERT INTO ' . $table . ' () VALUES ()');
        }

        $columns = [];
        $values = [];
        $set = [];

        foreach ($data as $columnName => $value) {
            $columns[] = $columnName;
            $values[] = $value;
            $set[] = '?';
        }

        // @phpstan-ignore return.type (see the same-shaped ignore above)
        return $this->executeStatement(
            'INSERT INTO ' . $table . ' (' . implode(', ', $columns) . ')' .
            ' VALUES (' . implode(', ', $set) . ')',
            $values,
            is_string(key($types)) ? $this->extractTypeValues($columns, $types) : $types,
        );
    }

    /**
     * @param list<string>            $columnList
     * @param array<array-key, mixed> $types
     *
     * @return list<mixed>
     */
    private function extractTypeValues(array $columnList, array $types): array
    {
        $typeValues = [];
        foreach ($columnList as $columnName) {
            $typeValues[] = $types[$columnName] ?? ParameterType::STRING;
        }

        return $typeValues;
    }
}
