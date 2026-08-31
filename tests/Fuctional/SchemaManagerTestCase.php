<?php

namespace Dudev\YdbDoctrine\Tests\Fuctional;

use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Types;
use YdbPlatform\Ydb\Exceptions\Grpc\UnimplementedException;

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
     * Pins today's known server limitation: no released YDB implements
     * Ydb.View.V1.ViewService.DescribeView yet (see YdbSchemaManager::listViews()),
     * so listing a real view currently fails - but with our own explanatory
     * exception, not a raw gRPC one. Once a server ships a handler, this test
     * should flip to asserting listViews() succeeds and returns the view.
     */
    public function testListViewsWrapsUnimplementedDescribeView(): void
    {
        $sm = $this->connection->createSchemaManager();
        $sm->createTable($this->createTable('tmp_view_source'));
        $this->connection->executeStatement(
            'CREATE VIEW tmp_v WITH (security_invoker = TRUE) AS SELECT id FROM `/local/tmp_view_source`',
        );

        try {
            $sm->listViews();
            $this->fail('Expected an exception because this YDB server does not implement DescribeView.');
        } catch (\Exception $e) {
            $this->assertStringContainsString('Ydb.View.V1.ViewService.DescribeView', $e->getMessage());
            $this->assertInstanceOf(UnimplementedException::class, $e->getPrevious());
        } finally {
            $this->connection->executeStatement('DROP VIEW tmp_v');
            $sm->dropTable('tmp_view_source');
        }
    }
}
