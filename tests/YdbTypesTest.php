<?php

namespace Dudev\YdbDoctrine\Tests;

use Dudev\YdbDoctrine\YdbTypes;
use PHPUnit\Framework\TestCase;
use YdbPlatform\Ydb\Traits\TypeValueHelpersTrait;

class YdbTypesTest extends TestCase
{
    use TypeValueHelpersTrait;

    /** @return list<array{0: string, 1: mixed}> */
    public function providerConsts(): array
    {
        return [
            [YdbTypes::BOOL, true],
//            [YdbTypes::INT8, 1],
            [YdbTypes::INT16, 1],
            [YdbTypes::INT32, 1],
            [YdbTypes::INT64, 1],
//            [YdbTypes::UINT8, 1],
//            [YdbTypes::UINT32, 1],
//            [YdbTypes::UINT64, 1],
            [YdbTypes::FLOAT, 1],
//            [YdbTypes::DOUBLE],
//            [YdbTypes::DECIMAL],
            [YdbTypes::STRING, ''],
            [YdbTypes::UTF8, ''],
            [YdbTypes::JSON, []],
//            [YdbTypes::JSON_DOCUMENT, []],
//            [YdbTypes::YSON, []],
//            [YdbTypes::UUID, ''], // as string
            [YdbTypes::DATE, new \DateTime()],
            [YdbTypes::DATETIME, new \DateTime()],
//            [YdbTypes::TIMESTAMP, time()], as datetime
//            [YdbTypes::INTERVAL],
        ];
    }

    /**
     * @dataProvider providerConsts
     */
    public function testConst(string $const, mixed $value): void
    {
        $typeObject = $this->valueOfType($value, $const);
        // getType() isn't declared on TypeContract, only on the concrete classes
        // TypeValueHelpersTrait::valueOfType() actually returns - an SDK
        // interface/implementation gap, not something fixable from here.
        // @phpstan-ignore method.notFound
        $this->assertEquals(strtolower($typeObject->getType()), $const);
    }
}
