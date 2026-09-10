<?php

namespace Dudev\YdbDoctrine;

use Dudev\YdbDoctrine\Value\TypedValue as YdbBoundValue;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;
use Doctrine\DBAL\ParameterType;
use Ydb\Type;
use Ydb\TypedValue;
use YdbPlatform\Ydb\QueryResult;
use YdbPlatform\Ydb\Session;
use YdbPlatform\Ydb\Traits\TypeValueHelpersTrait;

class YdbStatement implements Statement
{
    use TypeValueHelpersTrait;

    /** @var array<int|string, array{0: mixed, 1: ParameterType}> */
    private array $bindValues = [];

    /** @var array<string, TypedValue> */
    private array $parameters = [];

    /**
     * Must be the exact Session Driver\YdbConnection pinned for its lifetime,
     * not a Table (which hands out a *different* pooled session on every
     * call - see Table::session()/takeSession()) - otherwise a transaction
     * begun on one session can get "committed" on another that never saw it.
     */
    public function __construct(
        private string $sql,
        private Session $session,
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

    /** $value is a plain scalar/DateTime with a native ParameterType, or a YdbBoundValue needing a more specific YQL type. */
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
            YdbTypes::DATE => $this->typeValue($value, 'DATE')->toTypedValue(),
            YdbTypes::UUID => $this->typeValue($value, 'UUID')->toTypedValue(),
            YdbTypes::INT64 => $this->typeValue($value, 'INT64')->toTypedValue(),
            YdbTypes::INT16 => $this->typeValue($value, 'INT16')->toTypedValue(),
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
                $this->session->schemeQuery($sql);

                return new YdbSchemaResult();
            } else {
                $res = $this->session->prepare($sql)->execute($this->parameters);
                if (!$res instanceof QueryResult) {
                    throw new \Exception('Expected a QueryResult, got: ' . get_debug_type($res));
                }
                if ($res->isTruncated()) {
                    // YDB's Table Service caps a single result set (~1000 rows) and
                    // signals it via this flag instead of an error - silently
                    // returning fewer rows than actually match would be silent data
                    // loss. No fix planned upstream (ydb-platform/ydb-php-sdk#152).
                    throw new \Exception(
                        'Result set was truncated by the server (YDB Table Service caps rows per response) - '
                        . 'add LIMIT/OFFSET pagination to this query, or use scanQuery() for a full unpaginated read.'
                    );
                }

                return new YdbResult($res);
            }
        } catch (\Throwable $ex) {
            $this->clearDeadTransaction();

            throw new \Exception($sql . "\n" . ' Details: ' . $ex->getMessage(), previous: $ex);
        }
    }

    /**
     * Session::query() reuses tx_id across calls and never clears it on
     * failure, so a session gets stuck reusing a transaction the server has
     * already aborted. $session->rollBack() alone doesn't fix it either: its
     * own RPC fails for the same reason, before it resets tx_id (upstream SDK
     * bug) - reflection is the only way left to force it from outside the class.
     */
    private function clearDeadTransaction(): void
    {
        try {
            $this->session->rollBack();
        } catch (\Throwable) {
            try {
                $property = new \ReflectionProperty($this->session, 'tx_id');
                $property->setAccessible(true);
                $property->setValue($this->session, null);
            } catch (\Throwable) {
            }
        }
    }
}
