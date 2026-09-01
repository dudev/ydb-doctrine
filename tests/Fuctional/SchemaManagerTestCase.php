<?php

namespace Dudev\YdbDoctrine\Tests\Fuctional;

use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Types;

class SchemaManagerTestCase extends AbstractFunctionalCase
{
    private function createTable(string $name): Table
    {
        $table2 = new Table($name);
        $table2->addColumn('id', Types::STRING);
        $table2->addColumn('name', Types::STRING, ['notnull' => false]);
        $table2->setPrimaryKey(['id']);

        return $table2;
    }

    public function testCreateTable(): void
    {
        $this->connection->beginTransaction();
        try {
            $table2 = $this->createTable('tmp_event');
            $sm = $this->connection->createSchemaManager();
            $sm->createTable($table2);
            $this->assertTrue($sm->tablesExist(['tmp_event']));

            $sm->dropTable('tmp_event');
            $this->assertFalse($sm->tablesExist(['tmp_event']));
        } finally {
            $this->connection->rollBack();
        }
    }

    public function testInsetInNewTableStringValue(): void
    {
        $table = $this->createTable('tmp_event');
        $sm = $this->connection->createSchemaManager();
        $sm->createTable($table);

        $this->connection->transactional(function () {
            $this->connection->insert('tmp_event', ['id' => '1', 'name' => 'test']);
        });

        $this->assertEquals('test', $this->connection->fetchOne("SELECT name FROM tmp_event WHERE id = ?", [1], [Types::STRING]));
        $sm->dropTable('tmp_event');
    }

    public function testListTable(): void
    {
        $sm = $this->connection->createSchemaManager();

        $sm->createTable($this->createTable('tmp_event'));

        try {
            // The live DB is shared across the whole test run with no per-class
            // reset, so other tests' tables may legitimately coexist here -
            // assert tmp_event is present rather than assuming it's the only
            // (or first) table.
            $names = array_map(static fn ($table) => $table->getName(), $sm->listTables());
            $this->assertContains('tmp_event', $names);
        } finally {
            $sm->dropTable('tmp_event');
        }
    }

    /**
     * Pins today's real, installable state: composer.json declares the official
     * ydb-platform/ydb-php-sdk (a public package can't depend on a git fork), and
     * that SDK has no View::describeView() support at all - so listing a real
     * view currently fails with our own explanatory exception, not a raw "call to
     * undefined method" or a silently empty result. This also exercises the
     * int(20)-vs-'VIEW' entry-type detection in listViews(): confirmed live that
     * the stock SDK's stale Entry\Type enum makes listDirectory() report a view's
     * type as the bare int 20, not the string "VIEW". Once a released SDK ships
     * View::describeView(), this test should flip to asserting against a live
     * server's behavior instead (see YdbSchemaManager::listViews()).
     */
    public function testListViewsThrowsOnStockSdkWithoutDescribeView(): void
    {
        $sm = $this->connection->createSchemaManager();
        $sm->createTable($this->createTable('tmp_view_source'));
        $this->connection->executeStatement(
            'CREATE VIEW tmp_v WITH (security_invoker = TRUE) AS SELECT id FROM `/local/tmp_view_source`',
        );

        try {
            $sm->listViews();
            $this->fail('Expected an exception: the installed SDK has no View::describeView() support.');
        } catch (\Exception $e) {
            $this->assertStringContainsString('View::describeView()', $e->getMessage());
        } finally {
            $this->connection->executeStatement('DROP VIEW tmp_v');
            $sm->dropTable('tmp_view_source');
        }
    }

    /**
     * UNIQUE indexes are absent from every official YDB syntax/CLI reference
     * checked, but confirmed live to actually work - with a sharp edge: bare
     * "INDEX <name> GLOBAL UNIQUE ON (<columns>)" parses fine but silently
     * enforces nothing at all (a genuine duplicate is written without error);
     * only "GLOBAL UNIQUE SYNC ON (...)" (explicit SYNC) actually rejects a
     * conflicting write, for INSERT and UPSERT alike - see
     * YdbPlatform::getIndexDeclarationSQL(). Pins that a real conflicting
     * insert, not just the CREATE TABLE statement, is genuinely rejected -
     * and, now that YdbStatement::clearDeadTransaction() fixes the connection
     * up after that rejection (see testConnectionRecoversAfterADmlError()),
     * that a distinct value is still accepted right afterward on the same
     * connection.
     */
    public function testCreatingATableWithAUniqueIndexEnforcesUniqueness(): void
    {
        $table = $this->createTable('tmp_unique');
        $table->addUniqueIndex(['name'], 'idx_tmp_unique_name');

        $sm = $this->connection->createSchemaManager();
        $sm->createTable($table);

        try {
            $this->connection->insert('tmp_unique', ['id' => '1', 'name' => 'dup']);

            try {
                $this->connection->insert('tmp_unique', ['id' => '2', 'name' => 'dup']);
                $this->fail('Expected the unique index to reject a duplicate "name" value.');
            } catch (\Exception $e) {
                $this->assertStringContainsString('Conflict with existing key', $e->getMessage());
            }

            $this->connection->insert('tmp_unique', ['id' => '3', 'name' => 'distinct']);
            $this->assertEquals(
                'distinct',
                $this->connection->fetchOne('SELECT name FROM tmp_unique WHERE id = ?', ['3'], [Types::STRING]),
            );
        } finally {
            $sm->dropTable('tmp_unique');
        }
    }

    /**
     * Session::query() (reached via Session::prepare()->execute(), the path
     * every DML statement takes) reuses the SDK session's internal tx_id
     * across calls, only opening a fresh transaction when it's null - it
     * never clears it after a failed statement. Confirmed live (before the
     * fix in YdbStatement::clearDeadTransaction()) that this left a
     * connection permanently stuck reusing a transaction the server had
     * already aborted: every later query on it - regardless of whether it
     * had anything to do with the original failure - started failing with
     * "Transaction not found", for something as ordinary as a duplicate
     * PRIMARY KEY insert (no unique secondary index needed to trigger it at
     * all). This pins that recovery in the simplest possible case.
     */
    public function testConnectionRecoversAfterADmlError(): void
    {
        $sm = $this->connection->createSchemaManager();
        $sm->createTable($this->createTable('tmp_recover'));

        try {
            $this->connection->insert('tmp_recover', ['id' => '1', 'name' => 'first']);

            try {
                $this->connection->insert('tmp_recover', ['id' => '1', 'name' => 'duplicate-pk']);
                $this->fail('Expected a duplicate PRIMARY KEY insert to fail.');
            } catch (\Exception $e) {
                // The specific failure isn't the point here - see
                // testCreatingATableWithAUniqueIndexEnforcesUniqueness() for that.
            }

            $this->connection->insert('tmp_recover', ['id' => '2', 'name' => 'second']);
            $this->assertEquals(
                'second',
                $this->connection->fetchOne('SELECT name FROM tmp_recover WHERE id = ?', ['2'], [Types::STRING]),
            );
        } finally {
            $sm->dropTable('tmp_recover');
        }
    }

    /**
     * FK-stripping is also exercised via Doctrine ORM's SchemaTool (the plural
     * getCreateTablesSQL()) through ForeignKeyTestCase, but
     * YdbSchemaManager::createTable() - used directly here, and by anything
     * that isn't going through the ORM - hits the *singular* getCreateTableSQL(),
     * a distinct code path that was never exercised with an actual
     * ForeignKeyConstraint attached.
     */
    public function testCreatingATableWithAForeignKeyThroughTheSchemaManagerSucceeds(): void
    {
        $sm = $this->connection->createSchemaManager();

        $parent = $this->createTable('tmp_fk_parent');
        $sm->createTable($parent);

        try {
            $child = new Table('tmp_fk_child');
            $child->addColumn('id', Types::STRING);
            $child->addColumn('parent_id', Types::STRING);
            $child->setPrimaryKey(['id']);
            $child->addForeignKeyConstraint('tmp_fk_parent', ['parent_id'], ['id']);

            $sm->createTable($child);

            $this->assertTrue($sm->tablesExist(['tmp_fk_child']));
        } finally {
            if ($sm->tablesExist(['tmp_fk_child'])) {
                $sm->dropTable('tmp_fk_child');
            }

            $sm->dropTable('tmp_fk_parent');
        }
    }
}
