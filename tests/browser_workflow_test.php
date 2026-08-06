<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$updates = (string) file_get_contents($root . '/public/schema-updates.php');
$repair = (string) file_get_contents($root . '/public/schema-repair.php');

assert(str_contains($updates, 'method="post"'));
assert(str_contains($updates, 'sqlschematic_csrf'));
assert(str_contains($updates, '->applyAll('));
assert(str_contains($updates, '->apply('));
assert(str_contains($updates, '->upgradeLegacyHistoryTable('));
assert(str_contains($updates, 'Run all pending updates'));

assert(str_contains($repair, 'method="post"'));
assert(str_contains($repair, 'sqlschematic_repair_csrf'));
assert(str_contains($repair, '->adoptRenamedMigration('));
assert(str_contains($repair, 'ADOPT {$oldId} AS {$newId}'));

$combined = $updates . $repair;
foreach (['overwrite_checksum', 'mark_applied', 'deleteMigrationHistory'] as $forbidden) {
    assert(!str_contains($combined, $forbidden));
}
echo "Guarded browser workflow regression passed.\n";
