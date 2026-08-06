<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/SqlSchematic.php';

use SqlSchematic\NumberingPolicy;
use SqlSchematic\RepairPolicy;

$fixture = __DIR__ . '/fixtures/adoption_incident';
$history = json_decode((string) file_get_contents($fixture . '/history.json'), true, 512, JSON_THROW_ON_ERROR);
$applied = $history[0];
$appliedChecksum = hash_file('sha256', $fixture . '/' . $applied['checksum_source']);
$brokenFiles = glob($fixture . '/broken/*.sql') ?: [];
$brokenIds = array_map(static fn (string $path): string => pathinfo($path, PATHINFO_FILENAME), $brokenFiles);
$priorIds = array_map(static fn (int $number): string => sprintf('%03d_historical', $number), range(1, 14));
$renamedPath = $fixture . '/broken/016_normalize_status.sql';
assert(!in_array($applied['migration_id'], $brokenIds, true));
assert(hash_equals($appliedChecksum, hash_file('sha256', $renamedPath)));
assert(NumberingPolicy::audit([...$priorIds, ...$brokenIds])['valid'] === true);
$repair = RepairPolicy::assessRename(
    [['id' => '016_normalize_status']],
    [
        ['id' => '015_add_widget_priority', 'applied' => false, 'missing' => false],
        ['id' => '016_normalize_status', 'applied' => false, 'missing' => false],
    ]
);
assert($repair['repairable'] === false);

$restoredFiles = glob($fixture . '/restored/*.sql') ?: [];
$restoredIds = array_map(static fn (string $path): string => pathinfo($path, PATHINFO_FILENAME), $restoredFiles);
assert(in_array($applied['migration_id'], $restoredIds, true));
assert(hash_equals($appliedChecksum, hash_file('sha256', $fixture . '/restored/015_normalize_status.sql')));
assert(NumberingPolicy::audit([...$priorIds, ...$restoredIds])['valid'] === true);
assert(in_array('016_add_widget_priority', $restoredIds, true));
echo "Generic adoption incident fixture passed.\n";
