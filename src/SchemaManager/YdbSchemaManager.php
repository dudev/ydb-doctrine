<?php

namespace Dudev\YdbDoctrine\SchemaManager;

use Dudev\YdbDoctrine\YdbPlatform;
use Dudev\YdbDoctrine\YdbTypes;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Result;
use Doctrine\DBAL\Schema\AbstractSchemaManager;
use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\ForeignKeyConstraint;
use Doctrine\DBAL\Schema\Index;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Schema\TableDiff;
use Doctrine\DBAL\Schema\View;
use Doctrine\DBAL\Types\Type;
use YdbPlatform\Ydb\Exceptions\Grpc\UnimplementedException;
use YdbPlatform\Ydb\Ydb;

/** @extends AbstractSchemaManager<YdbPlatform> */
class YdbSchemaManager extends AbstractSchemaManager
{
    private Ydb $ydb;

    public function __construct(Connection $connection, AbstractPlatform $platform, Ydb $ydb)
    {
        $this->ydb = $ydb;
        parent::__construct($connection, $platform);
    }

    // phpcs:ignore PSR2.Methods.MethodDeclaration.Underscore -- overrides AbstractSchemaManager's own underscore-prefixed name, can't rename
    protected function _getPortableTableColumnDefinition(array $tableColumn): Column
    {
        throw new \Exception('YdbSchemaManager::_getPortableTableColumnDefinition not implemented');
    }

    private function bindType(string $type): Type
    {
        return YdbTypes::toDbalType($type);
    }

    public function listTableColumns(string $table): array
    {
        $list = [];
        $data = $this->ydb->table()->session()->describeTable($table);
        foreach ($data['columns'] as $column) {
            $notnull = !isset($column['type']['optionalType']);
            $item = $notnull ? $column['type'] : $column['type']['optionalType']['item'];

            // Decimal has its own precision/scale struct instead of a typeId.
            if (isset($item['decimalType'])) {
                $list[$column['name']] = new Column($column['name'], $this->bindType(YdbTypes::DECIMAL), [
                    'notnull' => $notnull,
                    'precision' => $item['decimalType']['precision'],
                    'scale' => $item['decimalType']['scale'],
                ]);
                continue;
            }

            $wireType = $item['typeId']
                ?? throw new \Exception("YDB: unrecognized column type for '{$column['name']}'");
            $list[$column['name']] = new Column($column['name'], $this->bindType($wireType), ['notnull' => $notnull]);
        }

        return $list;
    }

    public function listTableForeignKeys(string $table): array
    {
        return [];
    }

    public function listTableIndexes(string $table): array
    {
        $data = $this->ydb->table()->session()->describeTable($table);
        $columns = $data['primaryKey'];

        return ['primary' => new Index('primary', $columns, true, true, [], [])];
    }

    public function alterTable(TableDiff $tableDiff): void
    {
        // change
    }

    public function listTableNames(): array
    {
        $tableNames = [];
        foreach ($this->ydb->scheme()->listDirectory() as $table) {
            if ('TABLE' === $table['type']) {
                $tableNames[] = $table['name'];
            }
        }

        $filter = $this->connection->getConfiguration()->getSchemaAssetsFilter();

        return array_values(array_filter($tableNames, $filter));
    }

    /**
     * Two independent reasons this can fail today, both caught below and
     * rethrown with an explanation rather than a raw error - views created
     * via plain SQL are unaffected either way, only introspection is blocked:
     * the real published SDK has no View::describeView() at all (only the
     * dudev/ydb-php-sdk fork does, and a public package can't depend on a
     * fork); and no released YDB server implements the DescribeView RPC yet
     * even when the SDK does.
     *
     * Entry type is checked against both 'VIEW' and the raw int 20: the stock
     * SDK's stale Entry\Type enum makes listDirectory() report a view's type
     * as the bare int, not the string.
     *
     * @return list<View>
     */
    public function listViews(): array
    {
        $views = [];
        foreach ($this->ydb->scheme()->listDirectory() as $entry) {
            if ('VIEW' !== $entry['type'] && 20 !== $entry['type']) {
                continue;
            }

            if (!method_exists($this->ydb, 'view')) {
                throw new \Exception(
                    "Cannot list view '{$entry['name']}': the installed ydb-platform/ydb-php-sdk " .
                    'has no View::describeView() support (added in the dudev/ydb-php-sdk fork, not ' .
                    'yet released upstream - see YdbSchemaManager::listViews() for details). The ' .
                    'view itself still works fine via plain SQL - only introspection through ' .
                    'Doctrine requires that SDK capability.',
                );
            }

            try {
                $description = $this->ydb->view()->describeView($entry['name']);
            } catch (UnimplementedException $e) {
                throw new \Exception(
                    "Cannot list view '{$entry['name']}': this YDB server does not implement " .
                    'Ydb.View.V1.ViewService.DescribeView yet (see YdbSchemaManager::listViews() ' .
                    'for details). The view itself still works fine via plain SQL - only ' .
                    'introspection through Doctrine is unavailable.',
                    0,
                    $e,
                );
            }

            $views[] = new View($entry['name'], $description['query_text'] ?? '');
        }

        return $views;
    }

    /**
     * The base implementation builds Table objects from the select-then-_getPortable*
     * pipeline, which YDB doesn't go through (see below) - compose from our own
     * list*() methods instead.
     *
     * @return list<Table>
     */
    public function listTables(): array
    {
        $tables = [];
        foreach ($this->listTableNames() as $tableName) {
            $tables[] = new Table(
                $tableName,
                $this->listTableColumns($tableName),
                $this->listTableIndexes($tableName),
                [],
                $this->listTableForeignKeys($tableName),
            );
        }

        return $tables;
    }

    /*
     * DBAL's default select-then-_getPortable* introspection pipeline (SQL-query-based)
     * is bypassed entirely by the list*() overrides above, which talk to YDB's own
     * scheme/describeTable APIs instead. These stubs exist only to satisfy
     * AbstractSchemaManager's abstract contract.
     */

    protected function selectTableNames(string $databaseName): Result
    {
        throw new \Exception('YdbSchemaManager::selectTableNames not implemented');
    }

    protected function selectTableColumns(string $databaseName, ?string $tableName = null): Result
    {
        throw new \Exception('YdbSchemaManager::selectTableColumns not implemented');
    }

    protected function selectIndexColumns(string $databaseName, ?string $tableName = null): Result
    {
        throw new \Exception('YdbSchemaManager::selectIndexColumns not implemented');
    }

    protected function selectForeignKeyColumns(string $databaseName, ?string $tableName = null): Result
    {
        throw new \Exception('YdbSchemaManager::selectForeignKeyColumns not implemented');
    }

    protected function fetchTableOptionsByTable(string $databaseName, ?string $tableName = null): array
    {
        throw new \Exception('YdbSchemaManager::fetchTableOptionsByTable not implemented');
    }

    // phpcs:ignore PSR2.Methods.MethodDeclaration.Underscore -- overrides AbstractSchemaManager's own underscore-prefixed name, can't rename
    protected function _getPortableTableDefinition(array $table): string
    {
        throw new \Exception('YdbSchemaManager::_getPortableTableDefinition not implemented');
    }

    // phpcs:ignore PSR2.Methods.MethodDeclaration.Underscore -- overrides AbstractSchemaManager's own underscore-prefixed name, can't rename
    protected function _getPortableViewDefinition(array $view): View
    {
        throw new \Exception('YdbSchemaManager::_getPortableViewDefinition not implemented');
    }

    // phpcs:ignore PSR2.Methods.MethodDeclaration.Underscore -- overrides AbstractSchemaManager's own underscore-prefixed name, can't rename
    protected function _getPortableTableForeignKeyDefinition(array $tableForeignKey): ForeignKeyConstraint
    {
        throw new \Exception('YdbSchemaManager::_getPortableTableForeignKeyDefinition not implemented');
    }
}
