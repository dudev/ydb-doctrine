<?php

namespace Dudev\YdbDoctrine\Tests\Fuctional;

use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Types;

class SerialColumnTestCase extends AbstractFunctionalCase
{
    private function createTable(string $name, string $idType): Table
    {
        $table = new Table($name);
        $table->addColumn('id', $idType, ['autoincrement' => true]);
        $table->addColumn('v', Types::STRING, ['notnull' => false]);
        $table->setPrimaryKey(['id']);

        return $table;
    }

    public function testIntegerAutoincrementGeneratesSequentialIds(): void
    {
        $sm = $this->connection->createSchemaManager();
        $sm->createTable($this->createTable('tmp_serial_int', Types::INTEGER));

        try {
            $this->connection->insert('tmp_serial_int', ['v' => 'a'], ['v' => Types::STRING]);
            $this->connection->insert('tmp_serial_int', ['v' => 'b'], ['v' => Types::STRING]);

            $this->assertSame(
                [['id' => 1, 'v' => 'a'], ['id' => 2, 'v' => 'b']],
                $this->connection->fetchAllAssociative('SELECT id, v FROM tmp_serial_int ORDER BY id'),
            );
        } finally {
            $sm->dropTable('tmp_serial_int');
        }
    }

    public function testBigIntAutoincrementGeneratesSequentialIds(): void
    {
        $sm = $this->connection->createSchemaManager();
        $sm->createTable($this->createTable('tmp_serial_bigint', Types::BIGINT));

        try {
            $this->connection->insert('tmp_serial_bigint', ['v' => 'a'], ['v' => Types::STRING]);

            $this->assertSame(
                1,
                $this->connection->fetchOne('SELECT id FROM tmp_serial_bigint WHERE v = ?', ['a'], [Types::STRING]),
            );
        } finally {
            $sm->dropTable('tmp_serial_bigint');
        }
    }

    public function testSmallIntAutoincrementGeneratesSequentialIds(): void
    {
        $sm = $this->connection->createSchemaManager();
        $sm->createTable($this->createTable('tmp_serial_smallint', Types::SMALLINT));

        try {
            $this->connection->insert('tmp_serial_smallint', ['v' => 'a'], ['v' => Types::STRING]);

            $this->assertSame(
                1,
                $this->connection->fetchOne('SELECT id FROM tmp_serial_smallint WHERE v = ?', ['a'], [Types::STRING]),
            );
        } finally {
            $sm->dropTable('tmp_serial_smallint');
        }
    }

    public function testLastInsertIdReturnsTheGeneratedValue(): void
    {
        $sm = $this->connection->createSchemaManager();
        $sm->createTable($this->createTable('tmp_serial_last_insert_id', Types::INTEGER));

        try {
            $this->connection->insert('tmp_serial_last_insert_id', ['v' => 'a'], ['v' => Types::STRING]);
            $this->assertSame(1, $this->connection->lastInsertId());

            $this->connection->insert('tmp_serial_last_insert_id', ['v' => 'b'], ['v' => Types::STRING]);
            $this->assertSame(2, $this->connection->lastInsertId());
        } finally {
            $sm->dropTable('tmp_serial_last_insert_id');
        }
    }

    public function testCompositeSerialPrimaryKeyStillGeneratesBothValues(): void
    {
        $table = new Table('tmp_serial_composite');
        $table->addColumn('a', Types::INTEGER, ['autoincrement' => true]);
        $table->addColumn('b', Types::INTEGER, ['autoincrement' => true]);
        $table->addColumn('v', Types::STRING, ['notnull' => false]);
        $table->setPrimaryKey(['a', 'b']);

        $sm = $this->connection->createSchemaManager();
        $sm->createTable($table);

        try {
            $this->connection->insert('tmp_serial_composite', ['v' => 'x'], ['v' => Types::STRING]);

            $this->assertSame(
                ['a' => 1, 'b' => 1, 'v' => 'x'],
                $this->connection->fetchAssociative('SELECT a, b, v FROM tmp_serial_composite'),
            );

            $this->expectException(\Exception::class);
            $this->connection->lastInsertId();
        } finally {
            $sm->dropTable('tmp_serial_composite');
        }
    }

    public function testIntrospectionSurfacesAutoincrement(): void
    {
        $sm = $this->connection->createSchemaManager();
        $sm->createTable($this->createTable('tmp_serial_introspect', Types::INTEGER));

        try {
            $columns = $sm->listTableColumns('tmp_serial_introspect');

            $this->assertTrue($columns['id']->getAutoincrement());
            $this->assertFalse($columns['v']->getAutoincrement());
        } finally {
            $sm->dropTable('tmp_serial_introspect');
        }
    }
}
