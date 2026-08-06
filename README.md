# SqlSchematic

SqlSchematic is a compact, auditable MySQL/MariaDB migration runner for plain PHP
applications. It separates the reusable migration engine from each host application's
login, authorization, database bootstrap, migrations, and business-specific backfills.

## What it guarantees

- Discovers numbered `.sql` files in deterministic filename order.
- Enforces strict ordering; later updates cannot jump ahead of an earlier one.
- Runs any number of pending updates as one locked batch.
- Preflights the complete batch before executing its first SQL statement.
- Records SHA-256 checksums and detects edits to applied migrations.
- Uses a MySQL advisory lock to prevent concurrent administrators or deployments.
- Records successful migrations and every success/failure attempt separately.
- Provides a constrained, audited repair tool for migration-history/file mismatches.
- Reports the failing statement number and fingerprint without exposing SQL.
- Provides a fully gated browser workflow for audit, adoption, repair, and migration.
- Leaves authentication and authorization entirely under application control.

## Requirements

- PHP 8.1 or newer
- PDO MySQL
- MySQL or MariaDB with `GET_LOCK()` support
- A database account with the DDL privileges required by your migrations

## Install with Composer

While installing directly from GitHub:

```json
{
  "repositories": [
    {"type": "vcs", "url": "https://github.com/parkman47/sqlschematic"}
  ],
  "require": {
    "parkman47/sqlschematic": "dev-main"
  }
}
```

Run `composer install` and commit the host application's `composer.lock`. Once
stable tags are published, use a constraint such as `^1.0` instead of `dev-main`.
Do not make a production web page pull executable code from GitHub.

## Configure

Copy `sqlschematic.config.example.php` outside the public web root and configure:

- a PDO factory;
- the application-owned migration directory;
- an authorization callback for the web page;
- unique bookkeeping table and lock names;
- optional application-owned hooks.

```php
return [
    'pdo' => static fn (): PDO => app_database(),
    'migration_directory' => __DIR__ . '/schema',
    'authorize' => static function (): ?array {
        $user = current_user();
        return $user && user_is_superuser($user)
            ? ['id' => (string) $user['id']]
            : null;
    },
    'options' => [
        'table' => 'app_schema_migrations',
        'runs_table' => 'app_schema_migration_runs',
        'lock_name' => 'my-app:schema',
    ],
];
```

Google authentication only proves identity. A Google-authenticated application
must additionally check an administrator role or explicit email allowlist.

## Write migrations

Use sortable, immutable filenames:

```text
schema/
  001_create_customers.sql
  002_add_customer_status.sql
  003_create_message_queue.sql
```

The first non-empty `--` comment becomes the description shown in the UI:

```sql
-- Add a review state without breaking older application code.
ALTER TABLE customers ADD COLUMN review_state VARCHAR(30) NULL;
```

Never edit a successfully applied file. Add a corrective migration instead.
Prefer one structural concern per file and retry-safe SQL where your database
version supports it.

## Run updates

Mount `public/schema-updates.php` behind the host application's administrator gate.
The browser can initialize bookkeeping, adopt legacy history, and run one or all
pending migrations. Every action uses CSRF protection, full preflight, one advisory
lock, and audit history. The batch stops at the first failure and leaves later
migrations pending. SqlSchematic has no command-line interface;
all supported operation happens through the gated browser pages.

## Multiple pending updates and failures

If `015`, `016`, and `017` are pending, they execute in that order. If statement 2
of `016` fails, the state is:

```text
015  applied
016  failed attempt; still pending
017  waiting for 016
```

MySQL DDL commonly commits implicitly, so a failed migration may have partially
changed the database. SqlSchematic does not pretend a transaction can undo that.
Inspect the run history and database, repair the failed pending migration so it is
safe to retry, and run the batch again. See [deployment and recovery](docs/DEPLOYMENT.md).

If an applied file is missing or changed, normal updates freeze. The separate
[Schema Repair](docs/REPAIR.md) tool explains the repository repair and permits only
one automated history operation: adopting a single checksum-identical rename when
doing so cannot contradict pending migration order.

## Existing application migration systems

The package can use existing bookkeeping table names, allowing existing applications
to adopt their history rather than replay it. Migration IDs, filenames, and
checksums must be audited before cutover. Application-specific hooks remain in the
host application and must be idempotent. See [integration guidance](docs/INTEGRATION.md).

## SQL limitations

The splitter supports ordinary MySQL statements, quoted semicolons, escaped and
doubled quotes, and line/block comments. `DELIMITER` and stored-routine bodies are
intentionally unsupported. Handle those with a purpose-built executor or a
separately reviewed deployment step.

Migration files must contain DDL/DML, not result-producing statements such as
`SELECT`, `SHOW`, `EXPLAIN`, or `CALL`. Use `DO 1` instead of `SELECT 1` for no-op
dynamic SQL branches. Preflight rejects direct result-producing SQL before any
migration runs and translates driver-level result errors into actionable guidance.

## Development

```sh
php -l src/SqlSchematic.php
php -l public/schema-updates.php
php -l public/schema-repair.php
php -d zend.assertions=1 -d assert.exception=1 tests/splitter_test.php
php -d zend.assertions=1 -d assert.exception=1 tests/adoption_incident_test.php
php -d zend.assertions=1 -d assert.exception=1 tests/browser_workflow_test.php
php -d zend.assertions=1 -d assert.exception=1 tests/browser_only_product_test.php
php -d zend.assertions=1 -d assert.exception=1 tests/public_scrub_test.php
composer validate --strict
```

## License

MIT
