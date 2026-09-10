<?php

namespace Dudev\YdbDoctrine\Tests\Fuctional;

use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Types;

/** Regression coverage for wire-type binding of date/uuid/bigint/smallint types. */
class TypeWireBindingTestCase extends AbstractFunctionalCase
{
    private function createTable(string $name): Table
    {
        $table = new Table($name);
        $table->addColumn('id', Types::INTEGER);
        $table->addColumn('d', Types::DATE_MUTABLE, ['notnull' => false]);
        $table->addColumn('di', Types::DATE_IMMUTABLE, ['notnull' => false]);
        $table->addColumn('dt', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $table->addColumn('dttz', Types::DATETIMETZ_IMMUTABLE, ['notnull' => false]);
        $table->addColumn('u', Types::GUID, ['notnull' => false]);
        $table->addColumn('big', Types::BIGINT, ['notnull' => false]);
        $table->addColumn('small', Types::SMALLINT, ['notnull' => false]);
        $table->setPrimaryKey(['id']);

        return $table;
    }

    public function testDateMutableBindsAsDate(): void
    {
        $sm = $this->connection->createSchemaManager();
        $sm->createTable($this->createTable('tmp_wire_date'));

        try {
            $this->connection->insert(
                'tmp_wire_date',
                ['id' => 1, 'd' => new \DateTime('2026-01-01')],
                ['id' => Types::INTEGER, 'd' => Types::DATE_MUTABLE],
            );

            $this->assertSame(
                '2026-01-01',
                $this->connection->fetchOne('SELECT d FROM tmp_wire_date WHERE id = ?', [1], [Types::INTEGER]),
            );
        } finally {
            $sm->dropTable('tmp_wire_date');
        }
    }

    public function testDateImmutableBindsAsDate(): void
    {
        $sm = $this->connection->createSchemaManager();
        $sm->createTable($this->createTable('tmp_wire_date_immutable'));

        try {
            $this->connection->insert(
                'tmp_wire_date_immutable',
                ['id' => 1, 'di' => new \DateTimeImmutable('2026-01-01')],
                ['id' => Types::INTEGER, 'di' => Types::DATE_IMMUTABLE],
            );

            $this->assertSame(
                '2026-01-01',
                $this->connection->fetchOne('SELECT di FROM tmp_wire_date_immutable WHERE id = ?', [1], [Types::INTEGER]),
            );
        } finally {
            $sm->dropTable('tmp_wire_date_immutable');
        }
    }

    public function testDateTimeImmutableBindsAsDatetime(): void
    {
        $sm = $this->connection->createSchemaManager();
        $sm->createTable($this->createTable('tmp_wire_datetime_immutable'));

        try {
            $this->connection->insert(
                'tmp_wire_datetime_immutable',
                ['id' => 1, 'dt' => new \DateTimeImmutable('2026-01-01 12:00:00')],
                ['id' => Types::INTEGER, 'dt' => Types::DATETIME_IMMUTABLE],
            );

            $this->assertSame(
                '2026-01-01 12:00:00',
                $this->connection->fetchOne('SELECT dt FROM tmp_wire_datetime_immutable WHERE id = ?', [1], [Types::INTEGER]),
            );
        } finally {
            $sm->dropTable('tmp_wire_datetime_immutable');
        }
    }

    public function testDateTimeTzImmutableBindsAsDatetime(): void
    {
        $sm = $this->connection->createSchemaManager();
        $sm->createTable($this->createTable('tmp_wire_datetimetz_immutable'));

        try {
            $this->connection->insert(
                'tmp_wire_datetimetz_immutable',
                ['id' => 1, 'dttz' => new \DateTimeImmutable('2026-01-01 12:00:00')],
                ['id' => Types::INTEGER, 'dttz' => Types::DATETIMETZ_IMMUTABLE],
            );

            $this->assertSame(
                '2026-01-01 12:00:00',
                $this->connection->fetchOne('SELECT dttz FROM tmp_wire_datetimetz_immutable WHERE id = ?', [1], [Types::INTEGER]),
            );
        } finally {
            $sm->dropTable('tmp_wire_datetimetz_immutable');
        }
    }

    public function testGuidBindsAsUuid(): void
    {
        $sm = $this->connection->createSchemaManager();
        $sm->createTable($this->createTable('tmp_wire_guid'));

        try {
            $this->connection->insert(
                'tmp_wire_guid',
                ['id' => 1, 'u' => '550e8400-e29b-41d4-a716-446655440000'],
                ['id' => Types::INTEGER, 'u' => Types::GUID],
            );

            $this->assertSame(
                '550e8400-e29b-41d4-a716-446655440000',
                $this->connection->fetchOne('SELECT u FROM tmp_wire_guid WHERE id = ?', [1], [Types::INTEGER]),
            );
        } finally {
            $sm->dropTable('tmp_wire_guid');
        }
    }

    public function testBigIntBindsAsInt64(): void
    {
        $sm = $this->connection->createSchemaManager();
        $sm->createTable($this->createTable('tmp_wire_bigint'));

        try {
            $this->connection->insert(
                'tmp_wire_bigint',
                ['id' => 1, 'big' => 9223372036854775807],
                ['id' => Types::INTEGER, 'big' => Types::BIGINT],
            );

            $this->assertSame(
                9223372036854775807,
                $this->connection->fetchOne('SELECT big FROM tmp_wire_bigint WHERE id = ?', [1], [Types::INTEGER]),
            );
        } finally {
            $sm->dropTable('tmp_wire_bigint');
        }
    }

    public function testSmallIntBindsAsInt16(): void
    {
        $sm = $this->connection->createSchemaManager();
        $sm->createTable($this->createTable('tmp_wire_smallint'));

        try {
            $this->connection->insert(
                'tmp_wire_smallint',
                ['id' => 1, 'small' => 12345],
                ['id' => Types::INTEGER, 'small' => Types::SMALLINT],
            );

            $this->assertEquals(
                12345,
                $this->connection->fetchOne('SELECT small FROM tmp_wire_smallint WHERE id = ?', [1], [Types::INTEGER]),
            );
        } finally {
            $sm->dropTable('tmp_wire_smallint');
        }
    }
}
