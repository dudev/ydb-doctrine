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

        $this->assertNotEmpty($sm->listTables());
        $tableInfo = $sm->listTables()[0];

        $this->assertEquals('tmp_event', $tableInfo->getName());
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
}
