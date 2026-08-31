<?php

namespace Dudev\YdbDoctrine;

use Doctrine\DBAL\Driver\Result;

class YdbSchemaResult implements Result
{
    public function fetchNumeric(): array|false
    {
        return false;
    }

    public function fetchAssociative(): array|false
    {
        return false;
    }

    public function fetchOne(): mixed
    {
        return false;
    }

    public function fetchAllNumeric(): array
    {
        return [];
    }

    public function fetchAllAssociative(): array
    {
        return [];
    }

    public function fetchFirstColumn(): array
    {
        return [];
    }

    public function rowCount(): int
    {
        return 1;
    }

    public function columnCount(): int
    {
        return 1;
    }

    public function free(): void
    {
    }
}
