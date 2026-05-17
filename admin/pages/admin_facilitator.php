<?php
/**
 * ARISE Admin — Facilitator Sessions Manager (ADMIN page)
 * Included via admin/index.php for page 'facilitator'
 * Provides session code generation and management for facilitators.
 */

$msg = '';

// ── Generate new session code ────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create') {
    $clusterName = trim($_POST['cluster_name'] ?? '');
    $schoolName  = trim($_POST['school_name']  ?? '');

    if (!$clusterName || !$schoolName) {
        $msg = '&#10060; Please enter both a project name and cluster name.';
    } else {
        // Generate unique 6-char uppercase code
        $attempts = 0;
        do {
            $code = strtoupper(substr(bin2hex(random_bytes(4)), 0, 6));
            $exists = db()->querySingle(
                "SELECT id FROM facilitator_sessions WHERE session_code='" . SQLite3::escapeString($code) . "'"
            );
            $attempts++;
        } while ($exists && $attempts < 20);

        if ($exists) {
            $msg = '&#10060; Could not generate a unique code. Please try again.';
        } else {
            $facId = intval($_SESSION['arise_admin_id'] ?? 0);
            $st = db()->prepare(
                "INSERT INTO facilitator_sessions (facilitator_id, cluster_name, school_name, session_code, is_active)
                 VALUES (:fid, :cl, :sc, :code, 1)"
            );
            $st->bindValue(':fid',  $facId,       SQLITE3_INTEGER);
            $st->bindValue(':cl',   $clusterName, SQLITE3_TEXT);
            $st->bindValue(':sc',   $schoolName,  SQLITE3_TEXT);
            $st->bindValue(':code', $code,        SQLITE3_TEXT);
            $st->execute();
            $msg = '&#9989; New session created — code: <strong>' . e($code) . '</strong>';
        }
    }
}

// ── End an active session ─────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'end') {
    $sessionId = intval($_POST['session_id'] ?? 0);
    if ($sessionId > 0) {
        db()->exec(
            "UPDATE facilitator_sessions
             SET is_active=0, ended_at=CURRENT_TIMESTAMP
             WHERE id=$sessionId"
        );
        $msg = '&#9989; Session ended.';
    }
}

// ── Delete a session ──────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    $sessionId = intval($_POST['session_id'] ?? 0);
    if ($sessionId > 0) {
        db()->exec("DELETE FROM facilitator_sessions WHERE id=$sessionId");
        $msg = '&#9989; Session deleted.';
    }
}

// ── Fetch all sessions ────────────────────────────────────────────────────────
$sessions = [];
try {
    $res = db()->query(
        "SELECT * FROM facilitator_sessions ORDER BY started_at DESC LIMIT 100"
    );
    while ($r = $res->fetchArray(SQLITE3_ASSOC)) {
        $sessions[] = $r;
    }
} catch (Exception $e) {
    $msg = '&#9888; facilitator_sessions table not found. Run schema migration to create it.';
}

// ── Project & cluster lists for the create form ───────────────────────────────
$schools = [];
try {
    $sr = db()->query("SELECT DISTINCT s.school_name FROM students s INNER JOIN schools sc ON sc.name = s.school_name AND sc.is_active=1 WHERE s.is_active=1 AND s.school_name != '' ORDER BY s.school_name");
    while ($r = $sr->fetchArray(SQLITE3_ASSOC)) $schools[] = $r['school_name'];
} catch (Exception $e) {}

$clusters = [];
try {
    $cr = db()->query("SELECT DISTINCT s.class_name FROM students s INNER JOIN schools sc ON sc.name = s.school_name AND sc.is_active=1 WHERE s.is_active=1 AND s.class_name != '' ORDER BY s.class_name");
    while ($r = $cr->fetchArray(SQLITE3_ASSOC)) $clusters[] = $r['class_name'];
} catch (Exception $e) {}

$activeSessions  = array_filter($sessions, fn($s) => intval($s['is_active']) === 1);
$inactiveSessions = array_filter($sessions, fn($s) => intval($s['is_active']) === 0);

?>

<h1 class="page-title" style="font-size:1.2rem;font-weight:800;color:#111;margin-bottom:16px;">
  &#128247; Facilitator Sessions
</h1>

<?php if ($msg): ?>
<div class="alert" style="background:<?= str_contains($msg,'&#10060;')||str_contains($msg,'&#9888;') ? '#fef2f2' : '#f0fdf4' ?>;
     color:<?= str_contains($msg,'&#10060;')||str_contains($msg,'&#9888;') ? '#991b1b' : '#166534' ?>;
     border:1px solid <?= str_contains($msg,'&#10060;')||str_contains($msg,'&#9888;') ? '#fecaca' : '#86efac' ?>;
     border-radius:10px;padding:12px 16px;margin-bottom:16px;font-size:.9rem;" id="fac-msg">
  <?= $msg ?>
</div>
<script>setTimeout(function(){var el=document.getElementById('fac-msg');if(el)el.style.opacity='0';},4000);</script>
<?php endif; ?>

<!-- ── Facilitator access info ─────────────────────────────────────────────── -->
<div class="dp-card" style="background:linear-gradient(135deg,#052e16,#0a5e2a);color:#fff;margin-bottom:20px;border:none;">
  <div style="font-size:.72rem;color:rgba(255,255,255,.5);text-transform:uppercase;letter-spacing:.5px;margin-bottom:6px;">
    &#127760; Facilitator Access URL
  </div>
  <code style="background:rgba(255,255,255,.12);color:#6ee7b7;padding:8px 16px;border-radius:8px;font-size:.95rem;font-weight:700;letter-spacing:.5px;display:inline-block;margin-bottom:6px;">
    http://arise.local/arise/?p=facilitator
  </code>
  <div style="font-size:.78rem;color:rgba(255,255,255,.4);margin-top:4px;">
    Open on any device connected to the same WiFi &middot; No login required
  </div>
</div>

<!-- ── Create new session ──────────────────────────────────────────────────── -->
<div class="dp-card" style="margin-bottom:20px;border-left:4px solid #0ea271;">
  <h2 style="font-size:.95rem;font-weight:800;color:#111;margin-bottom:14px;">
    &#10010; Generate New Session Code
  </h2>
  <form method="POST">
    <input type="hidden" name="action" value="create">
    <div style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end;">

      <div style="flex:2;min-width:160px;">
        <label style="display:block;font-size:.75rem;font-weight:700;color:#6b7280;margin-bottom:5px;text-transform:uppercase;letter-spacing:.4px;">
          Project / School *
        </label>
        <?php if ($schools): ?>
        <select name="school_name" required style="padding:10px 14px;border:2px solid #e5e7eb;border-radius:10px;font-size:.9rem;font-family:inherit;width:100%;background:#fff;">
          <option value="">-- Select project --</option>
          <?php foreach ($schools as $s): ?>
            <option value="<?= e($s) ?>"><?= e($s) ?></option>
          <?php endforeach; ?>
        </select>
        <?php else: ?>
        <input type="text" name="school_name" required placeholder="e.g. Nairobi Youth Project"
               style="padding:10px 14px;border:2px solid #e5e7eb;border-radius:10px;font-size:.9rem;font-family:inherit;width:100%;">
        <?php endif; ?>
      </div>

      <div style="flex:2;min-width:160px;">
        <label style="display:block;font-size:.75rem;font-weight:700;color:#6b7280;margin-bottom:5px;text-transform:uppercase;letter-spacing:.4px;">
          Cluster Name *
        </label>
        <?php if ($clusters): ?>
        <select name="cluster_name" required style="padding:10px 14px;border:2px solid #e5e7eb;border-radius:10px;font-size:.9rem;font-family:inherit;width:100%;background:#fff;">
          <option value="">-- Select cluster --</option>
          <?php foreach ($clusters as $c): ?>
            <option value="<?= e($c) ?>"><?= e($c) ?></option>
          <?php endforeach; ?>
        </select>
        <?php else: ?>
        <input type="text" name="cluster_name" required placeholder="e.g. Group A, Cohort 2025"
               style="padding:10px 14px;border:2px solid #e5e7eb;border-radius:10px;font-size:.9rem;font-family:inherit;width:100%;">
        <?php endif; ?>
      </div>

      <div style="align-self:flex-end;padding-bottom:0;">
        <button type="submit" class="btn btn-primary" style="padding:11px 20px;white-space:nowrap;">
          &#127922; Generate Code
        </button>
      </div>
    </div>
  </form>
</div>

<!-- ── Active sessions ─────────────────────────────────────────────────────── -->
<?php if (count($activeSessions) > 0): ?>
<div class="dp-card" style="margin-bottom:20px;border-left:4px solid #22c55e;">
  <h2 style="font-size:.95rem;font-weight:800;color:#111;margin-bottom:14px;">
    &#9654; Active Sessions (<?= count($activeSessions) ?>)
  </h2>
  <div style="overflow-x:auto;">
    <table class="arise-table" style="font-size:.85rem;">
      <thead>
        <tr>
          <th>Code</th>
          <th>Project</th>
          <th>Cluster</th>
          <th>Started</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($activeSessions as $s): ?>
        <tr>
          <td>
            <code style="background:#f0fdf4;color:#166534;padding:5px 12px;border-radius:8px;font-size:.95rem;font-weight:800;letter-spacing:2px;border:1px solid #86efac;">
              <?= e($s['session_code']) ?>
            </code>
          </td>
          <td><strong><?= e($s['school_name'] ?? '') ?></strong></td>
          <td><?= e($s['cluster_name'] ?? '') ?></td>
          <td style="font-size:.8rem;color:#6b7280;white-space:nowrap;">
            <?= $s['started_at'] ? date('M j, g:i A', strtotime($s['started_at'])) : '—' ?>
          </td>
          <td style="white-space:nowrap;">
            <a href="/arise/?p=facilitator&project=<?= urlencode($s['school_name'] ?? '') ?>&cluster=<?= urlencode($s['cluster_name'] ?? '') ?>"
               target="_blank" class="btn btn-secondary btn-sm" style="font-size:.78rem;padding:4px 10px;">
              &#128247; View Live
            </a>
            <form method="POST" style="display:inline-block;margin-left:4px;">
              <input type="hidden" name="action" value="end">
              <input type="hidden" name="session_id" value="<?= intval($s['id']) ?>">
              <button type="submit" class="btn btn-sm"
                      style="background:#fef3c7;color:#92400e;border:1px solid #fde68a;font-size:.78rem;padding:4px 10px;"
                      onclick="return confirm('End this session?')">
                &#9632; End
              </button>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php else: ?>
<div class="dp-card" style="margin-bottom:20px;border-left:4px solid #e5e7eb;">
  <div style="color:#9ca3af;font-size:.88rem;padding:8px 0;">
    &#8722; No active sessions at the moment. Generate a code above to start one.
  </div>
</div>
<?php endif; ?>

<!-- ── All sessions history ────────────────────────────────────────────────── -->
<?php if (count($sessions) > 0): ?>
<div class="dp-card">
  <h2 style="font-size:.95rem;font-weight:800;color:#111;margin-bottom:14px;">
    &#128203; Session History
  </h2>
  <div style="overflow-x:auto;">
    <table class="arise-table" style="font-size:.84rem;">
      <thead>
        <tr>
          <th>Code</th>
          <th>Project</th>
          <th>Cluster</th>
          <th>Status</th>
          <th>Started</th>
          <th>Ended</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($sessions as $s):
          $isActive = intval($s['is_active']) === 1;
        ?>
        <tr>
          <td>
            <code style="background:<?= $isActive ? '#f0fdf4' : '#f9fafb' ?>;
                   color:<?= $isActive ? '#166534' : '#6b7280' ?>;
                   padding:4px 10px;border-radius:6px;font-weight:700;letter-spacing:1px;">
              <?= e($s['session_code']) ?>
            </code>
          </td>
          <td><?= e($s['school_name'] ?? '') ?></td>
          <td><?= e($s['cluster_name'] ?? '') ?></td>
          <td>
            <?php if ($isActive): ?>
              <span style="background:#dcfce7;color:#166534;padding:3px 10px;border-radius:20px;font-size:.75rem;font-weight:700;">
                &#9654; Active
              </span>
            <?php else: ?>
              <span style="background:#f3f4f6;color:#6b7280;padding:3px 10px;border-radius:20px;font-size:.75rem;font-weight:600;">
                &#9632; Ended
              </span>
            <?php endif; ?>
          </td>
          <td style="font-size:.8rem;color:#6b7280;white-space:nowrap;">
            <?= $s['started_at'] ? date('M j, Y g:i A', strtotime($s['started_at'])) : '—' ?>
          </td>
          <td style="font-size:.8rem;color:#9ca3af;white-space:nowrap;">
            <?= $s['ended_at'] ? date('M j, Y g:i A', strtotime($s['ended_at'])) : ($isActive ? '<span style="color:#22c55e;font-weight:600;">Live</span>' : '—') ?>
          </td>
          <td style="white-space:nowrap;">
            <?php if ($isActive): ?>
            <a href="/arise/?p=facilitator&project=<?= urlencode($s['school_name'] ?? '') ?>&cluster=<?= urlencode($s['cluster_name'] ?? '') ?>"
               target="_blank" class="btn btn-secondary btn-sm" style="font-size:.75rem;padding:3px 8px;">
              &#128247; Live
            </a>
            <form method="POST" style="display:inline-block;margin-left:3px;">
              <input type="hidden" name="action" value="end">
              <input type="hidden" name="session_id" value="<?= intval($s['id']) ?>">
              <button type="submit" class="btn btn-sm"
                      style="background:#fef3c7;color:#92400e;font-size:.75rem;padding:3px 8px;"
                      onclick="return confirm('End this session?')">
                End
              </button>
            </form>
            <?php else: ?>
            <a href="?p=facilitator_report&session_id=<?= intval($s['id']) ?>"
               class="btn btn-sm"
               style="background:#eff6ff;color:#1d4ed8;border:1px solid #bfdbfe;font-size:.75rem;padding:3px 8px;text-decoration:none;">
              &#128203; Report
            </a>
            <form method="POST" style="display:inline-block;margin-left:3px;">
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="session_id" value="<?= intval($s['id']) ?>">
              <button type="submit" class="btn btn-sm"
                      style="background:#fee2e2;color:#991b1b;font-size:.75rem;padding:3px 8px;"
                      onclick="return confirm('Delete this session record?')">
                &#128465; Delete
              </button>
            </form>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php if (count($sessions) === 100): ?>
  <div style="font-size:.78rem;color:#9ca3af;margin-top:10px;text-align:center;">
    Showing most recent 100 sessions.
  </div>
  <?php endif; ?>
</div>
<?php else: ?>
<div class="dp-card">
  <div style="color:#9ca3af;font-size:.88rem;text-align:center;padding:16px 0;">
    No sessions yet. Generate your first session code above.
  </div>
</div>
<?php endif; ?>
