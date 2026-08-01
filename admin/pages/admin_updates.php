<?php
// ARISE Updates — non-technical UX.
// Workflows:
//   1. Online box: click "Get latest update" → pulls field-boxes from GitHub.
//   2. Offline box: someone uploaded a bundle via DataPost; click "Install".
//   3. Something went wrong: click "Undo last update" on the most recent backup.

if (!function_exists('db')) require_once dirname(__DIR__) . '/../includes/config.php';

$updatesRoot = '/var/www/arise/data/content/updates';
$backupsRoot = '/var/www/arise/data/backups';
$logFile     = '/var/www/arise/data/updates.log';
$arisaRoot   = '/var/www/arise';

// The branch on kenyaone/arise that carries the school-box app. Do NOT change
// this to 'master' or to an API-resolved default_branch — the repo's master
// hosts a different application (cloud device panel), and pulling it bricks
// the box. See incident 2026-07-30.
const ARISE_FIELD_BRANCH = 'field-boxes';

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

function findExistingBundleForSha(string $updatesRoot, string $sha): ?array {
    foreach (listBundles($updatesRoot) as $b) {
        if (!empty($b['manifest']['git_sha']) && $b['manifest']['git_sha'] === $sha) {
            return $b;
        }
    }
    return null;
}

function githubHeadSha(string $repo, string $ref): ?string {
    $resp = @file_get_contents(
        "https://api.github.com/repos/$repo/commits/" . urlencode($ref),
        false,
        stream_context_create(['http' => [
            'method'=>'GET',
            'header'=>"User-Agent: arise-updater\r\n",
            'timeout'=>5,
            'ignore_errors'=>true,
        ]])
    );
    if (!$resp) return null;
    $j = json_decode($resp, true);
    return (is_array($j) && !empty($j['sha'])) ? (string)$j['sha'] : null;
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
    // Marker guard: refuse to apply anything that isn't the school-box app.
    // The 2026-07-30 brick was caused by a bundle from the cloud device panel
    // being applied on top of the school-box codebase. Cheap positive check:
    // the golden admin/index.php starts with a distinctive header comment.
    // And a negative check: the cloud panel's cPanel-only path must not appear.
    $indexPath = rtrim($bundlePath, '/') . '/admin/index.php';
    if (!is_file($indexPath)) {
        return ['ok'=>false, 'out'=>"REJECTED: bundle has no admin/index.php — not a school-box update."];
    }
    $head = (string) @file_get_contents($indexPath, false, null, 0, 400);
    if (strpos($head, 'ARISE Teacher & Admin Panel') === false) {
        return ['ok'=>false, 'out'=>"REJECTED: bundle's admin/index.php is missing the school-box marker header. This protects the box from an accidentally-published cloud/receiver bundle."];
    }
    if (strpos($head, '/home/cpmsfdav/') !== false) {
        return ['ok'=>false, 'out'=>"REJECTED: bundle contains a cloud-only path (/home/cpmsfdav/). Not applying."];
    }

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

function healthCheckAfterApply(): array {
    // Called after a successful apply. Hits a handful of always-public
    // endpoints via localhost. Any 5xx or connection failure means the new
    // code is broken and we should auto-rollback. Headless field boxes have
    // no other recovery path — this is the failsafe.
    $endpoints = [
        'http://localhost/arise/',
        'http://localhost/arise/login',
        'http://localhost/arise/admin/',
    ];
    $failures = [];
    foreach ($endpoints as $url) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT        => 5,
            CURLOPT_NOBODY         => true,
        ]);
        curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code === 0 || $code >= 500) {
            $failures[] = "$url → HTTP $code";
        }
    }
    return $failures;
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
        // Pinned to the school-box branch. Do NOT resolve default_branch from
        // the GitHub API — see the ARISE_FIELD_BRANCH comment at top.
        //
        // Short-circuit: ask GitHub for the branch HEAD SHA first (fast API
        // call). If a bundle at that SHA is already on disk, skip the ~5-min
        // download that trips the Apache 300s timeout and leaves the browser
        // stuck on "Downloading…".
        $latestSha = githubHeadSha('kenyaone/arise', ARISE_FIELD_BRANCH);
        $existing  = $latestSha ? findExistingBundleForSha($updatesRoot, $latestSha) : null;
        if ($existing) {
            log_update("PULL github " . ARISE_FIELD_BRANCH . " — SKIP (already have {$existing['id']})");
            $flash = ['ok', "✅ Latest update is already downloaded — click <strong>Install update</strong> below (no re-download needed)."];
        } else {
            $pull = pullFromGitHub(ARISE_FIELD_BRANCH, $updatesRoot);
            if ($pull['ok']) {
                log_update("PULL github " . ARISE_FIELD_BRANCH . " — OK as " . $pull['id']);
                $flash = ['ok', "✅ Update downloaded ({$pull['files']} files). Click <strong>Install update</strong> below."];
            } else {
                log_update("PULL github " . ARISE_FIELD_BRANCH . " — FAILED: " . $pull['out']);
                $flash = ['err', "Could not download. Check internet, then try again."];
            }
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
                    // Post-apply healthcheck — headless boxes can't recover
                    // manually, so we self-verify and auto-rollback on 5xx.
                    $failures = healthCheckAfterApply();
                    if (!empty($failures)) {
                        $rb = rollbackBackup($backup['path'], $arisaRoot, $backupsRoot);
                        if ($rb['ok']) {
                            log_update("APPLY $id — AUTO-REVERTED after healthcheck failed:\n  " . implode("\n  ", $failures));
                            $flash = ['err', "🛡 Update installed but health check failed — automatically reverted. Your box is safe.<br><em>Failures: " . htmlspecialchars(implode(' · ', $failures)) . "</em>"];
                        } else {
                            log_update("APPLY $id — HEALTHCHECK FAILED and ROLLBACK FAILED: " . $rb['out']);
                            $flash = ['err', "⚠️ Update installed but broken, and auto-revert also failed. Contact support."];
                        }
                    } else {
                        log_update("APPLY $id — OK (backup: " . basename($backup['path']) . ")");
                        $flash = ['ok', "✅ Update installed successfully. Your data is unchanged. Use <strong>Undo last update</strong> below if anything looks wrong."];
                    }
                } else {
                    log_update("APPLY $id — FAILED: " . $apply['out']);
                    // If the marker guard rejected it, surface that specifically so
                    // admins understand this is a safety feature, not a bug.
                    if (strpos($apply['out'], 'REJECTED:') === 0) {
                        $flash = ['err', "🛡 This update was rejected by the safety guard: <br><em>" . htmlspecialchars($apply['out']) . "</em><br>Your system is unchanged."];
                    } else {
                        $flash = ['err', "Install failed — your system is unchanged. Backup saved as a safety net."];
                    }
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

// Determine which bundle is currently installed by scanning updates.log for
// the most recent successful APPLY. Lets the UI mark that card as CURRENT and
// disable its Install button — otherwise identical-looking cards (both built
// the same day) let a user re-install the older one by mistake.
$installedBundleId = null;
if (is_file($logFile)) {
    $lines = @file($logFile) ?: [];
    for ($i = count($lines) - 1; $i >= 0; $i--) {
        if (preg_match('/APPLY (\S+) — OK/', $lines[$i], $mm)) {
            $installedBundleId = $mm[1];
            break;
        }
    }
}
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
  <?php else: foreach ($bundles as $i => $b):
    $m           = $b['manifest'];
    $when        = $m['built_at'] ?? null;
    $when_ts     = $when ? strtotime($when) : $b['mtime'];
    $isInstalled = ($b['id'] === $installedBundleId);
    $isNewest    = ($i === 0); // listBundles sorts newest-first
    $sha         = (!empty($m['git_sha']) && $m['git_sha'] !== 'unknown') ? substr($m['git_sha'], 0, 7) : null;
  ?>
    <div style="border:1.5px solid <?= $isInstalled ? '#d1fae5' : ($isNewest ? '#a7f3d0' : '#e5e7eb') ?>;background:<?= $isInstalled ? '#f0fdf4' : '#fff' ?>;border-radius:10px;padding:14px;margin-bottom:12px;display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;">
      <div>
        <strong style="font-size:1rem;">ARISE Update — <?= date('M j, Y \a\t H:i', $when_ts) ?></strong>
        <?php if ($isInstalled): ?>
          <span style="background:#059669;color:#fff;padding:2px 8px;border-radius:4px;font-size:.7rem;margin-left:6px;font-weight:600;">✓ CURRENTLY INSTALLED</span>
        <?php elseif ($isNewest): ?>
          <span style="background:#10b981;color:#fff;padding:2px 8px;border-radius:4px;font-size:.7rem;margin-left:6px;font-weight:600;">NEW — install this</span>
        <?php endif; ?>
        <div style="font-size:.8rem;color:#6b7280;margin-top:4px;">
          <?= $b['files'] ?> files · <?= fmtBytes((int)$b['bytes']) ?>
          <?php if ($sha): ?> · version <code style="background:#f3f4f6;padding:1px 4px;border-radius:3px;"><?= $sha ?></code><?php endif; ?>
          · received <?= friendlyDate($b['mtime']) ?>
        </div>
        <?php if (!$m): ?>
          <div style="font-size:.78rem;color:#92400e;margin-top:4px;">⚠ This update has no version info. Check with whoever provided it before installing.</div>
        <?php endif; ?>
      </div>
      <?php if ($isInstalled): ?>
        <span style="color:#059669;font-size:.85rem;font-weight:500;padding:8px 12px;">Already installed</span>
      <?php else: ?>
        <form method="POST" style="margin:0;" onsubmit="return confirm('Install this update? Your projects, learners and data will not be touched.');">
          <input type="hidden" name="action" value="apply">
          <input type="hidden" name="update_id" value="<?= htmlspecialchars($b['id']) ?>">
          <button type="submit" class="btn btn-primary">Install update</button>
        </form>
      <?php endif; ?>
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
