<?php

namespace Dudev\YdbDoctrine\Tests\Unit\YdbDoctrine;

use Doctrine\DBAL\Connection\StaticServerVersionProvider;
use Doctrine\DBAL\Schema\Comparator;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\ServerVersionProvider;
use Doctrine\DBAL\Types\Types;
use Dudev\YdbDoctrine\Platform\UnsupportedSchemaChange;
use Dudev\YdbDoctrine\YdbPlatform;
use PHPUnit\Framework\TestCase;

class YdbPlatformServerVersionTest extends TestCase
{
    private function oldTable(): Table
    {
        $table = new Table('t');
        $table->addColumn('id', Types::INTEGER);
        $table->addColumn('a', Types::STRING);
        $table->setPrimaryKey(['id']);

        return $table;
    }

    /** @return list<string> */
    private function alter(YdbPlatform $platform, Table $new): array
    {
        return $platform->getAlterTableSQL((new Comparator($platform))->compareTables($this->oldTable(), $new));
    }

    public function dropNotNull(): Table
    {
        $new = $this->oldTable();
        $new->getColumn('a')->setNotnull(false);

        return $new;
    }

    public function notNullWithDefault(): Table
    {
        $new = $this->oldTable();
        $new->addColumn('c', Types::INTEGER, ['default' => 5]);

        return $new;
    }

    public function uniqueIndex(): Table
    {
        $new = $this->oldTable();
        $new->addUniqueIndex(['a'], 'uniq_a');

        return $new;
    }

    /** @return iterable<string, array{string, string}> method building the wanted table, what the error names */
    public static function featuresOf251(): iterable
    {
        yield 'DROP NOT NULL' => ['dropNotNull', 't.a: DROP NOT NULL'];
        yield 'NOT NULL DEFAULT' => ['notNullWithDefault', 't.c: adding a NOT NULL column with a DEFAULT'];
        yield 'unique index' => ['uniqueIndex', 't.uniq_a: adding a unique index to an existing table'];
    }

    /** @dataProvider featuresOf251 */
    public function testRefusedBeforeAnySqlOnAnOldServer(string $wanted, string $what): void
    {
        $platform = new YdbPlatform(new StaticServerVersionProvider('24.4.4.12'));

        $this->expectException(UnsupportedSchemaChange::class);
        $this->expectExceptionMessage("$what needs YDB 25.1 or newer, this server is 24.4.4.12.");
        $this->alter($platform, $this->$wanted());
    }

    /** @dataProvider featuresOf251 */
    public function testAllowedFrom251(string $wanted): void
    {
        foreach (['25.1', 'stable-25-4-1', 'stable-26-3-1-17', 'main'] as $version) {
            $platform = new YdbPlatform(new StaticServerVersionProvider($version));

            $this->assertCount(1, $this->alter($platform, $this->$wanted()), $version);
        }
    }

    public function testNothingIsRefusedWhenTheVersionIsUnknown(): void
    {
        $unparsable = new YdbPlatform(new StaticServerVersionProvider('something new'));
        $none = new YdbPlatform();

        $this->assertCount(1, $this->alter($unparsable, $this->dropNotNull()));
        $this->assertCount(1, $this->alter($none, $this->dropNotNull()));
    }

    public function testNothingIsRefusedWhenTheServerDoesNotAnswer(): void
    {
        $platform = new YdbPlatform(new class implements ServerVersionProvider {
            public function getServerVersion(): string
            {
                throw new \RuntimeException('connection refused');
            }
        });

        $this->assertCount(1, $this->alter($platform, $this->dropNotNull()));
    }

    public function testTheVersionIsAskedLazilyAndOnce(): void
    {
        $provider = new class implements ServerVersionProvider {
            public int $asked = 0;

            public function getServerVersion(): string
            {
                ++$this->asked;

                return '26.3.1.17';
            }
        };
        $platform = new YdbPlatform($provider);
        $this->assertSame(0, $provider->asked, 'constructing the platform must not cost a query');

        $this->alter($platform, $this->oldTable());
        $this->assertSame(0, $provider->asked, 'a diff without version-dependent changes must not either');

        $this->alter($platform, $this->dropNotNull());
        $this->alter($platform, $this->uniqueIndex());
        $this->assertSame(1, $provider->asked);
    }

    public function testChangesThatNeedNoNewFeatureAreAllowedOnAnOldServer(): void
    {
        $new = $this->oldTable();
        $new->addColumn('c', Types::INTEGER, ['notnull' => false]);
        $new->addIndex(['a'], 'idx_a');

        $platform = new YdbPlatform(new StaticServerVersionProvider('24.4.4.12'));

        $this->assertCount(2, $this->alter($platform, $new));
    }
}
