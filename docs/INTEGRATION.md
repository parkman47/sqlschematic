# Application integration

The shared package owns ordering, locking, checksums, execution, repair constraints,
and audit history. Each host application owns its migrations, PDO connection,
administrator policy, visual integration, and application-specific backfills.

Migration SQL must be DDL/DML only. Do not use result-returning statements such as
`SELECT 1` as a dynamic SQL no-op; use `DO 1`. SqlSchematic rejects direct
result-producing statements during preflight and explains driver-level result errors.

## Composer

Until a package registry is configured, add the Git repository as a VCS source:

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

After tagged releases exist, replace `dev-main` with a stable constraint such as
`^1.0` and commit `composer.lock`.

## Existing-history adapter

Use the application's existing PDO and bookkeeping table name. Existing records are
audited and adopted rather than replayed.

```php
$runner = new SqlSchematic\MigrationRunner(app_database(), dirname(__DIR__) . '/schema', [
    'table' => 'app_schema_migrations',
    'runs_table' => 'app_schema_migration_runs',
    'repairs_table' => 'app_schema_migration_repairs',
    'lock_name' => 'my-application:schema',
    'strict_numbering' => true,
]);
```

Before conversion, compare every legacy migration ID, filename, and checksum with
the browser adoption audit. The gated **Upgrade legacy history** action adds supported
metadata columns without replaying SQL or inventing run records.

## Application hooks

Keep deterministic seed/backfill work in the host application through idempotent hooks:

```php
$runner->after('014_seed_reference_data', static function (PDO $pdo): void {
    seed_reference_catalog($pdo);
    backfill_existing_records($pdo);
});
```

Hooks execute after that migration's SQL and before its successful record is written.
Because MySQL DDL may already be committed when a hook fails, hooks must be safe to retry.

## Web authorization

`public/schema-updates.php` expects `authorize` to return a stable actor ID or `null`.
Returning `null` produces a generic 404 so the administrative route does not reveal
itself to unauthorized users. Keep the application's session protections and HTTPS
enabled. Authentication proves identity; separately enforce an administrator role or
explicit allowlist.

The browser pages provide the full guarded workflow: initialize tables, upgrade
supported legacy metadata, run pending migrations, and adopt only an exact,
ordering-safe rename. Keep them behind the host application's strongest admin gate.
