<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/SqlSchematic.php';

use SqlSchematic\SqlSplitter;
use SqlSchematic\RepairPolicy;
use SqlSchematic\NumberingPolicy;
use SqlSchematic\LegacyTablePolicy;
use SqlSchematic\AdoptionStatePolicy;
use SqlSchematic\SqlStatementPolicy;

$sql = <<<'SQL'
-- comment containing ;
CREATE TABLE example (`value` VARCHAR(20));
INSERT INTO example (`value`) VALUES ('semi;colon'), ('it''s fine');
/* another ; comment */
UPDATE example SET `value` = "quoted;value";
SQL;

$statements = SqlSplitter::split($sql);
assert(count($statements) === 3);
assert(str_contains($statements[1], "'semi;colon'"));
assert(SqlSplitter::split("# only a comment\n") === []);
assert(count(SqlSplitter::split("SELECT 'it''s; valid'; SELECT `semi;column` FROM `table`;")) === 2);

$failed = false;
try {
    SqlSplitter::split("SELECT 'unterminated;");
} catch (RuntimeException) {
    $failed = true;
}
assert($failed === true);

SqlStatementPolicy::assertSafe('CREATE TABLE safe_example (id INT)');
SqlStatementPolicy::assertSafe('DO 1');
$resultStatementRejected = false;
try {
    SqlStatementPolicy::assertSafe('SELECT 1');
} catch (RuntimeException $error) {
    $resultStatementRejected = str_contains($error->getMessage(), 'Use DO 1 instead of SELECT 1');
}
assert($resultStatementRejected === true);
$friendly = SqlStatementPolicy::friendlyError(new RuntimeException('Cannot execute queries while other unbuffered queries are active'));
assert(str_contains($friendly, 'Migration statement produced a result set'));

$safe = RepairPolicy::assessRename(
    [['id' => '016_renamed']],
    [
        ['id' => '014_done', 'applied' => true, 'missing' => false],
        ['id' => '016_renamed', 'applied' => false, 'missing' => false],
    ]
);
assert($safe['repairable'] === true);

$orderingConflict = RepairPolicy::assessRename(
    [['id' => '016_normalize_status']],
    [
        ['id' => '015_add_widget_priority', 'applied' => false, 'missing' => false],
        ['id' => '016_normalize_status', 'applied' => false, 'missing' => false],
    ]
);
assert($orderingConflict['repairable'] === false);
assert(str_contains($orderingConflict['instruction'], '015_add_widget_priority'));

assert(NumberingPolicy::audit(['001_first', '002_second', '003_third'])['valid'] === true);
$duplicates = NumberingPolicy::audit(['001_first', '002_second', '002_duplicate']);
assert($duplicates['valid'] === false);
assert(str_contains(implode(' ', $duplicates['errors']), 'Duplicate migration number 2'));
$gaps = NumberingPolicy::audit(['001_first', '003_third']);
assert($gaps['valid'] === false);
assert(str_contains(implode(' ', $gaps['errors']), 'Missing migration number(s) 2'));

$legacyColumns = ['migration_id', 'filename', 'checksum', 'applied_by_user_id', 'applied_at', 'success', 'message'];
$legacy = LegacyTablePolicy::inspect($legacyColumns);
assert($legacy['readable'] === true);
assert($legacy['legacy'] === true);
$modern = LegacyTablePolicy::inspect(['migration_id', 'filename', 'checksum', 'applied_by', 'applied_at', 'statement_count']);
assert($modern['readable'] === true);
assert($modern['legacy'] === false);

assert(AdoptionStatePolicy::resolve(true, false, [])['state'] === 'legacy_upgrade_required');
$adopted = AdoptionStatePolicy::resolve(false, true, []);
assert($adopted['state'] === 'adopted');
assert(str_contains($adopted['next_action'], 'legacy upgrade is now recovery-only'));
assert(AdoptionStatePolicy::resolve(false, true, ['Applied migration files are missing.'])['state'] === 'blocked');
echo "SqlSchematic unit tests passed.\n";
