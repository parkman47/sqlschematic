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
$_SESSION['sqlschematic_csrf'] ??= bin2hex(random_bytes(32));
$csrf = (string) $_SESSION['sqlschematic_csrf'];

$runner = new MigrationRunner(($config['pdo'])(), $config['migration_directory'], $config['options'] ?? []);
if (isset($config['configure']) && is_callable($config['configure'])) {
    ($config['configure'])($runner);
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        if (!hash_equals($csrf, (string) ($_POST['csrf'] ?? ''))) {
            throw new RuntimeException('Session token expired. Reload and try again.');
        }
        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'initialize') {
            $runner->install();
            $message = 'SqlSchematic bookkeeping tables initialized.';
        } elseif ($action === 'upgrade_legacy') {
            $auditBefore = $runner->adoptionAudit();
            if (($auditBefore['adoption_state'] ?? '') !== 'legacy_upgrade_required') {
                throw new RuntimeException('Legacy upgrade is not currently allowed. Resolve the audit blockers first.');
            }
            if (($_POST['confirm'] ?? '') !== 'yes') {
                throw new RuntimeException('Confirm the no-replay legacy upgrade first.');
            }
            $message = $runner->upgradeLegacyHistoryTable((string) $user['id'])['message'];
        } elseif ($action === 'run_all') {
            $message = $runner->applyAll((string) $user['id'])['message'];
        } elseif ($action === 'run_one') {
            $message = $runner->apply(trim((string) ($_POST['migration_id'] ?? '')), (string) $user['id'])['message'];
        } else {
            throw new RuntimeException('Unknown schema action.');
        }
        $_SESSION['sqlschematic_flash'] = ['ok' => $message];
    } catch (Throwable $error) {
        $_SESSION['sqlschematic_flash'] = ['error' => $error->getMessage()];
    }
    header('Location: ' . ($_SERVER['REQUEST_URI'] ?? '/schema-updates.php'), true, 303);
    exit;
}

$flash = $_SESSION['sqlschematic_flash'] ?? [];
unset($_SESSION['sqlschematic_flash']);

$audit = $runner->adoptionAudit();
$items = $audit['compatibility']['readable'] ? $runner->status(false) : [];
$preflight = $audit['compatibility']['readable']
    ? $runner->preflight(false)
    : ['ok' => false, 'pending' => $audit['pending'], 'errors' => $audit['problems']];
$recentRuns = $runner->recentRuns(50, false);
$h = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Schema Updates</title>
<style>
:root{color-scheme:light dark;--bg:#101412;--card:#18201c;--line:#3d5046;--text:#f4f1e8;--muted:#b7c1b9;--good:#69d391;--warn:#f0bd61;--bad:#f07b72}
*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--text);font:15px/1.5 system-ui,sans-serif}main{max-width:1180px;margin:auto;padding:32px 18px}
h1{margin:.2em 0}.panel{background:var(--card);border:1px solid var(--line);border-radius:10px;padding:20px;margin:16px 0;overflow:auto}
table{width:100%;border-collapse:collapse}th,td{text-align:left;vertical-align:top;padding:12px 8px;border-bottom:1px solid var(--line)}th{color:var(--muted)}
code{color:var(--warn)}.badge{white-space:nowrap}.good{color:var(--good)}.warn{color:var(--warn)}.bad{color:var(--bad)}
.flash{padding:12px;border:1px solid;border-radius:8px}.flash.ok{color:var(--good)}.flash.error{color:var(--bad)}
button{padding:9px 12px;border:1px solid var(--good);border-radius:7px;background:transparent;color:var(--good);font:inherit;cursor:pointer}button:disabled{opacity:.4;cursor:not-allowed}label.confirm{display:block;margin:12px 0}
</style>
</head>
<body><main>
<h1>Schema Updates</h1>
<p>Gated schema management. Every action runs preflight checks, uses a database lock, and writes audit history.</p>
<p><a href="schema-repair.php">Open Schema Repair</a></p>
<?php if (isset($flash['ok'])): ?><p class="flash ok"><?= $h($flash['ok']) ?></p><?php endif ?>
<?php if (isset($flash['error'])): ?><p class="flash error"><?= $h($flash['error']) ?></p><?php endif ?>
<?php if (!$audit['compatibility']['readable']): ?><section class="panel"><h2>Initialize</h2><p>Create SqlSchematic's bookkeeping tables. This does not run application migrations.</p><form method="post"><input type="hidden" name="csrf" value="<?= $h($csrf) ?>"><input type="hidden" name="action" value="initialize"><button>Initialize SqlSchematic</button></form></section><?php endif ?>
<?php if ($audit['adoption_state'] === 'legacy_upgrade_required'): ?><section class="panel"><h2>Adopt legacy history</h2><p><strong>This records existing applied migrations without replaying SQL.</strong> It adds compatibility columns, preserves every existing record, and creates no fake run history.</p><form method="post"><input type="hidden" name="csrf" value="<?= $h($csrf) ?>"><input type="hidden" name="action" value="upgrade_legacy"><label class="confirm"><input type="checkbox" name="confirm" value="yes" required> I understand this changes only migration bookkeeping metadata.</label><button>Upgrade legacy history</button></form></section><?php endif ?>
<section class="panel">
<h2>Batch update</h2>
<?php if (!$preflight['ok']): ?>
<p class="bad">Preflight found problems. No batch SQL will run until these are fixed.</p>
<ul><?php foreach ($preflight['errors'] as $problem): ?><li><?= $h($problem) ?></li><?php endforeach ?></ul>
<?php elseif ($preflight['pending'] === []): ?>
<p class="good">The schema is current.</p>
<?php else: ?>
<p><?= count($preflight['pending']) ?> update(s) will run in order: <code><?= $h(implode(', ', $preflight['pending'])) ?></code></p>
<form method="post"><input type="hidden" name="csrf" value="<?= $h($csrf) ?>"><input type="hidden" name="action" value="run_all"><button>Run all pending updates</button></form>
<?php endif ?>
</section>
<section class="panel"><table>
<thead><tr><th>Migration</th><th>Description</th><th>Status</th><th>Applied</th><th>Action</th></tr></thead>
<tbody>
<?php foreach ($items as $item):
    $changed = (bool) $item['checksum_changed'];
    $missing = (bool) $item['missing'];
    $blocked = $item['blocked_by'] !== null;
    $integrityBlocked = !$preflight['ok'] && !$item['applied'];
    $disabled = $item['applied'] || $missing || $blocked || $integrityBlocked;
    $status = $missing ? 'Missing applied file' : ($changed ? 'Changed after apply' : ($item['applied'] ? 'Applied' : ($blocked || $integrityBlocked ? 'Waiting' : 'Pending')));
    $class = $changed || $missing || $blocked ? 'warn' : ($item['applied'] ? 'good' : 'bad');
?>
<tr>
<td><strong><?= $h($item['name']) ?></strong><br><code><?= $h($item['id']) ?></code></td>
<td><?= $h($item['description']) ?><br><small><?= $h($item['filename']) ?></small></td>
<td class="badge <?= $class ?>"><?= $h($status) ?></td>
<td><?= $h($item['applied_at'] ?? 'Not yet') ?><?php if ($item['applied_by']): ?><br><small>by <?= $h($item['applied_by']) ?></small><?php endif ?></td>
<td><?php if ($disabled): ?><span class="warn">Not runnable</span><?php else: ?><form method="post"><input type="hidden" name="csrf" value="<?= $h($csrf) ?>"><input type="hidden" name="action" value="run_one"><input type="hidden" name="migration_id" value="<?= $h($item['id']) ?>"><button>Run update</button></form><?php endif ?>
<?php if ($blocked): ?><small>Run <?= $h($item['blocked_by']) ?> first.</small><?php endif ?></td>
</tr>
<?php endforeach ?>
</tbody></table></section>
<section class="panel"><h2>Recent attempts</h2><table><thead><tr><th>Migration</th><th>Status</th><th>Started</th><th>Result</th></tr></thead><tbody>
<?php if ($recentRuns === []): ?><tr><td colspan="4">No SqlSchematic run records yet. Adopted historical migrations do not receive fabricated attempts.</td></tr><?php endif ?>
<?php foreach ($recentRuns as $run): ?><tr><td><code><?= $h($run['migration_id']) ?></code></td><td class="<?= $run['success'] ? 'good' : 'bad' ?>"><?= $run['success'] ? 'Applied by SqlSchematic' : 'Failed attempt' ?></td><td><?= $h($run['started_at']) ?></td><td><?= $h($run['message'] ?? '') ?></td></tr><?php endforeach ?>
</tbody></table></section>
</main></body></html>
