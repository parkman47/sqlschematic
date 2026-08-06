<?php
declare(strict_types=1);

use SqlSchematic\MigrationRunner;

$config = require dirname(__DIR__) . '/sqlschematic.config.php';
require_once dirname(__DIR__) . '/src/SqlSchematic.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
$user = ($config['authorize'])();
if (!is_array($user) || !isset($user['id'])) {
    http_response_code(404);
    exit('Not found.');
}
$_SESSION['sqlschematic_repair_csrf'] ??= bin2hex(random_bytes(32));
$csrf = (string) $_SESSION['sqlschematic_repair_csrf'];
$runner = new MigrationRunner(($config['pdo'])(), $config['migration_directory'], $config['options'] ?? []);
if (isset($config['configure']) && is_callable($config['configure'])) {
    ($config['configure'])($runner);
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        if (!hash_equals($csrf, (string) ($_POST['csrf'] ?? ''))) {
            throw new RuntimeException('Session token expired. Reload and try again.');
        }
        $oldId = trim((string) ($_POST['old_id'] ?? ''));
        $newId = trim((string) ($_POST['new_id'] ?? ''));
        $expected = "ADOPT {$oldId} AS {$newId}";
        if (!hash_equals($expected, trim((string) ($_POST['confirmation'] ?? '')))) {
            throw new RuntimeException('Confirmation text did not match. No repair was made.');
        }
        $result = $runner->adoptRenamedMigration($oldId, $newId, (string) $user['id']);
        $_SESSION['sqlschematic_repair_flash'] = ['ok' => $result['message']];
    } catch (Throwable $error) {
        $_SESSION['sqlschematic_repair_flash'] = ['error' => $error->getMessage()];
    }
    header('Location: ' . ($_SERVER['REQUEST_URI'] ?? '/schema-repair.php'), true, 303);
    exit;
}

$flash = $_SESSION['sqlschematic_repair_flash'] ?? [];
unset($_SESSION['sqlschematic_repair_flash']);

$audit = $runner->adoptionAudit();
$repair = $audit['compatibility']['readable']
    ? $runner->repairStatus(false)
    : ['issues' => [], 'history' => []];
$h = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Schema Repair</title><style>
:root{color-scheme:light dark;--bg:#101412;--card:#18201c;--line:#3d5046;--text:#f4f1e8;--muted:#b7c1b9;--good:#69d391;--warn:#f0bd61;--bad:#f07b72}
*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--text);font:15px/1.5 system-ui,sans-serif}main{max-width:1100px;margin:auto;padding:32px 18px}
.panel{background:var(--card);border:1px solid var(--line);border-radius:10px;padding:20px;margin:16px 0;overflow:auto}code{color:var(--warn)}.good{color:var(--good)}.bad{color:var(--bad)}
.flash{padding:12px;border:1px solid;border-radius:8px}.flash.ok{color:var(--good)}.flash.error{color:var(--bad)}label{display:block;margin:10px 0}input{width:100%;max-width:650px;padding:9px;font:inherit}
table{width:100%;border-collapse:collapse}th,td{text-align:left;padding:10px 8px;border-bottom:1px solid var(--line)}
button{padding:9px 12px;border:1px solid var(--warn);border-radius:7px;background:transparent;color:var(--warn);font:inherit;cursor:pointer}
</style></head><body><main>
<p><a href="schema-updates.php">Back to Schema Updates</a></p><h1>Schema Repair</h1>
<p>Constrained migration-history repair. Only checksum-identical, ordering-safe renames can be adopted.</p>
<?php if (isset($flash['ok'])): ?><p class="flash ok"><?= $h($flash['ok']) ?></p><?php endif ?>
<?php if (isset($flash['error'])): ?><p class="flash error"><?= $h($flash['error']) ?></p><?php endif ?>
<section class="panel"><h2>Cutover audit</h2><p class="<?= $audit['ready'] ? 'good' : 'bad' ?>"><?= $audit['ready'] ? 'Ready for cutover' : 'Not ready for cutover' ?></p>
<p>Matching applied: <?= count($audit['matching']) ?> · Pending: <?= count($audit['pending']) ?> · History table: <?= $audit['compatibility']['legacy'] ? 'legacy upgrade required' : 'compatible' ?></p>
<p><strong>Adoption state:</strong> <?= $h(str_replace('_', ' ', $audit['adoption_state'])) ?><br><strong>Next action:</strong> <?= $h($audit['next_action']) ?></p>
<?php if ($audit['problems'] !== []): ?><ul><?php foreach ($audit['problems'] as $problem): ?><li><?= $h($problem) ?></li><?php endforeach ?></ul><?php endif ?></section>
<?php if ($repair['issues'] === []): ?><section class="panel"><p class="good">No migration-history repairs are needed.</p></section><?php endif ?>
<?php foreach ($repair['issues'] as $issue): $migration = $issue['migration']; ?>
<section class="panel"><h2><?= $issue['type'] === 'checksum_drift' ? 'Applied file changed' : 'Applied file missing' ?></h2>
<p><code><?= $h($migration['id']) ?></code> — checksum <code><?= $h($migration['checksum']) ?></code></p><p><?= $h($issue['instruction']) ?></p>
<?php if ($issue['repairable']): $candidate = $issue['candidates'][0]; ?>
<p>Identical candidate: <code><?= $h($candidate['filename']) ?></code></p>
<?php $phrase = 'ADOPT ' . $migration['id'] . ' AS ' . $candidate['id']; ?><form method="post"><input type="hidden" name="csrf" value="<?= $h($csrf) ?>"><input type="hidden" name="old_id" value="<?= $h($migration['id']) ?>"><input type="hidden" name="new_id" value="<?= $h($candidate['id']) ?>"><label>Type <code><?= $h($phrase) ?></code> to confirm<input name="confirmation" autocomplete="off" required></label><button>Adopt identical rename</button></form>
<?php elseif ($issue['candidates'] !== []): ?><p>Matching candidates: <?= $h(implode(', ', array_column($issue['candidates'], 'filename'))) ?></p><?php endif ?>
</section><?php endforeach ?>
<section class="panel"><h2>Repair history</h2><table><thead><tr><th>When</th><th>Action</th><th>Migration</th><th>Actor</th></tr></thead><tbody>
<?php if ($repair['history'] === []): ?><tr><td colspan="4">No repairs recorded.</td></tr><?php endif ?>
<?php foreach ($repair['history'] as $row): ?><tr><td><?= $h($row['repaired_at']) ?></td><td><?= $h($row['action']) ?></td><td><code><?= $h($row['old_migration_id']) ?></code> → <code><?= $h($row['new_migration_id']) ?></code></td><td><?= $h($row['actor'] ?? 'unknown') ?></td></tr><?php endforeach ?>
</tbody></table></section></main></body></html>
