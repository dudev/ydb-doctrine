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
            $notnull = true;
            $type = $column['type']['typeId'] ?? null;
            if (!$type) {
                $type = $column['type']['optionalType']['item']['typeId'] ?? throw new \Exception();
                $notnull = false;
            }

            $list[$column['name']] = new Column($column['name'], $this->bindType($type), ['notnull' => $notnull]);
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
     * Requires a live YDB server that implements Ydb.View.V1.ViewService.DescribeView -
     * a draft API (see ydb-api-protos' draft/protos/ydb_view.proto) that, as of this
     * writing, no released YDB server implements yet: the RPC is wired into the gRPC
     * surface (ydb/services/view/grpc_service.cpp upstream), but there is no
     * DoDescribeView handler behind it (verified live and by reading upstream source -
     * every other Describe* RPC has a corresponding rpc_describe_*.cpp, DescribeView
     * does not), so every call fails with gRPC UNIMPLEMENTED. The client-side plumbing
     * ($ydb->view()->describeView()) is correct and was exercised against a live
     * server up to the point the server rejects the RPC; this will work as-is once a
     * server ships a real handler. Until then, that specific failure is caught below
     * and rethrown with an explanation instead of a raw gRPC exception - views created
     * via plain SQL are unaffected, only this introspection path is blocked.
     *
     * View::describeView() itself only exists on the dudev/ydb-php-sdk fork, not yet
     * on the officially published ydb-platform/ydb-php-sdk that composer.json
     * declares as this package's dependency (a public package can't require a git
     * fork - see the discussion that led here). So on a stock install, $ydb->view()
     * is simply undefined - guarded below with the same "views without SQL access
     * are unaffected" framing as the UNIMPLEMENTED case above.
     *
     * Entry type detection below accepts both 'VIEW' and the raw int 20: on the
     * real published SDK, Ydb.Scheme.Entry.Type's generated PHP enum still stops
     * at TOPIC = 17 (same stale-codegen bug the fix/scheme-entry-type-view fork PR
     * addresses), so protobuf's JSON decoding of an unrecognized enum value falls
     * back to the bare int instead of a name - confirmed live: listDirectory()
     * reports a real view's type as int(20), not "VIEW". Comparing only against
     * the string would make this method silently report zero views on a stock
     * install instead of the intended explanatory exception below.
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
