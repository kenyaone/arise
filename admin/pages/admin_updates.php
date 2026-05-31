<?php
// ARISE Updates — non-technical UX.
// Workflows:
//   1. Online box: click "Get latest update" → pulls main from GitHub.
//   2. Offline box: someone uploaded a bundle via DataPost; click "Install".
//   3. Something went wrong: click "Undo last update" on the most recent backup.

if (!function_exists('db')) require_once dirname(__DIR__) . '/../includes/config.php';

$updatesRoot = '/var/www/arise/data/content/updates';
$backupsRoot = '/var/www/arise/data/backups';
$logFile     = '/var/www/arise/data/updates.log';
$arisaRoot   = '/var/www/arise';

@mkdir($updatesRoot, 0755, true);
@mkdir($backupsRoot, 0755, true);

function log_update(string $msg): void {
    global $logFile;
    @file_put_contents($logFile, '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL, FILE_APPEND);
}

function listBundles(string $root): array {
    if (!is_dir($root)) return [];
    $out = [];
    foreach (scandir($root) as $d) {
        if ($d === '.' || $d === '..') continue;
        $path = "$root/$d";
        if (!is_dir($path)) continue;
        $manifest = null;
        if (is_file("$path/manifest.json")) {
            $manifest = json_decode((string)file_get_contents("$path/manifest.json"), true);
        }
        $count = 0; $bytes = 0;
        try {
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS));
            foreach ($it as $f) { $count++; $bytes += $f->getSize(); }
        } catch (Throwable $e) {}
        $out[] = ['id'=>$d, 'path'=>$path, 'manifest'=>$manifest, 'files'=>$count, 'bytes'=>$bytes, 'mtime'=>filemtime($path)];
    }
    usort($out, fn($a, $b) => $b['mtime'] <=> $a['mtime']);
    return $out;
}

function listBackups(string $root): array {
    $out = [];
    foreach (glob("$root/code-*.tar.gz") ?: [] as $p) {
        $out[] = ['path'=>$p, 'name'=>basename($p), 'bytes'=>filesize($p), 'mtime'=>filemtime($p)];
    }
    usort($out, fn($a, $b) => $b['mtime'] <=> $a['mtime']);
    return $out;
}

function backupCurrent(string $arisaRoot, string $backupsRoot): array {
    $ts = date('Ymd-His');
    $out = "$backupsRoot/code-$ts.tar.gz";
    $cmd = sprintf(
        'tar -czf %s --exclude=%s --exclude=%s -C %s .',
        escapeshellarg($out),
        escapeshellarg('./data'),
        escapeshellarg('./data.bak.*'),
        escapeshellarg($arisaRoot)
    );
    $ret = 0; $output = [];
    exec("$cmd 2>&1", $output, $ret);
    return ['ok'=>$ret === 0, 'path'=>$out, 'out'=>implode("\n", $output)];
}

function applyBundle(string $bundlePath, string $arisaRoot): array {
    $cmd = sprintf(
        'rsync -a --omit-dir-times --exclude=%s %s/ %s/',
        escapeshellarg('data/'),
        escapeshellarg(rtrim($bundlePath, '/')),
        escapeshellarg(rtrim($arisaRoot, '/'))
    );
    $ret = 0; $output = [];
    exec("$cmd 2>&1", $output, $ret);
    if ($ret !== 0) return ['ok'=>false, 'out'=>implode("\n", $output)];
    @exec(sprintf('chown -R www-data:www-data %s 2>&1', escapeshellarg($arisaRoot)));
    @exec(sprintf('find %s -type f -name "*.php" -exec touch {} +', escapeshellarg($arisaRoot)));

    // Mirror cloud_push.php → /home/arise/ so the cron picks up changes
    // that ride the update channel. Best-effort: if perms forbid the copy,
    // the cron just keeps running the previous version (no breakage).
    $src = rtrim($arisaRoot, '/') . '/cloud_push.php';
    $dst = '/home/arise/cloud_push.php';
    if (is_file($src) && (!file_exists($dst) || md5_file($src) !== md5_file($dst))) {
        @copy($src, $dst);
        @chmod($dst, 0755);
        @chown($dst, 'arise');
        @chgrp($dst, 'arise');
    }

    return ['ok'=>true, 'out'=>implode("\n", $output)];
}

function rollbackBackup(string $backupPath, string $arisaRoot, string $backupsRoot): array {
    $safety = backupCurrent($arisaRoot, $backupsRoot);
    if (!$safety['ok']) return ['ok'=>false, 'out'=>'pre-rollback backup failed: ' . $safety['out']];
    $cmd = sprintf(
        'tar -xzf %s -C %s --overwrite --exclude=%s',
        escapeshellarg($backupPath),
        escapeshellarg($arisaRoot),
        escapeshellarg('./data')
    );
    $ret = 0; $output = [];
    exec("$cmd 2>&1", $output, $ret);
    if ($ret !== 0) return ['ok'=>false, 'out'=>implode("\n", $output)];
    @exec(sprintf('find %s -type f -name "*.php" -exec touch {} +', escapeshellarg($arisaRoot)));
    return ['ok'=>true, 'out'=>"restored from " . basename($backupPath)];
}

function pullFromGitHub(string $gitRef, string $updatesRoot): array {
    if (!preg_match('/^[A-Za-z0-9._\/\-]{1,100}$/', $gitRef)) {
        return ['ok'=>false, 'out'=>'invalid git ref'];
    }
    $repo = 'kenyaone/arise';
    $url  = "https://codeload.github.com/$repo/tar.gz/$gitRef";
    $ts   = date('Ymd-His');
    $safeRef = preg_replace('/[^A-Za-z0-9._\-]/', '_', $gitRef);
    $target  = "$updatesRoot/$ts-github-$safeRef";

    if (!is_dir($updatesRoot)) @mkdir($updatesRoot, 0755, true);
    if (!is_dir($target))      @mkdir($target,     0755, true);

    $tarball = sys_get_temp_dir() . "/arise-github-$ts.tgz";
    $cmd = sprintf('curl -fsSL -o %s %s 2>&1', escapeshellarg($tarball), escapeshellarg($url));
    $ret = 0; $output = [];
    exec($cmd, $output, $ret);
    if ($ret !== 0 || !is_file($tarball)) {
        @rmdir($target);
        return ['ok'=>false, 'out'=>"download failed:\n" . implode("\n", $output)];
    }

    $cmd = sprintf('tar -xzf %s --strip-components=1 -C %s 2>&1', escapeshellarg($tarball), escapeshellarg($target));
    exec($cmd, $output2, $ret2);
    @unlink($tarball);
    if ($ret2 !== 0) return ['ok'=>false, 'out'=>"extract failed:\n" . implode("\n", $output2)];

    $sha = null;
    $apiResp = @file_get_contents("https://api.github.com/repos/$repo/commits/" . urlencode($gitRef), false, stream_context_create([
        'http' => ['method'=>'GET', 'header'=>"User-Agent: arise-updater\r\n", 'timeout'=>5, 'ignore_errors'=>true]
    ]));
    if ($apiResp) {
        $j = json_decode($apiResp, true);
        if (is_array($j) && !empty($j['sha'])) $sha = (string)$j['sha'];
    }

    $count = 0; $bytes = 0;
    try {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($target, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) { $count++; $bytes += $f->getSize(); }
    } catch (Throwable $e) {}
    $manifest = [
        'version'   => "$ts-github-$safeRef",
        'git_ref'   => $gitRef,
        'git_sha'   => $sha ?: 'unknown',
        'built_at'  => gmdate('Y-m-d\TH:i:s\Z'),
        'files'     => $count,
        'bytes'     => $bytes,
        'courier'   => 'github-pull@' . gethostname(),
    ];
    file_put_contents("$target/manifest.json", json_encode($manifest, JSON_PRETTY_PRINT));

    return ['ok'=>true, 'id'=>basename($target), 'files'=>$count];
}

function friendlyDate(?int $ts): string {
    if (!$ts) return '—';
    $diff = time() - $ts;
    if ($diff < 60) return 'just now';
    if ($diff < 3600) return floor($diff / 60) . ' min ago';
    if ($diff < 86400) return floor($diff / 3600) . ' hr ago';
    if ($diff < 7 * 86400) return floor($diff / 86400) . ' days ago';
    return date('M j, Y', $ts);
}
function fmtBytes(int $b): string {
    $u = ['B','KB','MB','GB'];
    $i = 0; while ($b >= 1024 && $i < count($u) - 1) { $b /= 1024; $i++; }
    return number_format($b, $i > 0 ? 1 : 0) . ' ' . $u[$i];
}

// ── POST handlers ───────────────────────────────────────────────────────────
$flash = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'pull') {
        // Resolve the repo's actual default branch so we don't hard-code main/master.
        $defaultBranch = 'master';
        $apiResp = @file_get_contents('https://api.github.com/repos/kenyaone/arise', false, stream_context_create([
            'http' => ['method'=>'GET', 'header'=>"User-Agent: arise-updater\r\n", 'timeout'=>5, 'ignore_errors'=>true]
        ]));
        if ($apiResp) {
            $j = json_decode($apiResp, true);
            if (is_array($j) && !empty($j['default_branch'])) $defaultBranch = (string)$j['default_branch'];
        }
        $pull = pullFromGitHub($defaultBranch, $updatesRoot);
        if ($pull['ok']) {
            log_update("PULL github main — OK as " . $pull['id']);
            $flash = ['ok', "✅ Update downloaded ({$pull['files']} files). Click <strong>Install update</strong> below."];
        } else {
            log_update("PULL github main — FAILED: " . $pull['out']);
            $flash = ['err', "Could not download. Check internet, then try again."];
        }
    } elseif ($action === 'apply') {
        $id = (string)($_POST['update_id'] ?? '');
        $path = "$updatesRoot/$id";
        if (!is_dir($path) || strpos(realpath($path) ?: '', $updatesRoot) !== 0) {
            $flash = ['err', "Update not found."];
        } else {
            $backup = backupCurrent($arisaRoot, $backupsRoot);
            if (!$backup['ok']) {
                log_update("APPLY $id — backup FAILED: " . $backup['out']);
                $flash = ['err', "Couldn't take a safety backup — update was not installed."];
            } else {
                $apply = applyBundle($path, $arisaRoot);
                if ($apply['ok']) {
                    log_update("APPLY $id — OK (backup: " . basename($backup['path']) . ")");
                    $flash = ['ok', "✅ Update installed successfully. Your data is unchanged. Use <strong>Undo last update</strong> below if anything looks wrong."];
                } else {
                    log_update("APPLY $id — FAILED: " . $apply['out']);
                    $flash = ['err', "Install failed — your system is unchanged. Backup saved as a safety net."];
                }
            }
        }
    } elseif ($action === 'rollback') {
        $name = basename((string)($_POST['backup'] ?? ''));
        $path = "$backupsRoot/$name";
        if (!is_file($path) || strpos(realpath($path) ?: '', $backupsRoot) !== 0) {
            $flash = ['err', "Backup not found."];
        } else {
            $rb = rollbackBackup($path, $arisaRoot, $backupsRoot);
            if ($rb['ok']) {
                log_update("ROLLBACK $name — OK");
                $flash = ['ok', "✅ Undone. The previous update has been reversed."];
            } else {
                log_update("ROLLBACK $name — FAILED: " . $rb['out']);
                $flash = ['err', "Could not undo: " . htmlspecialchars($rb['out'])];
            }
        }
    }
}

$bundles = listBundles($updatesRoot);
$backups = listBackups($backupsRoot);
$latestBackup = $backups[0] ?? null;
?>
<h1 class="page-title">⬆️ Updates</h1>
<p class="text-muted" style="margin-top:-16px;margin-bottom:20px;">
  Keep the ARISE platform up to date. Updates change the system features — your projects, learners and data are never touched.
</p>

<?php if ($flash): ?>
  <div class="alert alert-<?= $flash[0]==='ok'?'success':'danger' ?>"><?= $flash[1] ?></div>
<?php endif; ?>

<!-- 1. Online-pull -->
<div class="dp-card">
  <h2 class="section-title">🌐 Get the latest update</h2>
  <p class="text-muted" style="margin-bottom:12px;font-size:.9rem;">
    Downloads the newest version from the internet. Use this if you're connected to WiFi or have a phone hotspot.
  </p>
  <form method="POST" onsubmit="this.querySelector('button').disabled=true;this.querySelector('button').innerText='Downloading…';">
    <input type="hidden" name="action" value="pull">
    <button type="submit" class="btn btn-primary">📥 Get latest update from internet</button>
  </form>
</div>

<!-- 2. Pending bundles -->
<div class="dp-card">
  <h2 class="section-title">📦 Updates ready to install
    <span style="color:#9ca3af;font-size:.85rem;font-weight:400;margin-left:6px;">(<?= count($bundles) ?>)</span>
  </h2>
  <?php if (!$bundles): ?>
    <p class="text-muted">Nothing waiting. Use the green button above, or have someone deliver an update via DataPost.</p>
  <?php else: foreach ($bundles as $b): $m = $b['manifest']; $when = $m['built_at'] ?? null; $when_ts = $when ? strtotime($when) : $b['mtime']; ?>
    <div style="border:1.5px solid #e5e7eb;border-radius:10px;padding:14px;margin-bottom:12px;display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;">
      <div>
        <strong style="font-size:1rem;">ARISE Update — <?= date('M j, Y', $when_ts) ?></strong>
        <div style="font-size:.8rem;color:#6b7280;margin-top:4px;">
          <?= $b['files'] ?> files · <?= fmtBytes((int)$b['bytes']) ?> · received <?= friendlyDate($b['mtime']) ?>
        </div>
        <?php if (!$m): ?>
          <div style="font-size:.78rem;color:#92400e;margin-top:4px;">⚠ This update has no version info. Check with whoever provided it before installing.</div>
        <?php endif; ?>
      </div>
      <form method="POST" style="margin:0;" onsubmit="return confirm('Install this update? Your projects, learners and data will not be touched.');">
        <input type="hidden" name="action" value="apply">
        <input type="hidden" name="update_id" value="<?= htmlspecialchars($b['id']) ?>">
        <button type="submit" class="btn btn-primary">Install update</button>
      </form>
    </div>
  <?php endforeach; endif; ?>
</div>

<!-- 3. Undo last update -->
<?php if ($latestBackup): ?>
<div class="dp-card">
  <h2 class="section-title">↩️ Undo last update</h2>
  <p class="text-muted" style="margin-bottom:12px;font-size:.9rem;">
    If something stopped working after the last update, you can reverse it. Your data stays the same either way.
  </p>
  <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;">
    <div>
      <strong>Snapshot from <?= friendlyDate($latestBackup['mtime']) ?></strong>
      <span style="font-size:.78rem;color:#6b7280;margin-left:6px;">(<?= fmtBytes((int)$latestBackup['bytes']) ?>)</span>
    </div>
    <form method="POST" style="margin:0;" onsubmit="return confirm('Undo the last update? Your data is not affected.');">
      <input type="hidden" name="action" value="rollback">
      <input type="hidden" name="backup" value="<?= htmlspecialchars($latestBackup['name']) ?>">
      <button type="submit" class="btn btn-sm" style="background:#fef3c7;color:#92400e;">↩️ Undo last update</button>
    </form>
  </div>
  <?php if (count($backups) > 1): ?>
    <details style="margin-top:14px;">
      <summary style="cursor:pointer;font-size:.85rem;color:#6b7280;">Show older snapshots (<?= count($backups) - 1 ?>)</summary>
      <div style="margin-top:10px;">
      <?php foreach (array_slice($backups, 1) as $b): ?>
        <div style="display:flex;justify-content:space-between;align-items:center;padding:8px 0;border-bottom:1px solid #f3f4f6;font-size:.85rem;">
          <span>Snapshot from <?= friendlyDate($b['mtime']) ?></span>
          <form method="POST" style="margin:0;" onsubmit="return confirm('Restore this older snapshot? Your data is not affected.');">
            <input type="hidden" name="action" value="rollback">
            <input type="hidden" name="backup" value="<?= htmlspecialchars($b['name']) ?>">
            <button type="submit" class="btn btn-sm" style="background:#f3f4f6;">Restore</button>
          </form>
        </div>
      <?php endforeach; ?>
      </div>
    </details>
  <?php endif; ?>
</div>
<?php endif; ?>

<!-- 4. Recent activity -->
<?php if (is_file($logFile)):
    $logLines = array_slice(file($logFile), -8);
    if ($logLines): ?>
<div class="dp-card">
  <h2 class="section-title">📜 Recent activity</h2>
  <div style="font-size:.85rem;">
    <?php foreach (array_reverse($logLines) as $line):
        // Parse "[YYYY-MM-DD HH:MM:SS] ACTION ..."
        if (preg_match('/^\[([^\]]+)\]\s+(\w+)\s+(.+)\s+—\s+(OK|FAILED.*)$/', trim($line), $mm)) {
            [$_, $when, $verb, $what, $result] = $mm;
            $isOk = strpos($result, 'OK') === 0;
            $label = ['APPLY'=>'Installed update', 'ROLLBACK'=>'Reversed an update', 'PULL'=>'Downloaded update'][$verb] ?? $verb;
            ?>
            <div style="padding:8px 0;border-bottom:1px solid #f3f4f6;display:flex;justify-content:space-between;align-items:center;">
              <span><?= $isOk ? '✅' : '❌' ?> <?= htmlspecialchars($label) ?> · <span style="color:#6b7280;"><?= friendlyDate(strtotime($when)) ?></span></span>
              <?php if (!$isOk): ?><span style="color:#991b1b;font-size:.78rem;">failed</span><?php endif; ?>
            </div>
            <?php
        }
    endforeach; ?>
  </div>
</div>
<?php endif; endif; ?>
