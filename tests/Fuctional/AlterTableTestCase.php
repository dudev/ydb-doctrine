<?php

namespace Dudev\YdbDoctrine\Tests\Fuctional;

use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Tools\SchemaTool;
use Dudev\YdbDoctrine\Platform\UnsupportedSchemaChange;
use Dudev\YdbDoctrine\Tests\App\Entity\Post;
use Dudev\YdbDoctrine\Tests\App\Entity\Profile;
use Dudev\YdbDoctrine\Tests\App\Entity\User;

/**
 * Only what every YDB version accepts runs here (CI uses the 24.3 image): DROP NOT NULL and NOT NULL ... DEFAULT
 * need a newer server, their SQL is covered by the unit test and was checked by hand against the trunk image.
 */
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
