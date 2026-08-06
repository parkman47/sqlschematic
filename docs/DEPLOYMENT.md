# Deployment and multiple pending migrations

SqlSchematic supports any number of pending migrations. `applyAll()` performs a
complete preflight first, acquires one MySQL advisory lock for the whole batch,
and applies files in filename order. Each successful migration is recorded before
the next begins. The batch stops at the first failure; it never skips ahead.

```text
015_add_customer_status       applied
016_create_message_queue      failed at statement 2
017_add_message_priority      waiting for 016
```

## Recommended release sequence

1. Back up the database and test the release against a recent copy.
2. Deploy code that tolerates both the old and new schema.
3. Open the gated Schema Updates page and review preflight.
4. Click **Run all pending updates**.
5. Deploy code that requires the new schema only after migration succeeds.

The gated browser pages are the only supported control surface. They apply preflight,
advisory locks, checksums, ordering, CSRF protection, and audit records to every action.

## Failure and recovery

MySQL and MariaDB implicitly commit many DDL statements. A transaction cannot
reliably roll back a partially applied schema migration. SqlSchematic therefore:

- records every attempt in `sqlschematic_migration_runs`;
- records successful migrations separately;
- reports the failed statement number and a safe SHA-256 fingerprint;
- leaves later migrations pending and blocked;
- never prints the full SQL statement into the browser error.

After failure, inspect the database and the run record. Make the failed migration
safe to retry only if it has never been recorded as successfully applied. Prefer
small, retry-safe migrations such as `CREATE TABLE IF NOT EXISTS`. Never edit a
successfully applied migration; create a new corrective migration instead.

## Application schema gate

An application can prevent new code from running against an old database:

```php
$runner->requireCurrent();
```

Keep the authenticated schema updater route outside that gate so an administrator
can still apply pending updates. For zero-downtime deployment, prefer temporary
backward-compatible application code rather than gating the entire site.

## Versioning the engine

Install SqlSchematic from its Git repository through Composer and commit the
application's `composer.lock`. Do not run `git pull` from the browser updater.
Executable package updates belong in the deployment process; database migrations
belong in the runner.
