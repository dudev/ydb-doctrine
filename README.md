[![Test](https://github.com/dudev/ydb-doctrine/actions/workflows/php.yml/badge.svg?branch=master)](https://github.com/dudev/ydb-doctrine/actions/workflows/php.yml)

A [YDB](https://ydb.tech) driver for Doctrine DBAL 4 / ORM 3, built on top of [ydb-platform/ydb-php-sdk](https://github.com/ydb-platform/ydb-php-sdk).

## Installation

```bash
composer require dudev/ydb-doctrine:dev-master
```

## Connecting

The connection string is a custom `url` DSN, passed either directly or via `driverOptions.url`:

```bash
# Anonymous access - used for local development against a Docker YDB instance.
DATABASE_URL="ydb://localhost:2136/local?discovery=false&iam_config[anonymous]=true&iam_config[insecure]=true"

# Yandex Cloud, metadata-based IAM auth.
DATABASE_URL="ydb://ydb.serverless.yandexcloud.net:2135/ru-central1/<folder-id>/<database-id>?discovery=false&iam_config[temp_dir]=/tmp&iam_config[use_metadata]=true"
```

### Symfony

```yaml
parameters:
  doctrine.orm.entity_manager.class: Dudev\YdbDoctrine\ORM\EntityManager

doctrine:
    dbal:
        options:
            url: '%env(resolve:DATABASE_URL)%'
        driver_class: Dudev\YdbDoctrine\Driver\YdbDriver
        wrapper_class: Dudev\YdbDoctrine\YdbConnection
        server_version: 1.4
    orm:
        dql:
            string_functions:
                rand: Dudev\YdbDoctrine\ORM\Functions\Rand
```

## Creating tables

Through the DBAL schema manager directly:

```php
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Types;

$table = new Table('event');
$table->addColumn('id', Types::INTEGER, ['autoincrement' => true]);
$table->addColumn('name', Types::STRING, ['notnull' => false]);
$table->setPrimaryKey(['id']);
$connection->createSchemaManager()->createTable($table);
```

`autoincrement` generates a `Serial`/`Bigserial`/`SmallSerial` column backed by YDB's own `Sequence` object - works the same way through the ORM's `#[GeneratedValue]`, as long as the identifier is a single column (YDB itself allows a composite `Serial` PK, but Doctrine ORM rejects `#[GeneratedValue]` on a composite identifier outright, regardless of platform).

## Custom DQL functions

| Function              | Usage           |
|------------------------|-----------------|
| `Dudev\YdbDoctrine\ORM\Functions\Rand` | `RAND(columnName)` in DQL |

## Type mapping

`Doctrine\DBAL\Types\Types::*` a column is declared with, to the YQL type it's stored as:

| `Types::*`              | YQL type            | Notes |
|--------------------------|----------------------|-------|
| `BOOLEAN`                 | `Bool`               | |
| `SMALLINT`                | `Int16`               | `SmallSerial` if `autoincrement` |
| `INTEGER`                 | `Int32`               | `Serial` if `autoincrement` |
| `BIGINT`                  | `Int64`               | `Bigserial` if `autoincrement` |
| `SMALLFLOAT`              | `Float`               | single precision |
| `FLOAT`                   | `Double`              | double precision, matching DBAL's own semantics |
| `DECIMAL`                 | `Decimal(p,s)`        | DDL only - writing a value currently loses precision, see [ydb-platform/ydb-php-sdk#281](https://github.com/ydb-platform/ydb-php-sdk/pull/281) |
| `STRING`, `TEXT`          | `Utf8`                | `TEXT`'s DDL currently emits `String` rather than `Utf8` - writes still work (YQL allows the implicit cast), but introspection won't round-trip the distinction |
| `BINARY`                  | `String`              | |
| `GUID`, Symfony's `'uuid'`| `Uuid`                | |
| `JSON`                    | `Json`                | |
| `DATE_MUTABLE`, `DATE_IMMUTABLE` | `Date`         | |
| `DATETIME_MUTABLE`, `DATETIME_IMMUTABLE` | `Datetime` | |
| `DATETIMETZ_MUTABLE`, `DATETIMETZ_IMMUTABLE` | `Datetime` | timezone isn't modeled separately - see YDB's own `Timestamp`/`Date`/`Datetime` reference if you need offsets |

Anything not listed above (`BLOB`, `ARRAY`, `OBJECT`, `SIMPLE_ARRAY`, `ASCII_STRING`, `DATEINTERVAL`, `TIME_MUTABLE`/`TIME_IMMUTABLE`, ...) isn't wired up and will fail on write.

### `Dudev\YdbDoctrine\YdbTypes`

Wire-type constants used internally to tag values for the SDK's binder (`Value\TypedValue`) - mainly useful if you're writing your own `Doctrine\DBAL\Types\Type` for a YQL type this package doesn't cover yet.

| Constant | Value | YQL type |
|---|---|---|
| `BOOL` | `bool` | `Bool` |
| `INT8` | `int8` | `Int8` (not reachable through any registered `Types::*`) |
| `INT16` | `int16` | `Int16` |
| `INT32` | `int32` | `Int32` |
| `INT64` | `int64` | `Int64` |
| `UINT8` | `uint8` | `Uint8` (not reachable through any registered `Types::*`) |
| `UINT32` | `uint32` | `Uint32` (not reachable through any registered `Types::*`) |
| `UINT64` | `uint64` | `Uint64` (not reachable through any registered `Types::*`) |
| `SMALL_SERIAL` | `smallserial` | `SmallSerial` |
| `SERIAL` | `serial` | `Serial` |
| `BIG_SERIAL` | `bigserial` | `Bigserial` |
| `FLOAT` | `float` | `Float` |
| `DOUBLE` | `double` | `Double` |
| `DECIMAL` | `decimal` | `Decimal(p,s)` |
| `STRING` | `string` | `String` |
| `UTF8` | `utf8` | `Utf8` |
| `JSON` | `json` | `Json` |
| `JSON_DOCUMENT` | `jsonDocument` | `JsonDocument` (not reachable through any registered `Types::*`) |
| `YSON` | `yson` | `Yson` (not reachable through any registered `Types::*`) |
| `UUID` | `uuid` | `Uuid` |
| `DATE` | `date` | `Date` |
| `DATETIME` | `datetime` | `Datetime` |
| `TIMESTAMP` | `timestamp` | `Timestamp` (not reachable through any registered `Types::*`) |
| `INTERVAL` | `interval` | `Interval` (not reachable through any registered `Types::*`) |

## Known limitations

- No `ALTER TABLE` support - `YdbSchemaManager::alterTable()` silently does nothing (see `getAlterTableSQL()`). Only `CREATE TABLE`/`DROP TABLE` work; schema changes on an existing table need raw YQL today.
- `Types::DECIMAL` writes lose precision (blocked on an open upstream SDK PR, see the table above) - reading and DDL are unaffected.
- No `FOREIGN KEY`, `SAVEPOINT`, or transaction isolation level support - YDB doesn't have them.
