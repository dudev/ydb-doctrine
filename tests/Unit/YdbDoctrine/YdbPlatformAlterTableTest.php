<?php

namespace Dudev\YdbDoctrine\Tests\Unit\YdbDoctrine;

use Doctrine\DBAL\Schema\Comparator;
use Doctrine\DBAL\Schema\ComparatorConfig;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Types;
use Dudev\YdbDoctrine\Platform\UnsupportedSchemaChange;
use Dudev\YdbDoctrine\YdbPlatform;
use PHPUnit\Framework\TestCase;

class YdbPlatformAlterTableTest extends TestCase
{
    private YdbPlatform $platform;

    protected function setUp(): void
    {
        $this->platform = new YdbPlatform();
    }

    /** What introspection returns: id serial PK, a utf8 NOT NULL, b int32 NULL, index idx_b. */
    private function oldTable(): Table
    {
        $table = new Table('t');
        $table->addColumn('id', Types::INTEGER, ['autoincrement' => true]);
        $table->addColumn('a', Types::STRING);
        $table->addColumn('b', Types::INTEGER, ['notnull' => false]);
        $table->setPrimaryKey(['id']);
        $table->addIndex(['b'], 'idx_b');

        return $table;
    }

    /** @return list<string> */
    private function alter(Table $old, Table $new, bool $detectRenames = true): array
    {
        $config = new ComparatorConfig($detectRenames, $detectRenames, false);
        $diff = (new Comparator($this->platform, $config))->compareTables($old, $new);

        return $this->platform->getAlterTableSQL($diff);
    }

    public function testNothingChangedNothingToDo(): void
    {
        $this->assertSame([], $this->alter($this->oldTable(), $this->oldTable()));
    }

    public function testAddNullableColumn(): void
    {
        $new = $this->oldTable();
        $new->addColumn('c', Types::STRING, ['notnull' => false]);

        $this->assertSame(['ALTER TABLE `t` ADD COLUMN `c` utf8'], $this->alter($this->oldTable(), $new));
    }

    public function testAddNotNullColumnNeedsADefault(): void
    {
        $new = $this->oldTable();
        $new->addColumn('c', Types::INTEGER);

        $this->expectException(UnsupportedSchemaChange::class);
        $this->expectExceptionMessage('t.c: YDB cannot add a NOT NULL column without a DEFAULT');
        $this->alter($this->oldTable(), $new);
    }

    /** @return iterable<string, array{string, array<string, mixed>, string}> */
    public static function notNullColumnsWithDefault(): iterable
    {
        yield 'int' => [Types::INTEGER, ['default' => 5], '`c` int32 NOT NULL DEFAULT 5'];
        yield 'int as numeric string' => [Types::INTEGER, ['default' => '-5'], '`c` int32 NOT NULL DEFAULT -5'];
        yield 'bigint' => [Types::BIGINT, ['default' => 5], '`c` int64 NOT NULL DEFAULT 5'];
        yield 'bool false' => [Types::BOOLEAN, ['default' => false], '`c` bool NOT NULL DEFAULT false'];
        yield 'bool true as string' => [Types::BOOLEAN, ['default' => '1'], '`c` bool NOT NULL DEFAULT true'];
        yield 'float' => [Types::FLOAT, ['default' => 1.5], '`c` double NOT NULL DEFAULT 1.5'];
        yield 'string' => [Types::STRING, ['default' => 'x'], "`c` utf8 NOT NULL DEFAULT 'x'"];
        yield 'string with quote and backslash' => [
            Types::STRING,
            ['default' => "it's a\\b\n"],
            "`c` utf8 NOT NULL DEFAULT 'it\\'s a\\\\b\\n'",
        ];
    }

    /**
     * @dataProvider notNullColumnsWithDefault
     * @param array<string, mixed> $options
     */
    public function testAddNotNullColumnWithDefault(string $type, array $options, string $expected): void
    {
        $new = $this->oldTable();
        $new->addColumn('c', $type, $options);

        $this->assertSame(["ALTER TABLE `t` ADD COLUMN $expected"], $this->alter($this->oldTable(), $new));
    }

    public function testAddNotNullColumnWithADefaultTheTypeCannotRender(): void
    {
        $new = $this->oldTable();
        $new->addColumn('c', Types::DATETIME_MUTABLE, ['default' => 'CURRENT_TIMESTAMP']);

        $this->expectException(UnsupportedSchemaChange::class);
        $this->expectExceptionMessage("cannot render the default 'CURRENT_TIMESTAMP' as a YQL datetime literal");
        $this->alter($this->oldTable(), $new);
    }

    public function testAddSerialColumnIsRejected(): void
    {
        $new = $this->oldTable();
        $new->addColumn('c', Types::INTEGER, ['autoincrement' => true]);

        $this->expectException(UnsupportedSchemaChange::class);
        $this->expectExceptionMessage('t.c: YDB cannot add a serial column');
        $this->alter($this->oldTable(), $new);
    }

    public function testDefaultIsNotRenderedForANullableColumn(): void
    {
        $new = $this->oldTable();
        $new->addColumn('c', Types::INTEGER, ['notnull' => false, 'default' => 7]);

        $this->assertSame(['ALTER TABLE `t` ADD COLUMN `c` int32'], $this->alter($this->oldTable(), $new));
    }

    public function testDropColumn(): void
    {
        $new = $this->oldTable();
        $new->dropIndex('idx_b');
        $new->dropColumn('b');

        $this->assertSame(
            ['ALTER TABLE `t` DROP INDEX `idx_b`', 'ALTER TABLE `t` DROP COLUMN `b`'],
            $this->alter($this->oldTable(), $new),
        );
    }

    public function testDropPrimaryKeyColumnIsRejected(): void
    {
        $new = new Table('t');
        $new->addColumn('a', Types::STRING);
        $new->addColumn('b', Types::INTEGER, ['notnull' => false]);
        $new->addIndex(['b'], 'idx_b');

        $this->expectException(UnsupportedSchemaChange::class);
        $this->alter($this->oldTable(), $new);
    }

    public function testChangingThePrimaryKeyIsRejected(): void
    {
        $new = $this->oldTable();
        $new->dropPrimaryKey();
        $new->setPrimaryKey(['id', 'a']);

        $this->expectException(UnsupportedSchemaChange::class);
        $this->expectExceptionMessage('cannot change the primary key');
        $this->alter($this->oldTable(), $new);
    }

    public function testDropNotNull(): void
    {
        $new = $this->oldTable();
        $new->getColumn('a')->setNotnull(false);

        $this->assertSame(['ALTER TABLE `t` ALTER COLUMN `a` DROP NOT NULL'], $this->alter($this->oldTable(), $new));
    }

    public function testSetNotNullIsRejected(): void
    {
        $new = $this->oldTable();
        $new->getColumn('b')->setNotnull(true);

        $this->expectException(UnsupportedSchemaChange::class);
        $this->expectExceptionMessage('t.b: YDB cannot make an existing column NOT NULL');
        $this->alter($this->oldTable(), $new);
    }

    public function testTypeChangeIsRejected(): void
    {
        $new = $this->oldTable();
        $new->modifyColumn('b', ['type' => \Doctrine\DBAL\Types\Type::getType(Types::BIGINT)]);

        $this->expectException(UnsupportedSchemaChange::class);
        $this->expectExceptionMessage("t.b: YDB cannot change a column's type (int32 -> int64)");
        $this->alter($this->oldTable(), $new);
    }

    public function testRenameIsRejected(): void
    {
        $new = $this->oldTable();
        $new->dropColumn('a');
        $new->addColumn('a_renamed', Types::STRING);

        $this->expectException(UnsupportedSchemaChange::class);
        $this->expectExceptionMessage('t.a -> a_renamed: YDB cannot rename a column');
        $this->alter($this->oldTable(), $new);
    }

    public function testWithoutRenameDetectionARenameIsDropPlusAdd(): void
    {
        $new = $this->oldTable();
        $new->dropColumn('b');
        $new->dropIndex('idx_b');
        $new->addColumn('b2', Types::INTEGER, ['notnull' => false]);

        $this->assertSame(
            [
                'ALTER TABLE `t` DROP INDEX `idx_b`',
                'ALTER TABLE `t` DROP COLUMN `b`',
                'ALTER TABLE `t` ADD COLUMN `b2` int32',
            ],
            $this->alter($this->oldTable(), $new, detectRenames: false),
        );
    }

    public function testAddIndexes(): void
    {
        $new = $this->oldTable();
        $new->addIndex(['a'], 'idx_a');
        $new->addUniqueIndex(['a', 'b'], 'uniq_ab');

        $this->assertSame(
            [
                'ALTER TABLE `t` ADD INDEX `idx_a` GLOBAL ON (`a`)',
                'ALTER TABLE `t` ADD INDEX `uniq_ab` GLOBAL UNIQUE SYNC ON (`a`, `b`)',
            ],
            $this->alter($this->oldTable(), $new),
        );
    }

    public function testRenameIndex(): void
    {
        $new = $this->oldTable();
        $new->renameIndex('idx_b', 'idx_b_new');

        $this->assertSame(
            ['ALTER TABLE `t` RENAME INDEX `idx_b` TO `idx_b_new`'],
            $this->alter($this->oldTable(), $new),
        );
    }

    public function testChangedIndexIsDroppedAndAdded(): void
    {
        $new = $this->oldTable();
        $new->dropIndex('idx_b');
        $new->addIndex(['a', 'b'], 'idx_b');

        $this->assertSame(
            ['ALTER TABLE `t` DROP INDEX `idx_b`', 'ALTER TABLE `t` ADD INDEX `idx_b` GLOBAL ON (`a`, `b`)'],
            $this->alter($this->oldTable(), $new),
        );
    }

    public function testNewColumnIsAddedBeforeAnIndexOnIt(): void
    {
        $new = $this->oldTable();
        $new->addColumn('c', Types::STRING, ['notnull' => false]);
        $new->addIndex(['c'], 'idx_c');

        $this->assertSame(
            ['ALTER TABLE `t` ADD COLUMN `c` utf8', 'ALTER TABLE `t` ADD INDEX `idx_c` GLOBAL ON (`c`)'],
            $this->alter($this->oldTable(), $new),
        );
    }

    /** These properties are not part of a YDB column, so a diff in them alone must not produce SQL. */
    public function testLengthDefaultCommentAndForeignKeysAreIgnored(): void
    {
        $old = $this->oldTable();
        $old->addColumn('fk', Types::INTEGER, ['notnull' => false]);
        $old->addIndex(['fk'], 'idx_fk');

        $new = clone $old;
        $new->getColumn('a')->setLength(255)->setDefault('x')->setComment('about a');
        $new->addForeignKeyConstraint('other', ['fk'], ['id']);

        $this->assertSame([], $this->alter($old, $new));
    }

    public function testTableAndColumnNamesAreBacktickQuoted(): void
    {
        $old = new Table('group');
        $old->addColumn('id', Types::INTEGER);
        $old->setPrimaryKey(['id']);
        $new = clone $old;
        $new->addColumn('variant', Types::STRING, ['notnull' => false]);

        $this->assertSame(['ALTER TABLE `group` ADD COLUMN `variant` utf8'], $this->alter($old, $new));
    }
}
