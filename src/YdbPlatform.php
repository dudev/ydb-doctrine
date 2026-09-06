<?php

namespace Dudev\YdbDoctrine;

use Dudev\YdbDoctrine\Platform\Keywords;
use Dudev\YdbDoctrine\SchemaManager\YdbSchemaManager;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\DateIntervalUnit;
use Doctrine\DBAL\Platforms\Keywords\KeywordList;
use Doctrine\DBAL\Schema\Index;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Schema\TableDiff;
use Doctrine\DBAL\TransactionIsolationLevel;
use YdbPlatform\Ydb\Ydb;

final class YdbPlatform extends AbstractPlatform
{
    public function getDecimalTypeDeclarationSQL(array $column): string
    {
        return 'decimal';
    }

    public function hasNativeGuidType(): bool
    {
        return true;
    }

    public function getGuidTypeDeclarationSQL(array $column): string
    {
        return YdbTypes::UUID;
    }

    protected function getCharTypeDeclarationSQLSnippet(?int $length): string
    {
        return YdbTypes::TEXT;
    }

    protected function getVarcharTypeDeclarationSQLSnippet(?int $length): string
    {
        return YdbTypes::TEXT;
    }

    protected function getBinaryTypeDeclarationSQLSnippet(?int $length): string
    {
        return YdbTypes::BINARY;
    }

    protected function getVarbinaryTypeDeclarationSQLSnippet(?int $length): string
    {
        return YdbTypes::BINARY;
    }

    public function getJsonTypeDeclarationSQL(array $column): string
    {
        return YdbTypes::JSON;
    }

    public function getAlterTableSQL(TableDiff $diff): array
    {
        return [];
    }

    /**
     * YDB has no FOREIGN KEY support; DBAL's defaults would emit FK DDL that
     * fails against a live server. Drop it instead, same "silently
     * unsupported" treatment as getDefaultValueDeclarationSQL(). Both the
     * singular (YdbSchemaManager) and plural (ORM SchemaTool) variants need
     * the override - they're separate code paths.
     */
    public function getCreateTableSQL(Table $table): array
    {
        return $this->getCreateTableWithoutForeignKeysSQL($table);
    }

    /** @param array<Table> $tables */
    public function getCreateTablesSQL(array $tables): array
    {
        $sql = [];
        foreach ($tables as $table) {
            $sql = array_merge($sql, $this->getCreateTableWithoutForeignKeysSQL($table));
        }

        return $sql;
    }

    /** @param array<Table> $tables */
    public function getDropTablesSQL(array $tables): array
    {
        $sql = [];
        foreach ($tables as $table) {
            $sql[] = $this->getDropTableSQL($table->getQuotedName($this));
        }

        return $sql;
    }

    /**
     * Needs "ON (...)" instead of DBAL's default "(...)", and GLOBAL is
     * required despite the docs listing it as optional. Doctrine emits one of
     * these automatically for every ManyToOne join column.
     *
     * UNIQUE is undocumented anywhere but works - with a trap: omitting SYNC
     * parses fine but silently enforces nothing at all, for INSERT or UPSERT.
     * SYNC must always be explicit for UNIQUE to mean anything.
     */
    public function getIndexDeclarationSQL(Index $index): string
    {
        $unique = $index->isUnique() ? 'UNIQUE SYNC ' : '';

        return 'INDEX ' . $index->getQuotedName($this) . ' GLOBAL ' . $unique . 'ON (' .
            implode(', ', $index->getQuotedColumns($this)) . ')';
    }

    public function createSchemaManager(Connection $connection): YdbSchemaManager
    {
        $ydb = $connection->getNativeConnection();
        if (!$ydb instanceof Ydb) {
            throw new \Exception('Expected a native Ydb connection, got: ' . get_debug_type($ydb));
        }

        return new YdbSchemaManager($connection, $this, $ydb);
    }

    public function supportsSequences(): bool
    {
        return false;
    }

    public function supportsSavepoints(): bool
    {
        return false;
    }

    public function convertBooleans($item): mixed
    {
        if (is_array($item)) {
            foreach ($item as $k => $value) {
                if (!is_bool($value)) {
                    continue;
                }

                $item[$k] = $value ? 'true' : 'false';
            }
        } elseif (is_bool($item)) {
            $item = $item ? 'true' : 'false';
        }

        return $item;
    }

    /**
     * Нет DEFAULT.
     */
    public function getDefaultValueDeclarationSQL(array $column): string
    {
        return '';
    }

    public function getDateTimeTypeDeclarationSQL(array $column): string
    {
        return YdbTypes::DATETIME;
    }

    public function getDateTypeDeclarationSQL(array $column): string
    {
        return YdbTypes::DATE;
    }

    public function getDateTimeTzTypeDeclarationSQL(array $column): string
    {
        return YdbTypes::DATETIME;
    }

    public function getDateTimeFormatString(): string
    {
        return 'Y-m-d H:i:s';
    }

    public function getBooleanTypeDeclarationSQL(array $column): string
    {
        return YdbTypes::BOOL;
    }

    public function getIntegerTypeDeclarationSQL(array $column): string
    {
        return YdbTypes::INTEGER;
    }

    public function getBigIntTypeDeclarationSQL(array $column): string
    {
        return YdbTypes::BIG_INT;
    }

    public function getSmallIntTypeDeclarationSQL(array $column): string
    {
        return YdbTypes::SMALL_INT;
    }

    // phpcs:ignore PSR2.Methods.MethodDeclaration.Underscore -- overrides AbstractPlatform's own underscore-prefixed name, can't rename
    protected function _getCommonIntegerTypeDeclarationSQL(array $column): string
    {
        return '';
    }

    protected function initializeDoctrineTypeMappings(): void
    {
        $this->doctrineTypeMapping = [];
    }

    public function getClobTypeDeclarationSQL(array $column): string
    {
        return YdbTypes::STRING;
    }

    public function getBlobTypeDeclarationSQL(array $column): string
    {
        return YdbTypes::STRING;
    }

    public function getFloatDeclarationSQL(array $column): string
    {
        return YdbTypes::FLOAT;
    }

    public function getName(): string
    {
        return 'ydb';
    }

    public function getCurrentDatabaseExpression(): string
    {
        return 'CurrentUtcDate()';
    }

    protected function createReservedKeywordsList(): KeywordList
    {
        return new Keywords();
    }

    /** No standalone time-of-day type in YDB (only Date/Datetime/Timestamp). */
    public function getTimeTypeDeclarationSQL(array $column): string
    {
        throw new \Exception('YdbPlatform::getTimeTypeDeclarationSQL not implemented');
    }

    /** FIND() is 0-based and NULL-when-missing; convert to DBAL's 1-based/0-when-missing convention. */
    public function getLocateExpression(string $string, string $substring, ?string $start = null): string
    {
        if (null === $start) {
            return sprintf('COALESCE(FIND(%s, %s) + 1, 0)', $string, $substring);
        }

        return sprintf('COALESCE(FIND(%s, %s, CAST((%s) - 1 AS Uint32)) + 1, 0)', $string, $substring, $start);
    }

    /** DateTime subtraction yields an Interval; ToDays() reduces it to whole days. */
    public function getDateDiffExpression(string $date1, string $date2): string
    {
        return sprintf('DateTime::ToDays(%s - %s)', $date1, $date2);
    }

    /**
     * ShiftMonths/ShiftYears handle the calendar-variable units; ShiftDays
     * doesn't exist, so fixed-duration units use IntervalFromDays/Hours/
     * Minutes/Seconds instead. WEEK/QUARTER are simple multiples of DAY/MONTH.
     */
    protected function getDateArithmeticIntervalExpression(
        string $date,
        string $operator,
        string $interval,
        DateIntervalUnit $unit,
    ): string {
        $amount = '-' === $operator ? sprintf('(0 - (%s))', $interval) : sprintf('(%s)', $interval);

        return match ($unit) {
            DateIntervalUnit::SECOND => sprintf('%s + DateTime::IntervalFromSeconds(%s)', $date, $amount),
            DateIntervalUnit::MINUTE => sprintf('%s + DateTime::IntervalFromMinutes(%s)', $date, $amount),
            DateIntervalUnit::HOUR => sprintf('%s + DateTime::IntervalFromHours(%s)', $date, $amount),
            DateIntervalUnit::DAY => sprintf('%s + DateTime::IntervalFromDays(%s)', $date, $amount),
            DateIntervalUnit::WEEK => sprintf('%s + DateTime::IntervalFromDays((%s) * 7)', $date, $amount),
            DateIntervalUnit::MONTH => sprintf('DateTime::ShiftMonths(%s, %s)', $date, $amount),
            DateIntervalUnit::QUARTER => sprintf('DateTime::ShiftMonths(%s, (%s) * 3)', $date, $amount),
            DateIntervalUnit::YEAR => sprintf('DateTime::ShiftYears(%s, %s)', $date, $amount),
        };
    }

    /**
     * YDB has CREATE VIEW (behind the enable_views cluster feature flag), but
     * a view body must reference its source tables by fully-qualified path
     * (FROM `/local/table`) - a relative FROM silently fails to resolve later.
     *
     * Not used by YdbSchemaManager::listViews(), which bypasses this
     * select-then-getListViewsSQL pipeline entirely and talks to YDB directly,
     * like every other list*() override on that class.
     */
    public function getListViewsSQL(string $database): string
    {
        throw new \Exception('YdbPlatform::getListViewsSQL not implemented');
    }

    /**
     * Нет уровней изоляции транзакций.
     */
    public function getSetTransactionIsolationSQL(TransactionIsolationLevel $level): string
    {
        return '';
    }
}
