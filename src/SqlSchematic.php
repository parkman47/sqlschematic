<?php
declare(strict_types=1);

namespace SqlSchematic;

use PDO;
use RuntimeException;
use Throwable;

final class MigrationRunner
{
    private PDO $pdo;
    private string $directory;
    private string $table;
    private string $runsTable;
    private string $repairsTable;
    private string $lockName;
    private bool $strictNumbering;
    /** @var array<string, callable(PDO, array<string, mixed>): void> */
    private array $afterHooks = [];

    public function __construct(PDO $pdo, string $directory, array $options = [])
    {
        $realDirectory = realpath($directory);
        if ($realDirectory === false || !is_dir($realDirectory)) {
            throw new RuntimeException('Migration directory does not exist: ' . $directory);
        }

        $this->pdo = $pdo;
        $this->directory = $realDirectory;
        $this->table = $this->identifier((string) ($options['table'] ?? 'sqlschematic_migrations'));
        $this->runsTable = $this->identifier((string) ($options['runs_table'] ?? 'sqlschematic_migration_runs'));
        $this->repairsTable = $this->identifier((string) ($options['repairs_table'] ?? 'sqlschematic_migration_repairs'));
        $this->lockName = (string) ($options['lock_name'] ?? 'sqlschematic:' . hash('sha256', $realDirectory));
        $this->strictNumbering = (bool) ($options['strict_numbering'] ?? false);
    }

    public function install(): void
    {
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS `{$this->table}` (
            migration_id VARCHAR(190) NOT NULL,
            filename VARCHAR(255) NOT NULL,
            checksum CHAR(64) NOT NULL,
            applied_by VARCHAR(190) NULL,
            applied_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
            statement_count INT UNSIGNED NOT NULL,
            PRIMARY KEY (migration_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $this->pdo->exec("CREATE TABLE IF NOT EXISTS `{$this->runsTable}` (
            run_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            migration_id VARCHAR(190) NOT NULL,
            checksum CHAR(64) NOT NULL,
            actor VARCHAR(190) NULL,
            started_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
            finished_at DATETIME(6) NULL,
            success TINYINT(1) NOT NULL DEFAULT 0,
            statement_count INT UNSIGNED NOT NULL DEFAULT 0,
            message TEXT NULL,
            PRIMARY KEY (run_id),
            KEY migration_runs_migration_id (migration_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $this->pdo->exec("CREATE TABLE IF NOT EXISTS `{$this->repairsTable}` (
            repair_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            action VARCHAR(64) NOT NULL,
            old_migration_id VARCHAR(190) NULL,
            new_migration_id VARCHAR(190) NULL,
            checksum CHAR(64) NULL,
            actor VARCHAR(190) NULL,
            repaired_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
            details TEXT NULL,
            PRIMARY KEY (repair_id),
            KEY migration_repairs_old_id (old_migration_id),
            KEY migration_repairs_new_id (new_migration_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    /** @return list<array<string, mixed>> */
    public function status(bool $initialize = true): array
    {
        if ($initialize) {
            $this->install();
        }
        $compatibility = $this->historyCompatibility();
        if (!$compatibility['readable']) {
            throw new RuntimeException('Migration history table is incompatible: ' . implode(' ', $compatibility['problems']));
        }
        $applied = [];
        $rows = $this->pdo->query("SELECT * FROM `{$this->table}` ORDER BY applied_at")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            $row['applied_by'] = $row['applied_by'] ?? $row['applied_by_user_id'] ?? null;
            $row['statement_count'] = $row['statement_count'] ?? null;
            $applied[(string) $row['migration_id']] = $row;
        }

        $result = [];
        $firstPending = null;
        foreach ($this->discover() as $migration) {
            $row = $applied[$migration['id']] ?? null;
            $isApplied = is_array($row);
            if (!$isApplied && $firstPending === null) {
                $firstPending = $migration['id'];
            }
            $result[] = $migration + [
                'applied' => $isApplied,
                'checksum_changed' => $isApplied && $row['checksum'] !== $migration['checksum'],
                'blocked_by' => !$isApplied && $firstPending !== $migration['id'] ? $firstPending : null,
                'applied_at' => $isApplied ? $row['applied_at'] : null,
                'applied_by' => $isApplied ? $row['applied_by'] : null,
                'statement_count' => $isApplied && $row['statement_count'] !== null ? (int) $row['statement_count'] : null,
            ];
        }

        foreach ($applied as $id => $row) {
            if (!array_filter($result, static fn (array $item): bool => $item['id'] === $id)) {
                $result[] = [
                    'id' => $id,
                    'name' => $id,
                    'description' => 'The applied migration file is no longer present.',
                    'filename' => $row['filename'],
                    'checksum' => $row['checksum'],
                    'applied' => true,
                    'checksum_changed' => false,
                    'missing' => true,
                    'blocked_by' => null,
                    'applied_at' => $row['applied_at'],
                    'applied_by' => $row['applied_by'],
                    'statement_count' => $row['statement_count'] !== null ? (int) $row['statement_count'] : null,
                ];
            }
        }

        return $result;
    }

    /** @return array{ok:bool,pending:list<string>,errors:list<string>} */
    public function preflight(bool $initialize = true): array
    {
        $items = $this->status($initialize);
        $pending = [];
        $errors = [];
        $compatibility = $this->historyCompatibility();
        if ($compatibility['legacy']) {
            $errors[] = 'Migration history table needs the no-replay legacy upgrade before migrations can run.';
        }
        foreach ($items as $item) {
            if ($item['missing']) {
                $errors[] = 'Applied migration file is missing: ' . $item['id'];
            } elseif ($item['checksum_changed']) {
                $errors[] = 'Applied migration was edited: ' . $item['id'];
            } elseif (!$item['applied']) {
                $pending[] = $item['id'];
                try {
                    $sql = (string) file_get_contents($item['path']);
                    $statements = SqlSplitter::split($sql);
                    if ($statements === []) {
                        $errors[] = 'Migration contains no SQL: ' . $item['id'];
                    }
                    foreach ($statements as $index => $statement) {
                        try {
                            SqlStatementPolicy::assertSafe($statement);
                        } catch (Throwable $error) {
                            $errors[] = $item['id'] . ' statement ' . ($index + 1) . ': ' . $error->getMessage();
                        }
                    }
                } catch (Throwable $error) {
                    $errors[] = $item['id'] . ': ' . $error->getMessage();
                }
            }
        }
        if ($this->strictNumbering) {
            $numbering = NumberingPolicy::audit(array_column($this->discover(), 'id'));
            array_push($errors, ...$numbering['errors']);
        }
        return ['ok' => $errors === [], 'pending' => $pending, 'errors' => $errors];
    }

    /** @return array{readable:bool,writable:bool,legacy:bool,columns:list<string>,problems:list<string>} */
    public function historyCompatibility(): array
    {
        $rows = $this->pdo->query("SHOW COLUMNS FROM `{$this->table}`")->fetchAll(PDO::FETCH_ASSOC);
        $columns = array_map(static fn (array $row): string => (string) $row['Field'], $rows);
        return LegacyTablePolicy::inspect($columns);
    }

    /** @return array{changed:bool,message:string} */
    public function upgradeLegacyHistoryTable(?string $actor = null): array
    {
        $this->install();
        if (!$this->acquireLock()) {
            throw new RuntimeException('Another schema update or repair is already running.');
        }
        try {
            $before = $this->historyCompatibility();
            if (!$before['readable']) {
                throw new RuntimeException('Legacy table cannot be upgraded automatically: ' . implode(' ', $before['problems']));
            }
            $changes = [];
            if (!in_array('applied_by', $before['columns'], true)) {
                $this->pdo->exec("ALTER TABLE `{$this->table}` ADD COLUMN applied_by VARCHAR(190) NULL AFTER checksum");
                $changes[] = 'added applied_by';
                if (in_array('applied_by_user_id', $before['columns'], true)) {
                    $this->pdo->exec("UPDATE `{$this->table}` SET applied_by = CAST(applied_by_user_id AS CHAR) WHERE applied_by_user_id IS NOT NULL");
                    $changes[] = 'copied legacy actor IDs';
                }
            }
            if (!in_array('statement_count', $before['columns'], true)) {
                $this->pdo->exec("ALTER TABLE `{$this->table}` ADD COLUMN statement_count INT UNSIGNED NULL AFTER applied_at");
                $changes[] = 'added nullable statement_count';
            }
            if ($changes === []) {
                return ['changed' => false, 'message' => 'Migration history table is already SqlSchematic-compatible.'];
            }
            $audit = $this->pdo->prepare("INSERT INTO `{$this->repairsTable}`
                (action, actor, details) VALUES ('upgrade_legacy_history_table', ?, ?)");
            $audit->execute([$actor, implode('; ', $changes) . '. Existing migrations were not replayed and no run records were fabricated.']);
            return ['changed' => true, 'message' => 'Legacy table upgraded: ' . implode(', ', $changes) . '.'];
        } finally {
            $this->releaseLock();
        }
    }

    /** @return array<string,mixed> */
    public function adoptionAudit(): array
    {
        $numbering = $this->strictNumbering
            ? NumberingPolicy::audit(array_column($this->discover(), 'id'))
            : ['valid' => true, 'prefixes' => [], 'errors' => [], 'enforced' => false];
        try {
            $compatibility = $this->historyCompatibility();
        } catch (Throwable $error) {
            return [
                'ready' => false,
                'adoption_state' => 'unavailable',
                'next_action' => 'Restore access to the migration history table, then run adoption-audit again.',
                'compatibility' => ['readable' => false, 'writable' => false, 'legacy' => false, 'columns' => [], 'problems' => [$error->getMessage()]],
                'numbering' => $numbering,
                'matching' => [],
                'changed' => [],
                'missing' => [],
                'pending' => array_column($this->discover(), 'id'),
                'problems' => ['Migration history table could not be inspected: ' . $error->getMessage()],
            ];
        }
        $items = $compatibility['readable'] ? $this->status(false) : [];
        $matching = $changed = $missing = $pending = [];
        foreach ($items as $item) {
            if ($item['missing']) {
                $missing[] = $item['id'];
            } elseif ($item['checksum_changed']) {
                $changed[] = $item['id'];
            } elseif ($item['applied']) {
                $matching[] = $item['id'];
            } else {
                $pending[] = $item['id'];
            }
        }
        $problems = $compatibility['problems'];
        if ($compatibility['legacy']) {
            $problems[] = 'History table needs the no-replay legacy upgrade before new migrations can be written.';
        }
        array_push($problems, ...$numbering['errors']);
        if ($missing !== []) {
            $problems[] = 'Applied migration files are missing: ' . implode(', ', $missing) . '.';
        }
        if ($changed !== []) {
            $problems[] = 'Applied migration checksums changed: ' . implode(', ', $changed) . '.';
        }
        $state = AdoptionStatePolicy::resolve($compatibility['legacy'], $this->legacyUpgradeRecorded(), $problems);
        return [
            'ready' => $problems === [],
            'adoption_state' => $state['state'],
            'next_action' => $state['next_action'],
            'compatibility' => $compatibility,
            'numbering' => $numbering,
            'matching' => $matching,
            'changed' => $changed,
            'missing' => $missing,
            'pending' => $pending,
            'problems' => $problems,
        ];
    }

    public function isCurrent(): bool
    {
        $check = $this->preflight();
        return $check['ok'] && $check['pending'] === [];
    }

    /** @return list<array<string,mixed>> */
    public function recentRuns(int $limit = 50, bool $initialize = true): array
    {
        if ($initialize) {
            $this->install();
        }
        $limit = max(1, min(200, $limit));
        try {
            return $this->pdo->query("SELECT run_id, migration_id, checksum, actor, started_at,
                finished_at, success, statement_count, message FROM `{$this->runsTable}`
                ORDER BY run_id DESC LIMIT {$limit}")->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $error) {
            if (!$initialize) {
                return [];
            }
            throw $error;
        }
    }

    /** @return array{issues:list<array<string,mixed>>,history:list<array<string,mixed>>} */
    public function repairStatus(bool $initialize = true): array
    {
        if ($initialize) {
            $this->install();
        }
        $items = $this->status(false);
        $pendingByChecksum = [];
        foreach ($items as $item) {
            if (!$item['applied'] && !$item['missing']) {
                $pendingByChecksum[$item['checksum']][] = $item;
            }
        }

        $issues = [];
        foreach ($items as $item) {
            if ($item['missing']) {
                $candidates = array_map(
                    static fn (array $candidate): array => [
                        'id' => $candidate['id'],
                        'filename' => $candidate['filename'],
                        'checksum' => $candidate['checksum'],
                    ],
                    $pendingByChecksum[$item['checksum']] ?? []
                );
                $assessment = RepairPolicy::assessRename($candidates, $items);
                $issues[] = [
                    'type' => 'missing_applied_file',
                    'migration' => $item,
                    'candidates' => $candidates,
                    'repairable' => $assessment['repairable'],
                    'instruction' => $assessment['instruction'],
                ];
            } elseif ($item['checksum_changed']) {
                $issues[] = [
                    'type' => 'checksum_drift',
                    'migration' => $item,
                    'candidates' => [],
                    'repairable' => false,
                    'instruction' => 'Restore the applied file. Do not accept changed SQL; add a corrective migration.',
                ];
            }
        }

        try {
            $history = $this->pdo->query("SELECT repair_id, action, old_migration_id, new_migration_id,
                checksum, actor, repaired_at, details FROM `{$this->repairsTable}` ORDER BY repair_id DESC LIMIT 100")
                ->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $error) {
            if ($initialize) {
                throw $error;
            }
            $history = [];
        }
        return ['issues' => $issues, 'history' => $history];
    }

    /** @return array{status:string,message:string} */
    public function adoptRenamedMigration(string $oldId, string $newId, ?string $actor = null): array
    {
        $this->install();
        if (!$this->acquireLock()) {
            throw new RuntimeException('Another schema update or repair is already running.');
        }
        try {
            $items = [];
            foreach ($this->status() as $item) {
                $items[$item['id']] = $item;
            }
            $old = $items[$oldId] ?? null;
            $new = $items[$newId] ?? null;
            if (!is_array($old) || !$old['applied'] || !$old['missing']) {
                throw new RuntimeException('The old migration must be applied and its file must be missing.');
            }
            if (!is_array($new) || $new['applied'] || $new['missing']) {
                throw new RuntimeException('The replacement must be a present, pending migration file.');
            }
            if (!hash_equals((string) $old['checksum'], (string) $new['checksum'])) {
                throw new RuntimeException('Repair refused: replacement SQL checksum is not identical.');
            }
            $matchingCandidates = [];
            foreach ($items as $item) {
                if (!$item['applied'] && !$item['missing'] && hash_equals((string) $old['checksum'], (string) $item['checksum'])) {
                    $matchingCandidates[] = $item['id'];
                }
            }
            if ($matchingCandidates !== [$newId]) {
                throw new RuntimeException('Repair refused: the renamed migration is not the single unambiguous checksum match.');
            }
            foreach ($this->discover() as $definition) {
                if ($definition['id'] === $newId) {
                    break;
                }
                $earlier = $items[$definition['id']] ?? null;
                if (is_array($earlier) && !$earlier['applied']) {
                    throw new RuntimeException(
                        'Repair refused: pending migration ' . $earlier['id'] . ' sorts before the proposed adopted ID. Renumber files in the repository instead.'
                    );
                }
            }

            $this->pdo->beginTransaction();
            try {
                $update = $this->pdo->prepare("UPDATE `{$this->table}` SET migration_id = ?, filename = ?
                    WHERE migration_id = ? AND checksum = ?");
                $update->execute([$newId, $new['filename'], $oldId, $old['checksum']]);
                if ($update->rowCount() !== 1) {
                    throw new RuntimeException('Migration history changed during repair; nothing was adopted.');
                }
                $audit = $this->pdo->prepare("INSERT INTO `{$this->repairsTable}`
                    (action, old_migration_id, new_migration_id, checksum, actor, details)
                    VALUES ('adopt_identical_rename', ?, ?, ?, ?, ?)");
                $audit->execute([
                    $oldId,
                    $newId,
                    $old['checksum'],
                    $actor,
                    'Adopted a renamed migration only after exact SHA-256 checksum verification.',
                ]);
                $this->pdo->commit();
            } catch (Throwable $error) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
                throw $error;
            }
            return ['status' => 'repaired', 'message' => "Adopted {$oldId} as {$newId}; SQL was not executed."];
        } finally {
            $this->releaseLock();
        }
    }

    public function requireCurrent(): void
    {
        $check = $this->preflight();
        if (!$check['ok']) {
            throw new RuntimeException('Schema preflight failed: ' . implode(' ', $check['errors']));
        }
        if ($check['pending'] !== []) {
            throw new RuntimeException('Schema updates are pending: ' . implode(', ', $check['pending']));
        }
    }

    /** @param callable(PDO, array<string, mixed>): void $hook */
    public function after(string $migrationId, callable $hook): self
    {
        $this->afterHooks[$migrationId] = $hook;
        return $this;
    }

    /** @return array{status:string,message:string,statement_count:int} */
    public function apply(string $migrationId, ?string $actor = null): array
    {
        $this->install();
        if (!$this->acquireLock()) {
            throw new RuntimeException('Another schema update is already running.');
        }

        try {
            $check = $this->preflight();
            if (!$check['ok']) {
                throw new RuntimeException('Integrity preflight failed before any SQL ran: ' . implode(' ', $check['errors']));
            }
            return $this->applyUnlocked($migrationId, $actor);
        } finally {
            $this->releaseLock();
        }
    }

    /** @return array{status:string,message:string,applied:list<array<string,mixed>>,failed:?string} */
    public function applyAll(?string $actor = null): array
    {
        $this->install();
        if (!$this->acquireLock()) {
            throw new RuntimeException('Another schema update is already running.');
        }
        try {
            $check = $this->preflight();
            if (!$check['ok']) {
                throw new RuntimeException('Preflight failed before any SQL ran: ' . implode(' ', $check['errors']));
            }
            if ($check['pending'] === []) {
                return ['status' => 'current', 'message' => 'Schema is already current.', 'applied' => [], 'failed' => null];
            }

            $applied = [];
            foreach ($check['pending'] as $migrationId) {
                try {
                    $applied[] = ['id' => $migrationId] + $this->applyUnlocked($migrationId, $actor);
                } catch (Throwable $error) {
                    $message = count($applied) . ' migration(s) applied; stopped at ' . $migrationId . ': ' . $error->getMessage();
                    throw new RuntimeException($message, 0, $error);
                }
            }
            return [
                'status' => 'applied',
                'message' => 'Applied ' . count($applied) . ' migration(s): ' . implode(', ', array_column($applied, 'id')),
                'applied' => $applied,
                'failed' => null,
            ];
        } finally {
            $this->releaseLock();
        }
    }

    /** @return list<array{id:string,name:string,description:string,filename:string,path:string,checksum:string,missing:bool}> */
    public function discover(): array
    {
        $files = glob($this->directory . DIRECTORY_SEPARATOR . '*.sql') ?: [];
        sort($files, SORT_NATURAL | SORT_FLAG_CASE);
        $result = [];
        foreach ($files as $file) {
            $id = pathinfo($file, PATHINFO_FILENAME);
            if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $id)) {
                throw new RuntimeException('Unsafe migration filename: ' . basename($file));
            }
            $sql = (string) file_get_contents($file);
            $description = basename($file);
            foreach (preg_split('/\R/', $sql) ?: [] as $line) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }
                if (str_starts_with($line, '--')) {
                    $description = trim(substr($line, 2)) ?: $description;
                }
                break;
            }
            $label = preg_replace('/^\d+[._-]*/', '', $id) ?: $id;
            $result[] = [
                'id' => $id,
                'name' => ucwords(str_replace(['_', '-'], ' ', $label)),
                'description' => $description,
                'filename' => basename($file),
                'path' => $file,
                'checksum' => hash('sha256', $sql),
                'missing' => false,
            ];
        }
        return $result;
    }

    private function finishRun(int $runId, bool $success, int $count, string $message): void
    {
        $query = $this->pdo->prepare("UPDATE `{$this->runsTable}`
            SET finished_at = CURRENT_TIMESTAMP(6), success = ?, statement_count = ?, message = ? WHERE run_id = ?");
        $query->execute([$success ? 1 : 0, $count, $message, $runId]);
    }

    /** @return array{status:string,message:string,statement_count:int} */
    private function applyUnlocked(string $migrationId, ?string $actor): array
    {
        $compatibility = $this->historyCompatibility();
        if (!$compatibility['writable'] || $compatibility['legacy']) {
            throw new RuntimeException('Migration history table is not writable by SqlSchematic. Run the legacy-table adoption upgrade first.');
        }
        $definitions = [];
        foreach ($this->discover() as $migration) {
            $definitions[$migration['id']] = $migration;
        }
        if (!isset($definitions[$migrationId])) {
            throw new RuntimeException('Unknown migration: ' . $migrationId);
        }
        $status = [];
        foreach ($this->status() as $item) {
            $status[$item['id']] = $item;
        }
        if ($status[$migrationId]['applied']) {
            if ($status[$migrationId]['checksum_changed']) {
                throw new RuntimeException('Applied migration has changed. Restore it and create a new migration.');
            }
            return ['status' => 'skipped', 'message' => 'Migration was already applied.', 'statement_count' => 0];
        }
        if ($status[$migrationId]['blocked_by'] !== null) {
            throw new RuntimeException('Apply earlier migration first: ' . $status[$migrationId]['blocked_by']);
        }

        $migration = $definitions[$migrationId];
        $statements = SqlSplitter::split((string) file_get_contents($migration['path']));
        $run = $this->pdo->prepare("INSERT INTO `{$this->runsTable}` (migration_id, checksum, actor) VALUES (?, ?, ?)");
        $run->execute([$migrationId, $migration['checksum'], $actor]);
        $runId = (int) $this->pdo->lastInsertId();
        $executed = 0;
        try {
            foreach ($statements as $index => $statement) {
                try {
                    SqlStatementPolicy::assertSafe($statement);
                    $result = $this->pdo->query($statement);
                    if ($result === false) {
                        throw new RuntimeException('Database driver did not return a statement result.');
                    }
                    $resultColumns = $result->columnCount();
                    $result->closeCursor();
                    if ($resultColumns > 0) {
                        throw new RuntimeException(
                            'Migration statement produced a result set. Migrations must be DDL/DML only; '
                            . 'use DO 1 instead of SELECT 1 for no-op branches.'
                        );
                    }
                    $executed++;
                } catch (Throwable $error) {
                    $fingerprint = substr(hash('sha256', $statement), 0, 12);
                    throw new RuntimeException(
                        'Statement ' . ($index + 1) . ' failed (fingerprint ' . $fingerprint . '): ' . SqlStatementPolicy::friendlyError($error),
                        0,
                        $error
                    );
                }
            }
            if (isset($this->afterHooks[$migrationId])) {
                ($this->afterHooks[$migrationId])($this->pdo, $migration);
            }
            $insert = $this->pdo->prepare("INSERT INTO `{$this->table}`
                (migration_id, filename, checksum, applied_by, statement_count) VALUES (?, ?, ?, ?, ?)");
            $insert->execute([$migrationId, $migration['filename'], $migration['checksum'], $actor, $executed]);
            $message = "Applied {$executed} SQL statement" . ($executed === 1 ? '.' : 's.');
            $this->finishRun($runId, true, $executed, $message);
            return ['status' => 'applied', 'message' => $message, 'statement_count' => $executed];
        } catch (Throwable $error) {
            $this->finishRun($runId, false, $executed, $error->getMessage());
            throw $error;
        }
    }

    private function acquireLock(): bool
    {
        $query = $this->pdo->prepare('SELECT GET_LOCK(?, 0)');
        $query->execute([$this->lockName]);
        return (int) $query->fetchColumn() === 1;
    }

    private function releaseLock(): void
    {
        $query = $this->pdo->prepare('SELECT RELEASE_LOCK(?)');
        $query->execute([$this->lockName]);
    }

    private function legacyUpgradeRecorded(): bool
    {
        try {
            $exists = $this->pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?');
            $exists->execute([$this->repairsTable]);
            if ((int) $exists->fetchColumn() !== 1) {
                return false;
            }
            $query = $this->pdo->query("SELECT COUNT(*) FROM `{$this->repairsTable}` WHERE action = 'upgrade_legacy_history_table'");
            return (int) $query->fetchColumn() > 0;
        } catch (Throwable) {
            return false;
        }
    }

    private function identifier(string $value): string
    {
        if (!preg_match('/^[A-Za-z][A-Za-z0-9_]{0,63}$/', $value)) {
            throw new RuntimeException('Unsafe SQL identifier: ' . $value);
        }
        return $value;
    }
}

final class SqlSplitter
{
    /** @return list<string> */
    public static function split(string $sql): array
    {
        $statements = [];
        $buffer = '';
        $quote = null;
        $lineComment = false;
        $blockComment = false;
        $length = strlen($sql);

        for ($i = 0; $i < $length; $i++) {
            $char = $sql[$i];
            $next = $i + 1 < $length ? $sql[$i + 1] : '';
            if ($lineComment) {
                if ($char === "\n") {
                    $lineComment = false;
                    $buffer .= $char;
                }
                continue;
            }
            if ($blockComment) {
                if ($char === '*' && $next === '/') {
                    $blockComment = false;
                    $i++;
                }
                continue;
            }
            if ($quote === null && (($char === '-' && $next === '-' && ($i + 2 >= $length || ctype_space($sql[$i + 2]))) || $char === '#')) {
                $lineComment = true;
                if ($char === '-') {
                    $i++;
                }
                continue;
            }
            if ($quote === null && $char === '/' && $next === '*') {
                $blockComment = true;
                $i++;
                continue;
            }
            if ($quote === null && in_array($char, ["'", '"', '`'], true)) {
                $quote = $char;
                $buffer .= $char;
                continue;
            }
            if ($quote !== null) {
                $buffer .= $char;
                if ($char === '\\' && $quote !== '`' && $i + 1 < $length) {
                    $buffer .= $sql[++$i];
                } elseif ($char === $quote) {
                    if ($next === $quote && $quote !== '`') {
                        $buffer .= $sql[++$i];
                    } else {
                        $quote = null;
                    }
                }
                continue;
            }
            if ($char === ';') {
                if (trim($buffer) !== '') {
                    $statements[] = trim($buffer);
                }
                $buffer = '';
            } else {
                $buffer .= $char;
            }
        }
        if ($quote !== null || $blockComment) {
            throw new RuntimeException('Unterminated quote or comment in migration SQL.');
        }
        if (trim($buffer) !== '') {
            $statements[] = trim($buffer);
        }
        return $statements;
    }
}

final class RepairPolicy
{
    /**
     * @param list<array{id:string}> $candidates
     * @param list<array<string,mixed>> $orderedItems
     * @return array{repairable:bool,instruction:string}
     */
    public static function assessRename(array $candidates, array $orderedItems): array
    {
        if ($candidates === []) {
            return ['repairable' => false, 'instruction' => 'Restore the original applied file with this exact checksum.'];
        }
        if (count($candidates) !== 1) {
            return ['repairable' => false, 'instruction' => 'Multiple identical candidates exist; remove ambiguity before repairing.'];
        }
        foreach ($orderedItems as $item) {
            if ($item['id'] === $candidates[0]['id']) {
                break;
            }
            if (!$item['applied'] && !$item['missing']) {
                return [
                    'repairable' => false,
                    'instruction' => 'Do not adopt this rename: pending migration ' . $item['id'] . ' sorts before it. Repair and renumber the repository files instead.',
                ];
            }
        }
        return ['repairable' => true, 'instruction' => 'A checksum-identical renamed file can be adopted after confirmation.'];
    }
}

final class NumberingPolicy
{
    /** @param list<string> $migrationIds @return array{valid:bool,prefixes:array<string,int>,errors:list<string>} */
    public static function audit(array $migrationIds): array
    {
        $prefixes = [];
        $byNumber = [];
        $errors = [];
        foreach ($migrationIds as $id) {
            if (!preg_match('/^(\d+)(?:[._-]|$)/', $id, $matches)) {
                $errors[] = 'Migration ID lacks a numeric prefix: ' . $id . '.';
                continue;
            }
            $number = (int) $matches[1];
            $prefixes[$id] = $number;
            $byNumber[$number][] = $id;
        }
        ksort($byNumber, SORT_NUMERIC);
        foreach ($byNumber as $number => $ids) {
            if (count($ids) > 1) {
                $errors[] = 'Duplicate migration number ' . $number . ': ' . implode(', ', $ids) . '.';
            }
        }
        if ($byNumber !== []) {
            $numbers = array_map('intval', array_keys($byNumber));
            $highest = max($numbers);
            $expected = 1;
            foreach ($numbers as $number) {
                if ($number > $expected) {
                    $gap = $number === $expected + 1 ? (string) $expected : $expected . '-' . ($number - 1);
                    $errors[] = 'Missing migration number(s) ' . $gap . ' before latest number ' . $highest . '.';
                }
                $expected = $number + 1;
            }
        }
        return ['valid' => $errors === [], 'prefixes' => $prefixes, 'errors' => $errors];
    }
}

final class LegacyTablePolicy
{
    /** @param list<string> $columns @return array{readable:bool,writable:bool,legacy:bool,columns:list<string>,problems:list<string>} */
    public static function inspect(array $columns): array
    {
        $problems = [];
        foreach (['migration_id', 'filename', 'checksum', 'applied_at'] as $required) {
            if (!in_array($required, $columns, true)) {
                $problems[] = 'Missing required column ' . $required . '.';
            }
        }
        $hasActor = in_array('applied_by', $columns, true) || in_array('applied_by_user_id', $columns, true);
        $readable = $problems === [];
        return [
            'readable' => $readable,
            'writable' => $readable && $hasActor,
            'legacy' => !in_array('applied_by', $columns, true) || !in_array('statement_count', $columns, true),
            'columns' => $columns,
            'problems' => $problems,
        ];
    }
}

final class AdoptionStatePolicy
{
    /** @param list<string> $problems @return array{state:string,next_action:string} */
    public static function resolve(bool $legacy, bool $upgradeRecorded, array $problems): array
    {
        $nonLegacyProblems = array_values(array_filter(
            $problems,
            static fn (string $problem): bool => !str_contains($problem, 'legacy upgrade')
        ));
        if ($nonLegacyProblems !== []) {
            return ['state' => 'blocked', 'next_action' => 'Repair the reported file, checksum, numbering, or table-shape blockers; then run adoption-audit again.'];
        }
        if ($legacy) {
            return ['state' => 'legacy_upgrade_required', 'next_action' => 'Use the gated Upgrade legacy history action once, then review the adoption audit again.'];
        }
        if ($upgradeRecorded) {
            return ['state' => 'adopted', 'next_action' => 'Legacy adoption is complete. Review the adoption audit before future schema operations; legacy upgrade is now recovery-only.'];
        }
        return ['state' => 'native_or_compatible', 'next_action' => 'History is compatible. Review preflight, then use the gated update actions.'];
    }
}

final class SqlStatementPolicy
{
    /** @var list<string> */
    private const RESULT_KEYWORDS = ['SELECT', 'SHOW', 'DESCRIBE', 'DESC', 'EXPLAIN', 'CALL', 'HELP', 'TABLE', 'VALUES', 'WITH'];

    public static function assertSafe(string $statement): void
    {
        if (!preg_match('/^\s*([A-Za-z]+)/', $statement, $matches)) {
            throw new RuntimeException('Could not identify the SQL statement type.');
        }
        $keyword = strtoupper($matches[1]);
        if (in_array($keyword, self::RESULT_KEYWORDS, true)) {
            throw new RuntimeException(
                'Migration statement produces or may produce a result set (' . $keyword . '). '
                . 'Migrations must be DDL/DML only. Use DO 1 instead of SELECT 1 for no-op branches.'
            );
        }
    }

    public static function friendlyError(Throwable $error): string
    {
        $message = $error->getMessage();
        if (stripos($message, 'unbuffered queries are active') !== false
            || stripos($message, 'pending result sets') !== false) {
            return 'Migration statement produced a result set. Migrations must be DDL/DML only; use DO 1 instead of SELECT 1 for no-op branches.';
        }
        return $message;
    }
}
