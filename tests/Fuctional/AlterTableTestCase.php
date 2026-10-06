<?php

namespace Dudev\YdbDoctrine\Tests\Fuctional;

use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Tools\SchemaTool;
use Dudev\YdbDoctrine\Platform\UnsupportedSchemaChange;
use Dudev\YdbDoctrine\Tests\App\Entity\Post;
use Dudev\YdbDoctrine\Tests\App\Entity\Profile;
use Dudev\YdbDoctrine\Tests\App\Entity\User;

/** Needs YDB 25.4 or newer, started with the feature flags from docker-compose.yml (24.x has no DROP NOT NULL). */
class AlterTableTestCase extends AbstractFunctionalCase
{
    private const TABLE = 'tmp_alter';

    public function setUp(): void
    {
        parent::setUp();
        $sm = $this->connection->createSchemaManager();
        if ($sm->tablesExist([self::TABLE])) {
            $sm->dropTable(self::TABLE);
        }
    }

    public function tearDown(): void
    {
        $sm = $this->connection->createSchemaManager();
        if ($sm->tablesExist([self::TABLE])) {
            $sm->dropTable(self::TABLE);
        }
        parent::tearDown();
    }

    private function createBaseTable(): void
    {
        $table = new Table(self::TABLE);
        $table->addColumn('id', Types::INTEGER, ['autoincrement' => true]);
        $table->addColumn('a', Types::STRING);
        $table->addColumn('b', Types::INTEGER, ['notnull' => false]);
        $table->setPrimaryKey(['id']);
        $table->addIndex(['b'], 'idx_b');
        $this->connection->createSchemaManager()->createTable($table);
    }

    private function introspect(): Table
    {
        $sm = $this->connection->createSchemaManager();

        return new Table(self::TABLE, $sm->listTableColumns(self::TABLE), $sm->listTableIndexes(self::TABLE));
    }

    private function migrateTo(Table $wanted): void
    {
        $sm = $this->connection->createSchemaManager();
        $sm->alterTable($sm->createComparator()->compareTables($this->introspect(), $wanted));
    }

    /** @return list<string> */
    private function indexNames(): array
    {
        $names = array_map(static fn ($index) => $index->getName(), array_values($this->introspect()->getIndexes()));
        sort($names);

        return $names;
    }

    public function testIntrospectionSeesSecondaryIndexes(): void
    {
        $this->createBaseTable();

        $table = $this->introspect();
        $this->assertSame(['idx_b', 'primary'], $this->indexNames());
        $this->assertFalse($table->getIndex('idx_b')->isUnique());
        $this->assertSame(['b'], $table->getIndex('idx_b')->getColumns());
    }

    public function testAddColumn(): void
    {
        $this->createBaseTable();
        $wanted = $this->introspect();
        $wanted->addColumn('c', Types::STRING, ['notnull' => false]);

        $this->migrateTo($wanted);

        $column = $this->introspect()->getColumn('c');
        $this->assertFalse($column->getNotnull());
    }

    public function testDropColumnTogetherWithItsIndex(): void
    {
        $this->createBaseTable();
        $wanted = $this->introspect();
        $wanted->dropIndex('idx_b');
        $wanted->dropColumn('b');

        $this->migrateTo($wanted);

        $this->assertFalse($this->introspect()->hasColumn('b'));
        $this->assertSame(['primary'], $this->indexNames());
    }

    public function testAddRenameAndDropIndex(): void
    {
        $this->createBaseTable();

        $wanted = $this->introspect();
        $wanted->addIndex(['a'], 'idx_a');
        $this->migrateTo($wanted);
        $this->assertSame(['idx_a', 'idx_b', 'primary'], $this->indexNames());

        $wanted = $this->introspect();
        $wanted->renameIndex('idx_a', 'idx_a_new');
        $this->migrateTo($wanted);
        $this->assertSame(['idx_a_new', 'idx_b', 'primary'], $this->indexNames());

        $wanted = $this->introspect();
        $wanted->dropIndex('idx_a_new');
        $this->migrateTo($wanted);
        $this->assertSame(['idx_b', 'primary'], $this->indexNames());
    }

    public function testAddNotNullColumnWithDefaultFillsExistingRows(): void
    {
        $this->createBaseTable();
        $this->connection->executeStatement('INSERT INTO ' . self::TABLE . " (a) VALUES ('x')");
        $wanted = $this->introspect();
        $wanted->addColumn('n', Types::INTEGER, ['default' => 5]);
        $wanted->addColumn('flag', Types::BOOLEAN, ['default' => false]);
        $wanted->addColumn('ratio', Types::FLOAT, ['default' => 1.5]);
        $wanted->addColumn('label', Types::STRING, ['default' => "it's"]);

        $this->migrateTo($wanted);

        $row = $this->connection->fetchAssociative('SELECT n, flag, ratio, label FROM ' . self::TABLE);
        $this->assertIsArray($row);
        $this->assertEquals(5, $row['n']);
        $this->assertEquals(false, $row['flag']);
        $this->assertEquals(1.5, $row['ratio']);
        $this->assertSame("it's", $row['label']);
        $this->assertTrue($this->connection->createSchemaManager()->createComparator()->compareTables($this->introspect(), $wanted)->isEmpty());
    }

    public function testDropNotNull(): void
    {
        $this->createBaseTable();
        $wanted = $this->introspect();
        $wanted->getColumn('a')->setNotnull(false);

        $this->migrateTo($wanted);

        $this->assertFalse($this->introspect()->getColumn('a')->getNotnull());
    }

    public function testAddUniqueIndexToAnExistingTable(): void
    {
        $this->createBaseTable();
        $wanted = $this->introspect();
        $wanted->addUniqueIndex(['a'], 'uniq_a');

        $this->migrateTo($wanted);

        $this->assertTrue($this->introspect()->getIndex('uniq_a')->isUnique());
    }

    public function testAppliedDiffLeavesNothingToDo(): void
    {
        $this->createBaseTable();
        $wanted = $this->introspect();
        $wanted->addColumn('c', Types::INTEGER, ['notnull' => false]);
        $wanted->addIndex(['c'], 'idx_c');
        $this->migrateTo($wanted);

        $sm = $this->connection->createSchemaManager();
        $this->assertTrue($sm->createComparator()->compareTables($this->introspect(), $wanted)->isEmpty());
    }

    public function testUnsupportedChangeThrowsInsteadOfDoingNothing(): void
    {
        $this->createBaseTable();
        $wanted = $this->introspect();
        $wanted->getColumn('b')->setNotnull(true);

        $this->expectException(UnsupportedSchemaChange::class);
        $this->migrateTo($wanted);
    }

    public function testSchemaToolSeesNoChangesInAnUnchangedOrmSchema(): void
    {
        $em = $this->createEntityManager();
        $classes = [User::class, Post::class, Profile::class];
        $this->generateSchema($em, $classes);
        $metadata = array_map(static fn ($class) => $em->getClassMetadata($class), $classes);
        $tool = new SchemaTool($em);

        try {
            // Other tables in the shared database (some tests leave them behind) get offered for dropping.
            $sql = array_filter(
                $tool->getUpdateSchemaSql($metadata),
                static fn (string $statement) => !str_starts_with($statement, 'DROP TABLE'),
            );

            $this->assertSame([], array_values($sql));
        } finally {
            $tool->dropSchema($metadata);
        }
    }
}
