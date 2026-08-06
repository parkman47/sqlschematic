<?php
declare(strict_types=1);

return [
    'pdo' => static function (): PDO {
        // Prefer requiring your application's bootstrap and returning its existing PDO.
        return new PDO(
            (string) getenv('APP_DB_DSN'),
            (string) getenv('APP_DB_USER'),
            (string) getenv('APP_DB_PASS'),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
    },
    'migration_directory' => __DIR__ . '/migrations',
    'authorize' => static function (): ?array {
        // This is the only application-specific gate. Return a stable actor ID or null.
        // Site login: return current_user_is_superuser() ? ['id' => current_user_id()] : null;
        // Google auth: return google_admin_user() ? ['id' => google_admin_user()['email']] : null;
        return null;
    },
    'options' => [
        'table' => 'sqlschematic_migrations',
        'runs_table' => 'sqlschematic_migration_runs',
        'repairs_table' => 'sqlschematic_migration_repairs',
        'lock_name' => 'my-application:schema',
        // Enable for 001, 002, 003-style projects. Leave false for date-based IDs.
        'strict_numbering' => false,
    ],
    'configure' => static function (SqlSchematic\MigrationRunner $runner): void {
        // Register application-owned, retry-safe hooks here when SQL alone is insufficient.
        // $runner->after('014_backfill_names', static fn (PDO $pdo) => backfill_names($pdo));
    },
];
