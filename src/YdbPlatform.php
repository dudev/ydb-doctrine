<?php

namespace Dudev\YdbDoctrine;

use Dudev\YdbDoctrine\Platform\Keywords;
use Dudev\YdbDoctrine\SchemaManager\YdbSchemaManager;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\DateIntervalUnit;
use Doctrine\DBAL\Platforms\Keywords\KeywordList;
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
