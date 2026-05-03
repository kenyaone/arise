<?php
require_once __DIR__ . '/../../includes/config.php';
$ver = defined('ARISE_VERSION') ? ARISE_VERSION : '1.0';
$dbPath = defined('DB_PATH') ? DB_PATH : '/var/www/arise/data/arise.db';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>ARISE Technical Manual</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Segoe UI',Arial,sans-serif;font-size:13pt;color:#111;background:#fff;line-height:1.6}
.cover{background:linear-gradient(135deg,#1e1b4b,#312e81);color:#fff;padding:80px 60px;min-height:260px;display:flex;flex-direction:column;justify-content:center}
.cover h1{font-size:2.8rem;font-weight:900;letter-spacing:-.5px;margin-bottom:8px}
.cover .sub{font-size:1.1rem;color:rgba(255,255,255,.7);margin-bottom:30px}
.cover .meta{font-size:.9rem;color:rgba(255,255,255,.5)}
.content{max-width:820px;margin:0 auto;padding:40px 40px 80px}
h2{font-size:1.4rem;font-weight:800;color:#1e1b4b;border-bottom:3px solid #6366f1;padding-bottom:6px;margin:36px 0 14px}
h3{font-size:1.05rem;font-weight:700;color:#312e81;margin:22px 0 8px}
p{margin-bottom:10px}
ul,ol{margin:8px 0 12px 24px}
li{margin-bottom:5px}
.tip{background:#eff6ff;border-left:4px solid #6366f1;border-radius:0 8px 8px 0;padding:12px 16px;margin:14px 0;font-size:.95rem}
.warn{background:#fffbeb;border-left:4px solid #f59e0b;border-radius:0 8px 8px 0;padding:12px 16px;margin:14px 0;font-size:.95rem}
.danger{background:#fef2f2;border-left:4px solid #ef4444;border-radius:0 8px 8px 0;padding:12px 16px;margin:14px 0;font-size:.95rem}
pre,code{background:#1e293b;color:#7dd3fc;border-radius:6px;padding:2px 6px;font-family:'Courier New',monospace;font-size:.85rem}
pre{display:block;padding:12px 16px;margin:10px 0;overflow-x:auto;white-space:pre-wrap;word-break:break-all}
table{width:100%;border-collapse:collapse;margin:12px 0}
th{background:#1e1b4b;color:#fff;padding:8px 12px;font-size:.85rem;text-align:left}
td{padding:8px 12px;border-bottom:1px solid #e5e7eb;font-size:.88rem;vertical-align:top}
tr:nth-child(even) td{background:#f9fafb}
.no-print{margin-bottom:0}
@media print{
  .no-print{display:none}
  .cover{-webkit-print-color-adjust:exact;print-color-adjust:exact}
  pre,code{background:#f3f4f6;color:#1e293b;border:1px solid #e5e7eb}
  body{font-size:10.5pt}
}
@page{margin:15mm 20mm}
</style>
</head>
<body>

<div class="no-print" style="background:#1e1b4b;padding:12px 40px;display:flex;align-items:center;justify-content:space-between;">
  <div style="display:flex;gap:20px;align-items:center;">
    <span style="color:#a5b4fc;font-weight:700;">⚙ Technical Docs</span>
    <a href="/arise/?p=manual_user" style="color:#a5b4fc;text-decoration:none;font-size:.9rem;padding:6px 12px;border-radius:6px;border:1px solid #4c1d95;transition:.2s">📖 User Manual</a>
    <a href="/arise/?p=manual_impact" style="color:#a5b4fc;text-decoration:none;font-size:.9rem;padding:6px 12px;border-radius:6px;border:1px solid #4c1d95;transition:.2s">📊 Impact Guide</a>
    <a href="/arise/?p=datapost" style="color:#a5b4fc;text-decoration:none;font-size:.9rem;padding:6px 12px;border-radius:6px;border:1px solid #4c1d95;transition:.2s">💾 DataPost</a>
  </div>
  <button onclick="window.print()" style="background:#6366f1;color:#fff;border:none;border-radius:8px;padding:8px 20px;font-weight:700;cursor:pointer;font-size:.9rem;">
    &#128424; Print / Save as PDF
  </button>
</div>

<div class="cover">
  <div style="font-size:.8rem;letter-spacing:2px;text-transform:uppercase;color:rgba(255,255,255,.4);margin-bottom:12px;">ARISE Platform</div>
  <h1>Technical Manual</h1>
  <div class="sub">For System Administrators &amp; Developers</div>
  <div class="meta">Version <?= e($ver) ?> &nbsp;&middot;&nbsp; <?= date('F Y') ?> &nbsp;&middot;&nbsp; Adolescent Reproductive Health Information Support &amp; Empowerment</div>
</div>

<div class="content">

<h2>Table of Contents</h2>
<ol>
  <li>System Architecture</li>
  <li>Requirements &amp; Dependencies</li>
  <li>File Structure</li>
  <li>Apache Configuration</li>
  <li>Database Schema</li>
  <li>Configuration File</li>
  <li>Admin Panel</li>
  <li>DataPost API</li>
  <li>Backup &amp; Recovery</li>
  <li>Schema Migrations</li>
  <li>Troubleshooting</li>
  <li>Security Notes</li>
</ol>

<h2>1. System Architecture</h2>
<p>ARISE is a single-server offline-first PHP application. There is no cloud dependency — everything runs locally.</p>
<table>
  <tr><th>Component</th><th>Technology</th><th>Notes</th></tr>
  <tr><td>Web server</td><td>Apache 2.4</td><td>HTTP on port 80; LAN IP <code>192.168.0.10</code></td></tr>
  <tr><td>Server-side language</td><td>PHP 8.0+</td><td>No frameworks; plain PHP</td></tr>
  <tr><td>Database</td><td>SQLite 3</td><td>Single file; no MySQL/Postgres needed</td></tr>
  <tr><td>Network</td><td>LAN Hotspot</td><td>Access via <code>http://192.168.0.10/arise/</code></td></tr>
  <tr><td>Uploads</td><td>File system</td><td><code>/var/www/arise/data/uploads/</code></td></tr>
</table>

<h2>2. Requirements &amp; Dependencies</h2>
<ul>
  <li>Ubuntu / Debian Linux (22.04 LTS recommended)</li>
  <li>Apache 2.4 with <code>mod_rewrite</code>, <code>mod_alias</code>, <code>mod_headers</code></li>
  <li>PHP 8.0 or higher with extensions: <code>sqlite3</code>, <code>json</code>, <code>mbstring</code>, <code>gd</code></li>
  <li>Network: LAN hotspot with fixed IP <code>192.168.0.10</code></li>
  <li>All devices connect to the same WiFi network (LN)</li>
</ul>
<h3>Install dependencies</h3>
<pre>sudo apt update
sudo apt install apache2 php8.2 php8.2-sqlite3 php8.2-mbstring \
     php8.2-gd avahi-daemon libapache2-mod-php
sudo a2enmod rewrite alias headers
sudo systemctl restart apache2</pre>

<h2>3. File Structure</h2>
<pre>/var/www/arise/
├── admin/
│   ├── index.php          ← Admin panel entry point
│   └── pages/             ← Admin page includes
│       ├── admin_analytics.php
│       ├── admin_facilitator.php
│       ├── admin_quiz.php
│       └── ...
├── data/
│   ├── arise.db           ← SQLite database
│   ├── uploads/           ← Uploaded lesson files &amp; images
│   └── backups/           ← Auto-generated backups
├── includes/
│   └── config.php         ← Database connection, helpers, constants
└── public/
    ├── index.php          ← Public router
    ├── css/style.css
    ├── js/
    │   ├── app.js
    │   └── qr_helper.js
    └── pages/             ← Public page includes</pre>

<h2>4. Apache Configuration</h2>
<p>Config file: <code>/etc/apache2/sites-available/mtti-lms.conf</code></p>
<div class="tip">ARISE is served over plain HTTP on port 80. SSL is only used for the MTTI-LMS application on port 443. This avoids browser "site not safe" warnings on the LAN.</div>
<pre># Key directives in :80 VirtualHost
RedirectMatch ^/data$ /arise/?p=datapost
RewriteRule ^/mtti-lms(.*)$ https://%{HTTP_HOST}/mtti-lms$1 [R=301,L]
Alias /arise/uploads /var/www/arise/data/uploads
Alias /arise/admin   /var/www/arise/admin
Alias /arise         /var/www/arise/public</pre>
<h3>Reload Apache after config changes</h3>
<pre>sudo apache2ctl configtest &amp;&amp; sudo systemctl reload apache2</pre>

<h2>5. Database Schema</h2>
<p>Database location: <code><?= e($dbPath) ?></code></p>
<table>
  <tr><th>Table</th><th>Purpose</th></tr>
  <tr><td><code>students</code></td><td>Learner accounts (name, PIN hash, school, class, XP)</td></tr>
  <tr><td><code>modules</code></td><td>Learning modules (title, slug, icon, is_active)</td></tr>
  <tr><td><code>lessons</code></td><td>Lessons per module (type: interactive/video/pdf/text)</td></tr>
  <tr><td><code>lesson_progress</code></td><td>Per-learner lesson completion records</td></tr>
  <tr><td><code>quiz_questions</code></td><td>MCQ and essay questions per module</td></tr>
  <tr><td><code>quiz_attempts</code></td><td>Aggregate quiz attempt scores</td></tr>
  <tr><td><code>quiz_answers</code></td><td>Per-question answer records (feeds difficulty report)</td></tr>
  <tr><td><code>pretest_attempts</code></td><td>Pre/post test scores (test_type: 'pre' or 'post')</td></tr>
  <tr><td><code>certificates</code></td><td>Earned certificates per learner per module</td></tr>
  <tr><td><code>page_views</code></td><td>Session activity tracking (session_hash, module_id, viewed_at)</td></tr>
  <tr><td><code>forum_posts</code></td><td>Forum messages</td></tr>
  <tr><td><code>anon_questions</code></td><td>Private anonymous questions (Ask Us)</td></tr>
  <tr><td><code>challenges</code></td><td>Challenge definitions per module</td></tr>
  <tr><td><code>challenge_submissions</code></td><td>Learner challenge submissions</td></tr>
  <tr><td><code>behavioral_surveys</code></td><td>Post-module behavioral impact survey responses</td></tr>
  <tr><td><code>facilitator_sessions</code></td><td>Session codes generated by facilitators</td></tr>
  <tr><td><code>retention_tests</code></td><td>30-day follow-up knowledge retention tests</td></tr>
  <tr><td><code>audit_log</code></td><td>Admin action audit trail</td></tr>
</table>

<h2>6. Configuration File</h2>
<p>File: <code>/var/www/arise/includes/config.php</code></p>
<p>Key constants and functions defined here:</p>
<table>
  <tr><th>Identifier</th><th>Description</th></tr>
  <tr><td><code>DB_PATH</code></td><td>Absolute path to arise.db</td></tr>
  <tr><td><code>ARISE_VERSION</code></td><td>Application version string</td></tr>
  <tr><td><code>db()</code></td><td>Returns the singleton SQLite3 connection</td></tr>
  <tr><td><code>e($str)</code></td><td>htmlspecialchars() shorthand for output escaping</td></tr>
  <tr><td><code>getSessionHash()</code></td><td>Returns stable anonymous session identifier</td></tr>
  <tr><td><code>getStudentId()</code></td><td>Returns current logged-in student ID or null</td></tr>
  <tr><td><code>ariseAuditLog()</code></td><td>Writes to audit_log table</td></tr>
  <tr><td><code>trackSession()</code></td><td>Records page view to page_views table</td></tr>
</table>

<h2>7. Admin Panel</h2>
<p>Access at: <code>http://arise.local/arise/admin/</code></p>
<p>The admin panel uses session-based authentication. All pages are included via <code>admin/index.php</code> based on the <code>?p=</code> parameter. Sidebar navigation groups:</p>
<ul>
  <li><strong>Content:</strong> Modules, Quiz Builder, Challenges, Bulk Upload</li>
  <li><strong>People:</strong> Projects, Learners, Certificates, Anon Questions</li>
  <li><strong>Insights:</strong> Analytics, Reports</li>
  <li><strong>System:</strong> Admin Users, Facilitator, Audit Log, Recycle Bin</li>
</ul>

<h2>8. DataPost API</h2>
<p>The DataPost endpoint exports all platform data for offline analysis. It is used by M&amp;E officers to collect data from the device after a session.</p>
<div class="code">http://arise.local/arise/?p=datapost</div>
<p>Short URL alias: <code>http://arise.local/data</code></p>
<p>The response is a JSON object containing: projects, clusters, students (anonymised as LRN-XXXX), grades, pretests, progress, certificates, lesson_scores, xp, challenges, essays, forum, questions, analytics, and summary.</p>
<div class="tip">Learner names are replaced with anonymous reference codes (e.g. LRN-0042) in all DataPost exports to comply with data protection requirements.</div>
<h3>Ping action</h3>
<pre>GET /arise/?p=datapost&amp;action=ping</pre>
<p>Returns a quick summary: learner count, modules, quiz attempts, certs issued, etc. Use this to verify connectivity before a full export.</p>

<h2>9. Backup &amp; Recovery</h2>
<h3>Automatic backups</h3>
<p>ARISE runs a daily auto-backup. Backup files are stored in <code>/var/www/arise/data/backups/</code>. The admin panel's <strong>Backup</strong> page shows all backups and allows download or restore.</p>
<h3>Manual backup</h3>
<pre>cp /var/www/arise/data/arise.db /var/www/arise/data/backups/arise_$(date +%Y%m%d_%H%M%S).db</pre>
<h3>Restore from backup</h3>
<pre>cp /var/www/arise/data/backups/arise_YYYYMMDD_HHMMSS.db /var/www/arise/data/arise.db
sudo systemctl restart apache2</pre>
<div class="danger"><strong>Restoring overwrites all current data.</strong> Always create a new backup before restoring.</div>

<h2>10. Schema Migrations</h2>
<p>New features may require additional database tables or columns. Run migrations via the SQLite CLI:</p>
<pre>sqlite3 /var/www/arise/data/arise.db</pre>
<p>Key migrations added in v1.x:</p>
<pre>-- Behavioral survey responses
CREATE TABLE IF NOT EXISTS behavioral_surveys (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  student_id INTEGER, session_hash TEXT, module_id INTEGER,
  q1_changed INTEGER DEFAULT 0, q1_detail TEXT,
  q2_shared INTEGER DEFAULT 0, q2_detail TEXT,
  q3_confident INTEGER DEFAULT 0, q3_detail TEXT,
  submitted_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- Facilitator session codes
CREATE TABLE IF NOT EXISTS facilitator_sessions (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  facilitator_id INTEGER, cluster_name TEXT, school_name TEXT,
  session_code TEXT UNIQUE, is_active INTEGER DEFAULT 1,
  started_at DATETIME DEFAULT CURRENT_TIMESTAMP, ended_at DATETIME
);

-- 30-day retention tests
CREATE TABLE IF NOT EXISTS retention_tests (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  student_id INTEGER, session_hash TEXT, module_id INTEGER,
  score INTEGER, total INTEGER, percentage REAL,
  taken_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- Question competency tagging
ALTER TABLE quiz_questions ADD COLUMN competency TEXT;</pre>

<h2>11. Troubleshooting</h2>
<table>
  <tr><th>Problem</th><th>Likely Cause</th><th>Fix</th></tr>
  <tr><td>Blank page in admin</td><td>PHP error (SQLite column name mismatch)</td><td>Check Apache error log: <code>tail /var/log/apache2/arise_error.log</code></td></tr>
  <tr><td>arise.local not resolving</td><td>Avahi not running or hostname not set</td><td><code>sudo hostnamectl set-hostname arise &amp;&amp; sudo systemctl restart avahi-daemon</code></td></tr>
  <tr><td>"Site not safe" on LAN</td><td>HTTP redirect to HTTPS enabled</td><td>Check Apache :80 VirtualHost — ARISE must not have a RewriteRule pointing to HTTPS</td></tr>
  <tr><td>Uploads not loading</td><td>Wrong Alias order or permissions</td><td>Ensure <code>Alias /arise/uploads</code> appears before <code>Alias /arise</code> in config</td></tr>
  <tr><td>Admin login fails</td><td>Session not persisting</td><td>Check PHP session path is writable: <code>php -r "echo session_save_path();"</code></td></tr>
  <tr><td>Zero score on pre/post test</td><td>Question IDs not round-tripping correctly</td><td>Ensure pre_test.php extracts IDs from POST keys (preg_match <code>/^q(\d+)$/</code>)</td></tr>
</table>

<h2>12. Security Notes</h2>
<ul>
  <li>ARISE is designed for trusted LAN environments. The HTTP-only setup is intentional for ease of use in field settings.</li>
  <li>All user-supplied data is passed through <code>htmlspecialchars()</code> before output.</li>
  <li>Database writes use prepared statements with bound parameters.</li>
  <li>Admin session is protected by <code>$_SESSION['arise_admin_id']</code> — sessions expire on browser close.</li>
  <li>Student PINs are stored hashed (not plain text).</li>
  <li>DataPost exports anonymise all learner names — never export raw student names over the network.</li>
  <li>For deployments with internet connectivity, add SSL via Let's Encrypt and restrict admin access by IP.</li>
</ul>

<div style="margin-top:60px;padding-top:20px;border-top:2px solid #e5e7eb;font-size:.82rem;color:#6b7280;text-align:center;">
  ARISE Technical Manual &mdash; v<?= e($ver) ?> &mdash; <?= date('Y') ?>
</div>
</div>
</body>
</html>
