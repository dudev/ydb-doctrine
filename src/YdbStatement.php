<?php

namespace Dudev\YdbDoctrine;

use Dudev\YdbDoctrine\Value\TypedValue as YdbBoundValue;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;
use Doctrine\DBAL\ParameterType;
use Ydb\Type;
use Ydb\TypedValue;
use YdbPlatform\Ydb\QueryResult;
use YdbPlatform\Ydb\Table;
use YdbPlatform\Ydb\Traits\TypeValueHelpersTrait;

class YdbStatement implements Statement
{
    use TypeValueHelpersTrait;

    /** @var array<int|string, array{0: mixed, 1: ParameterType}> */
    private array $bindValues = [];

    /** @var array<string, TypedValue> */
    private array $parameters = [];

    public function __construct(
        private string $sql,
        private Table $table,
    ) {
    }

    public function bindValue(int|string $param, mixed $value, ParameterType $type = ParameterType::STRING): void
    {
        $this->bindValues[$param] = [$value, $type];
    }

    public function getRawSql(): string
    {
        $rawSql = $this->sql;
        $index = 1;
        $declareSql = [];
        foreach ($this->bindValues as $param => [$value, $type]) {
            if (null === $value) {
                $rawSql = $this->replaceFirstPlaceholder($rawSql, 'NULL');
            } else {
                $name = '$col' . $index++;
                $typedValue = $this->makeYdbType($value, $type);
                $ydbType = $typedValue->getType() ?? throw new \Exception("$name has no type");
                $typeName = Type\PrimitiveTypeId::name($ydbType->getTypeId());
                $rawSql = $this->replaceFirstPlaceholder($rawSql, $name);
                $this->parameters[$name] = $typedValue;
                $declareSql[] = sprintf("DECLARE $name AS %s;\n", $typeName);
            }
        }

        return implode($declareSql) . '' . $rawSql;
    }

    private function replaceFirstPlaceholder(string $sql, string $replacement): string
    {
        return preg_replace('/\?/', $replacement, $sql, 1)
            ?? throw new \Exception('preg_replace failed on: ' . $sql);
    }

    /**
     * $value is either a plain scalar/DateTime bound with a native DBAL ParameterType
     * (STRING/INTEGER/BOOLEAN/BINARY), or a YdbBoundValue produced by one of the custom
     * Doctrine\DBAL\Types\Type::convertToDatabaseValue() implementations (Datetime/Json/
     * Float/Decimal) that need a more specific YQL type than DBAL's ParameterType enum
     * can express.
     */
    private function makeYdbType(mixed $value, ParameterType $type): TypedValue
    {
        if ($value instanceof YdbBoundValue) {
            return $this->makeYdbTypeFor($value->value, $value->ydbType);
        }

        return match ($type) {
            ParameterType::BINARY => $this->typeValue((string) $value, 'STRING')->toTypedValue(),
            ParameterType::INTEGER => $this->typeValue((int) $value, 'INT32')->toTypedValue(),
            ParameterType::BOOLEAN => $this->makeBoolTypedValue($value),
            ParameterType::STRING, ParameterType::ASCII => $this->typeValue((string) $value, 'UTF8')->toTypedValue(),
            default => throw new \Exception("{$type->name}, $value not support"),
        };
    }

    private function makeBoolTypedValue(mixed $value): TypedValue
    {
        if ('true' === $value) {
            $value = true;
        } elseif ('false' === $value) {
            $value = false;
        } else {
            throw new \Exception("Undefined bool value equals $value");
        }

        return $this->typeValue($value, 'BOOL')->toTypedValue();
    }

    private function makeYdbTypeFor(mixed $value, string $ydbType): TypedValue
    {
        return match ($ydbType) {
            YdbTypes::DATETIME => $this->typeValue(
                $value instanceof \DateTimeImmutable ? \DateTime::createFromImmutable($value) : $value,
                'DATETIME',
            )->toTypedValue(),
            YdbTypes::JSON => $this->typeValue($value, 'JSON')->toTypedValue(),
            YdbTypes::FLOAT, YdbTypes::DECIMAL => $this->typeValue($value, 'FLOAT')->toTypedValue(),
            YdbTypes::TIMESTAMP => $this->typeValue($value, 'TIMESTAMP')->toTypedValue(),
            YdbTypes::UINT32 => $this->typeValue($value, 'UINT32')->toTypedValue(),
            YdbTypes::UINT64 => $this->typeValue($value, 'UINT64')->toTypedValue(),
            default => throw new \Exception("$ydbType, $value not support"),
        };
    }

    public function execute(): Result
    {
        $sql = $this->getRawSql();
        try {
            if (str_starts_with($sql, 'CREATE') || str_starts_with($sql, 'DROP')) {
                $this->table->schemeQuery($sql);

                return new YdbSchemaResult();
            } else {
                $res = $this->table->prepare($sql)->execute($this->parameters);
                if (!$res instanceof QueryResult) {
                    throw new \Exception('Expected a QueryResult, got: ' . get_debug_type($res));
                }

                return new YdbResult($res);
            }
        } catch (\Throwable $ex) {
            throw new \Exception($sql . "\n" . ' Details: ' . $ex->getMessage(), previous: $ex);
        }
    }
}
