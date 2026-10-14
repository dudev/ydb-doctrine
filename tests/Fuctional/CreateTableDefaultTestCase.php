<?php

namespace Dudev\YdbDoctrine\Tests\Fuctional;

use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Types;

class CreateTableDefaultTestCase extends AbstractFunctionalCase
{
    private const TABLE = 'tmp_create_default';

    public function setUp(): void
    {
        parent::setUp();
        $this->dropTable();
    }

    public function tearDown(): void
    {
        $this->dropTable();
        parent::tearDown();
    }

    private function dropTable(): void
    {
        $sm = $this->connection->createSchemaManager();
        if ($sm->tablesExist([self::TABLE])) {
            $sm->dropTable(self::TABLE);
        }
    }

    private function mappedTable(): Table
    {
        $table = new Table(self::TABLE);
        $table->addColumn('id', Types::INTEGER);
        $table->addColumn('n', Types::INTEGER, ['default' => 5]);
        $table->addColumn('big', Types::BIGINT, ['default' => -3]);
        $table->addColumn('flag', Types::BOOLEAN, ['default' => false]);
        $table->addColumn('ratio', Types::FLOAT, ['default' => 1.5]);
        $table->addColumn('small', Types::SMALLFLOAT, ['default' => 1.5]);
        $table->addColumn('label', Types::STRING, ['default' => "it's"]);
        $table->addColumn('doc', Types::JSON, ['default' => '[]']);
        $table->addColumn('day', Types::DATE_MUTABLE, ['default' => '2020-01-02']);
        $table->addColumn('moment', Types::DATETIME_MUTABLE, ['default' => '2020-01-02 03:04:05']);
        $table->addColumn('ref', Types::GUID, ['default' => '6f9619ff-8b86-d011-b42d-00c04fc964ff']);
        $table->addColumn('opt', Types::STRING, ['notnull' => false, 'default' => 'x']);
        $table->addColumn('plain', Types::STRING);
        $table->setPrimaryKey(['id']);

        return $table;
    }

    public function testServerFillsInTheDefaultsOfAnInsertThatOmitsThem(): void
    {
        $this->connection->createSchemaManager()->createTable($this->mappedTable());

        $this->connection->executeStatement('INSERT INTO ' . self::TABLE . " (id, plain) VALUES (1, 'p')");

        $matching = $this->connection->fetchOne(
            'SELECT COUNT(*) FROM ' . self::TABLE . ' WHERE id = 1 AND n = 5 AND big = -3 AND flag = false'
            . " AND ratio = 1.5 AND small = Float('1.5') AND label = 'it\\'s' AND CAST(doc AS Utf8) = '[]'"
            . " AND day = Date('2020-01-02') AND moment = Datetime('2020-01-02T03:04:05Z')"
            . " AND ref = Uuid('6f9619ff-8b86-d011-b42d-00c04fc964ff') AND opt = 'x' AND plain = 'p'",
        );

        $this->assertEquals(1, $matching);
    }

    public function testDefaultsDoNotShowUpAsADifferenceAfterwards(): void
    {
        $sm = $this->connection->createSchemaManager();
        $mapped = $this->mappedTable();
        $sm->createTable($mapped);

        $introspected = new Table(self::TABLE, $sm->listTableColumns(self::TABLE), $sm->listTableIndexes(self::TABLE));

        $this->assertTrue($sm->createComparator()->compareTables($introspected, $mapped)->isEmpty());
    }
}
