<?php

namespace Dudev\YdbDoctrine\Tests\Unit\YdbDoctrine;

use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Types;
use Dudev\YdbDoctrine\Platform\UnsupportedSchemaChange;
use Dudev\YdbDoctrine\YdbPlatform;
use PHPUnit\Framework\TestCase;

class YdbPlatformCreateTableDefaultTest extends TestCase
{
    private YdbPlatform $platform;

    protected function setUp(): void
    {
        $this->platform = new YdbPlatform();
    }

    private function createSql(Table $table): string
    {
        return implode(";\n", $this->platform->getCreateTableSQL($table));
    }

    private function table(): Table
    {
        $table = new Table('t');
        $table->addColumn('id', Types::INTEGER);
        $table->setPrimaryKey(['id']);

        return $table;
    }

    public function testDefaultFollowsNotNull(): void
    {
        $table = $this->table();
        $table->addColumn('n', Types::INTEGER, ['default' => 5]);
        $table->addColumn('flag', Types::BOOLEAN, ['default' => false]);

        $sql = $this->createSql($table);

        $this->assertStringContainsString('`n` int32 NOT NULL DEFAULT 5', $sql);
        $this->assertStringContainsString('`flag` bool NOT NULL DEFAULT false', $sql);
    }

    public function testNullableColumnKeepsItsDefaultToo(): void
    {
        $table = $this->table();
        $table->addColumn('label', Types::STRING, ['notnull' => false, 'default' => "it's"]);

        $this->assertStringContainsString("`label` utf8 DEFAULT 'it\\'s'", $this->createSql($table));
    }

    public function testNoDefaultMeansNoDefaultClause(): void
    {
        $table = $this->table();
        $table->addColumn('a', Types::STRING);
        $table->addColumn('b', Types::INTEGER, ['notnull' => false]);

        $this->assertStringNotContainsString('DEFAULT', $this->createSql($table));
    }

    public function testASerialColumnIgnoresADefault(): void
    {
        $table = new Table('t');
        $table->addColumn('id', Types::INTEGER, ['autoincrement' => true, 'default' => 1]);
        $table->setPrimaryKey(['id']);

        $this->assertStringNotContainsString('DEFAULT', $this->createSql($table));
    }

    public function testADefaultTheTypeCannotRenderThrowsInsteadOfBeingDropped(): void
    {
        $table = $this->table();
        $table->addColumn('created', Types::DATETIME_MUTABLE, ['default' => 'CURRENT_TIMESTAMP']);

        $this->expectException(UnsupportedSchemaChange::class);
        $this->expectExceptionMessage("`created`: cannot render the default 'CURRENT_TIMESTAMP' as a YQL datetime literal");
        $this->createSql($table);
    }

    public function testAnExplicitColumnDefinitionIsLeftAlone(): void
    {
        $table = $this->table();
        $table->addColumn('c', Types::INTEGER, ['columnDefinition' => 'Int32 NOT NULL', 'default' => 5]);

        $sql = $this->createSql($table);

        $this->assertStringContainsString('`c` Int32 NOT NULL', $sql);
        $this->assertStringNotContainsString('DEFAULT', $sql);
    }
}
