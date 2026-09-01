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
     * YDB has no FOREIGN KEY constraint concept at all - confirmed via ydb.tech's
     * CREATE TABLE grammar reference (no REFERENCES/FOREIGN KEY syntax anywhere in
     * it) and the upstream roadmap, which never mentions referential integrity.
     * DBAL's default getCreateTableSQL()/getCreateTablesSQL()/getDropTablesSQL()
     * unconditionally emit ALTER TABLE ... ADD/DROP CONSTRAINT ... FOREIGN KEY
     * statements for any Table/Schema that declares one - which just fails against
     * a live server. All three are overridden below to drop foreign keys from the
     * generated DDL instead, the same "silently unsupported" treatment already
     * used for getDefaultValueDeclarationSQL()'s "Нет DEFAULT". Doctrine ORM
     * associations (ManyToOne/JoinColumn) still work fine without a DB-level
     * constraint - only server-side enforcement is unavailable, and the FK column
     * itself is created and populated normally either way.
     *
     * getCreateTableSQL()/getDropTablesSQL(fine-grained per Table) matter for
     * direct SchemaManager usage (YdbSchemaManager::createTable()/dropTable());
     * getCreateTablesSQL()/getDropTablesSQL() (plural) are what Doctrine ORM's
     * SchemaTool::createSchema()/dropSchema() actually call under the hood
     * (via Schema::toSql()/toDropSql()) - both paths need the override.
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
     * YDB's inline secondary-index grammar (ydb.tech's CREATE TABLE reference:
     * INDEX <name> [GLOBAL] [SYNC|ASYNC] [USING <type>] ON (<columns>)
     * [COVER (...)]) needs "ON (...)" where DBAL's generic default just uses
     * "(...)", and - despite the docs listing GLOBAL as optional - verified live
     * that this YDB build rejects the statement without it ("Unexpected token
     * 'INDEX'"); with GLOBAL present it's accepted (SYNC/ASYNC can be omitted,
     * defaults to sync). Doctrine ORM emits an index like this automatically for
     * every ManyToOne/JoinColumn (the FK column gets one for query performance,
     * even without any DB-level FK constraint - see getCreateTablesSQL() above).
     *
     * UNIQUE indexes are undocumented (absent from every official syntax/CLI
     * reference checked), but confirmed live to actually exist and actually
     * enforce uniqueness - with a sharp edge that took three separate live
     * checks to pin down: "GLOBAL UNIQUE ON (...)" (bare, no explicit sync
     * mode) parses fine and silently does NOT enforce anything - both INSERT
     * and UPSERT happily write a duplicate. Only with SYNC spelled out
     * explicitly - "GLOBAL UNIQUE SYNC ON (...)" - does a genuinely
     * conflicting write actually fail (PRECONDITION_FAILED "Conflict with
     * existing key"), for both INSERT and UPSERT alike. This is the opposite
     * of plain (non-unique) indexes, where omitting SYNC/ASYNC is confirmed
     * to default to sync-equivalent behavior - so SYNC must always be emitted
     * explicitly here, never omitted. UNIQUE combined with ASYNC is separately
     * rejected server-side outright ("unique: alternative is not implemented
     * yet: global_index"), which is moot as long as this platform only ever
     * emits SYNC for unique indexes.
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
        return YdbTypes::STRING;
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

    /**
     * YDB has no standalone time-of-day type (only Date/Datetime/Timestamp).
     * ydb-dotnet-sdk's EFCore.Ydb provider agrees: its DateTime member translator
     * throws "Ydb doesn't support TimeOnly right now" rather than mapping it to
     * anything.
     */
    public function getTimeTypeDeclarationSQL(array $column): string
    {
        throw new \Exception('YdbPlatform::getTimeTypeDeclarationSQL not implemented');
    }

    /**
     * Verified against a live YDB instance: FIND(string, substring[, start]) returns
     * a 0-based position or NULL if not found, and its start offset is 0-based too
     * (searches at-or-after that offset) - convert both ends to the 1-based/0-when-
     * missing convention every other DBAL platform uses for getLocateExpression().
     */
    public function getLocateExpression(string $string, string $substring, ?string $start = null): string
    {
        if (null === $start) {
            return sprintf('COALESCE(FIND(%s, %s) + 1, 0)', $string, $substring);
        }

        return sprintf('COALESCE(FIND(%s, %s, CAST((%s) - 1 AS Uint32)) + 1, 0)', $string, $substring, $start);
    }

    /**
     * DateTime subtraction yields an Interval (verified against a live YDB
     * instance), which DateTime::ToDays() reduces to a whole number of days -
     * matching the "diff = date1 - date2" contract this method documents.
     */
    public function getDateDiffExpression(string $date1, string $date2): string
    {
        return sprintf('DateTime::ToDays(%s - %s)', $date1, $date2);
    }

    /**
     * Verified against a live YDB instance: DateTime::ShiftMonths/ShiftYears
     * exist and handle calendar-variable units (month/year length varies), but
     * DateTime::ShiftDays does not exist on this YDB build - DateTime::IntervalFromDays
     * /Hours/Minutes/Seconds (fixed-duration units) work instead, added directly
     * via +/-. WEEK/QUARTER are simple multiples of DAY/MONTH.
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
     * YDB does have CREATE VIEW, gated behind the enable_views cluster feature
     * flag (off by default on the docker-compose.yml dev image - enabled there
     * via local_ydb deploy --enable-feature-flag enable_views). Confirmed
     * against a live instance: CREATE VIEW/DROP VIEW and SELECT FROM a view all
     * work fine via this driver's normal DataQuery execution path - the earlier
     * "table does not exist" error was because the view body referenced its
     * source table by a relative name (FROM tmp_v). A view's body is apparently
     * stored/resolved as-is with no creation-time path context, so relative
     * references inside CREATE VIEW ... AS SELECT ... FROM <table> silently fail
     * to resolve later; the source table must be fully qualified
     * (FROM `/local/tmp_v`) inside the view body. Worth remembering for any DQL
     * that ever generates CREATE VIEW SQL.
     *
     * Not implemented here: YdbSchemaManager::listViews() bypasses this method
     * entirely and talks to YDB directly (same pattern as every other list*()
     * override on that class), rather than going through the base
     * select-then-getListViewsSQL pipeline this method belongs to.
     *
     * The two real gaps that took work to close, both in the SDK fork
     * (dudev/ydb-php-sdk), not here:
     *  - scheme()->listDirectory()/describeTable() reported a view's entry type
     *    as the raw int 20 instead of "VIEW" - the SDK's generated
     *    Ydb.Scheme.Entry.Type enum was generated from a stale ydb-api-protos
     *    checkout that stopped at TOPIC = 17. Fixed by regenerating it (PR sent
     *    upstream).
     *  - there was no way at all to fetch a view's SQL definition text (needed
     *    by Doctrine\DBAL\Schema\View::__construct()) - describeTable() doesn't
     *    return it for views, and no .sys system table exposes it either. YDB
     *    does define a service for this (Ydb.View.V1.ViewService.DescribeView,
     *    see ydb-api-protos' draft/protos/ydb_view.proto - "draft" because it's
     *    not in the SDK's regular Makefile proto set), so added a small View
     *    class wrapping it, following Scheme's existing pattern.
     *
     * That new $ydb->view()->describeView() client code is correct - exercised
     * against a live instance up to the point the server responds - but no
     * released YDB server implements a DoDescribeView handler yet (confirmed by
     * reading upstream ydb-platform/ydb source: the RPC is registered in
     * ydb/services/view/grpc_service.cpp, but unlike every other Describe* RPC
     * there is no rpc_describe_view.cpp providing the actual logic), so every
     * call currently fails with gRPC UNIMPLEMENTED. YdbSchemaManager::listViews()
     * catches exactly that and rethrows with an explanation rather than a raw
     * gRPC exception; it should work as-is once a server ships a real handler.
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
