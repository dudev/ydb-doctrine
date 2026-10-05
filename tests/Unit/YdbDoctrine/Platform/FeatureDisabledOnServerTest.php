<?php

namespace Dudev\YdbDoctrine\Tests\Unit\YdbDoctrine\Platform;

use Dudev\YdbDoctrine\Platform\FeatureDisabledOnServer;
use PHPUnit\Framework\TestCase;

class FeatureDisabledOnServerTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function disabledFeatures(): iterable
    {
        yield 'default' => [
            "Execution of node: KiAlterTable!\n - Adding columns with defaults is disabled",
            'enable_add_colums_with_defaults',
        ];
        yield 'unique index' => [
            'Failed item check: Adding a unique index to an existing table is disabled',
            'enable_add_unique_index',
        ];
    }

    /** @dataProvider disabledFeatures */
    public function testNamesTheFlag(string $serverMessage, string $flag): void
    {
        $original = new \RuntimeException($serverMessage);

        $error = FeatureDisabledOnServer::tryFrom('ALTER TABLE `t` ...', $original);

        $this->assertNotNull($error);
        $this->assertStringContainsString('ALTER TABLE `t` ...', $error->getMessage());
        $this->assertStringContainsString("`$flag`", $error->getMessage());
        $this->assertSame($original, $error->getPrevious());
    }

    public function testOtherErrorsAreLeftAlone(): void
    {
        $this->assertNull(FeatureDisabledOnServer::tryFrom('ALTER TABLE `t` ...', new \RuntimeException('syntax error')));
        $this->assertNull(FeatureDisabledOnServer::tryFrom('ALTER TABLE `t` ...', new \RuntimeException('Something else is disabled')));
    }
}
