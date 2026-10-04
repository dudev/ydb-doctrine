<?php

namespace Dudev\YdbDoctrine;

use Dudev\YdbDoctrine\Platform\Keywords;
use Dudev\YdbDoctrine\Platform\UnsupportedSchemaChange;
use Dudev\YdbDoctrine\SchemaManager\YdbSchemaManager;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\DateIntervalUnit;
use Doctrine\DBAL\Platforms\Keywords\KeywordList;
use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\ColumnDiff;
use Doctrine\DBAL\Schema\Index;
use Doctrine\DBAL\Schema\Name\UnquotedIdentifierFolding;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Schema\TableDiff;
use Doctrine\DBAL\TransactionIsolationLevel;
use YdbPlatform\Ydb\Ydb;

final class YdbPlatform extends AbstractPlatform
{
    public function __construct()
    {
        // YQL identifiers are case-sensitive: folding to upper case turned `idx_b` into `IDX_B` in RENAME INDEX.
        parent::__construct(UnquotedIdentifierFolding::NONE);
    }

    /**
     * YQL quotes identifiers with backticks, not the ANSI-SQL/Postgres double quote
     * AbstractPlatform defaults to - confirmed live: a quoted identifier reaching the server
     * as `"..."` is a *string literal* in YQL, not an identifier, and fails DDL with a parser
     * error ("String literal can not be used here"), not a quoting-specific complaint.
     */
    public function quoteSingleIdentifier(string $str): string
    {
        // C-style escaping inside backtick-quoted IDs (YQL's own lexer docs), not SQL-style
        // quote-doubling.
        return '`' . str_replace('`', '\\`', $str) . '`';
    }

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

    /**
     * One ALTER TABLE per change, ordered so an index is gone before its column is. What YDB can do:
     * ADD/DROP COLUMN (non-key), DROP NOT NULL, ADD/DROP/RENAME INDEX. Everything else it rejects
     * (key, type, rename, SET NOT NULL, a serial column) throws instead of silently doing nothing.
     * Foreign keys, defaults, lengths and comments are not modelled, same as in CREATE TABLE.
     */
    public function getAlterTableSQL(TableDiff $diff): array
    {
        $table = $diff->getOldTable();
        $alter = 'ALTER TABLE ' . $table->getQuotedName($this) . ' ';
        $keyColumns = array_map('strtolower', $table->getPrimaryKey()?->getColumns() ?? []);

        foreach ([...$diff->getDroppedIndexes(), ...$diff->getAddedIndexes()] as $index) {
            if ($index->isPrimary()) {
                throw new UnsupportedSchemaChange(
                    "{$table->getName()}: YDB cannot change the primary key of an existing table."
                );
            }
        }

        $sql = [];
        foreach ($diff->getDroppedIndexes() as $index) {
            $sql[] = $alter . 'DROP INDEX ' . $index->getQuotedName($this);
        }
        foreach ($diff->getIndexRenames() as $rename) {
            $sql[] = $alter . 'RENAME INDEX ' . $rename->getOldName()->toSQL($this)
                . ' TO ' . $rename->getNewIndex()->getQuotedName($this);
        }
        foreach ($diff->getDroppedColumns() as $column) {
            if (in_array(strtolower($column->getName()), $keyColumns, true)) {
                throw new UnsupportedSchemaChange(
                    "{$table->getName()}.{$column->getName()}: YDB cannot drop a primary key column."
                );
            }
            $sql[] = $alter . 'DROP COLUMN ' . $column->getQuotedName($this);
        }
        foreach ($diff->getChangedColumns() as $columnDiff) {
            $sql = [...$sql, ...$this->getAlterColumnSQL($alter, $table->getName(), $columnDiff)];
        }
        foreach ($diff->getAddedColumns() as $column) {
            $sql[] = $alter . 'ADD COLUMN ' . $this->getAddColumnDeclarationSQL($table->getName(), $column);
        }
        foreach ($diff->getAddedIndexes() as $index) {
            $sql[] = $alter . 'ADD ' . $this->getIndexDeclarationSQL($index);
        }

        return $sql;
    }

    /** @return list<string> */
    private function getAlterColumnSQL(string $alter, string $tableName, ColumnDiff $diff): array
    {
        $old = $diff->getOldColumn();
        $new = $diff->getNewColumn();
        $name = "$tableName.{$old->getName()}";

        if ($diff->hasNameChanged()) {
            throw new UnsupportedSchemaChange(
                "$name -> {$new->getName()}: YDB cannot rename a column (add the new one, copy the data, drop the old)."
            );
        }

        $oldType = $this->getColumnTypeSQL($old);
        $newType = $this->getColumnTypeSQL($new);
        if ($oldType !== $newType) {
            throw new UnsupportedSchemaChange("$name: YDB cannot change a column's type ($oldType -> $newType).");
        }

        if ($old->getNotnull() === $new->getNotnull()) {
            return [];
        }
        if ($new->getNotnull()) {
            throw new UnsupportedSchemaChange("$name: YDB cannot make an existing column NOT NULL.");
        }

        return [$alter . 'ALTER COLUMN ' . $old->getQuotedName($this) . ' DROP NOT NULL'];
    }

    private function getAddColumnDeclarationSQL(string $tableName, Column $column): string
    {
        $name = "$tableName.{$column->getName()}";
        $declaration = $this->getColumnDeclarationSQL($column->getQuotedName($this), $column->toArray());

        if ($column->getAutoincrement()) {
            throw new UnsupportedSchemaChange("$name: YDB cannot add a serial column to an existing table.");
        }
        if (!$column->getNotnull()) {
            return $declaration;
        }
        if (null === $column->getDefault()) {
            throw new UnsupportedSchemaChange(
                "$name: YDB cannot add a NOT NULL column without a DEFAULT. Make it nullable or give it a default."
            );
        }

        return $declaration . ' DEFAULT ' . $this->getDefaultLiteral($name, $column);
    }

    /** The type part of the column's declaration, without NOT NULL: what actually decides a "type change". */
    private function getColumnTypeSQL(Column $column): string
    {
        return $column->getType()->getSQLDeclaration($column->toArray(), $this);
    }

    /** DEFAULT is only needed (and only rendered) for NOT NULL columns being added; YQL wants a literal. */
    private function getDefaultLiteral(string $name, Column $column): string
    {
        $default = $column->getDefault();
        $yqlType = $this->getColumnTypeSQL($column);
        $literal = match ($yqlType) {
            YdbTypes::BOOL => match ($default) {
                true, 1, '1', 'true' => 'true',
                false, 0, '0', 'false' => 'false',
                default => null,
            },
            YdbTypes::INT16, YdbTypes::INT32, YdbTypes::INT64 => is_int($default)
                || (is_string($default) && 1 === preg_match('/^-?\d+$/', $default))
                ? (string) $default
                : null,
            YdbTypes::DOUBLE => is_numeric($default) ? (string) (float) $default : null,
            YdbTypes::UTF8, YdbTypes::STRING => is_string($default)
                ? "'" . str_replace(['\\', "'", "\n", "\r", "\t"], ['\\\\', "\\'", '\n', '\r', '\t'], $default) . "'"
                : null,
            default => null,
        };

        return $literal ?? throw new UnsupportedSchemaChange(
            "$name: cannot render the default " . var_export($default, true) . " as a YQL $yqlType literal."
        );
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

    public function supportsIdentityColumns(): bool
    {
        return true;
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
        return empty($column['autoincrement']) ? YdbTypes::INTEGER : YdbTypes::SERIAL;
    }

    public function getBigIntTypeDeclarationSQL(array $column): string
    {
        return empty($column['autoincrement']) ? YdbTypes::BIG_INT : YdbTypes::BIG_SERIAL;
    }

    public function getSmallIntTypeDeclarationSQL(array $column): string
    {
        return empty($column['autoincrement']) ? YdbTypes::SMALL_INT : YdbTypes::SMALL_SERIAL;
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
        return YdbTypes::DOUBLE;
    }

    public function getSmallFloatDeclarationSQL(array $column): string
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
