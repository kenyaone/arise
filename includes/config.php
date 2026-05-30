<?php
/**
 * ARISE - Configuration & Database
 * Offline Health Education Platform with DataPost
 */

// ============================================
// CONFIGURATION
// ============================================

define('ARISE_VERSION', '2.0.0');
define('ARISE_NAME', 'ARISE');
define('ARISE_FULL', 'Adolescent Reproductive Health Information Support and Empowerment');
define('DB_PATH', __DIR__ . '/../data/arise.db');
define('UPLOAD_PATH', __DIR__ . '/../data/uploads/');
define('UPLOAD_URL', '/arise/uploads/');
define('DATAPOST_PATH', __DIR__ . '/../data/datapost/');
define('CONTENT_PATH', __DIR__ . '/../data/content/');
define('LOGO_PATH', __DIR__ . '/../data/uploads/logos/');

// School config — set during first setup
define('DEFAULT_SCHOOL_ID', 'ARISE-SETUP-000');

// Cloud sync — pushes cluster/school data to live site after admin changes
define('CLOUD_SYNC_SECRET', 'arise_sync_k3nya_2026');
define('CLOUD_SYNC_URL',    'https://ariseci.org/arise/arise_cluster_receiver.php');

// Kenya county/cluster centroid coordinates used as a fast offline geocoder.
// Mirrors the table in locations.php so cluster_receiver writes the same values.
function countyCoords(): array {
    return [
        'Nairobi'=>[-1.2864,36.8172], 'Kiambu'=>[-1.0536,36.6710],
        'Uasin Gishu'=>[0.5204,35.2698], 'UASIN GISHU'=>[0.5204,35.2698], 'Eldoret'=>[0.5204,35.2698],
        'Nandi'=>[0.2028,35.1045], 'Kisumu'=>[-0.0917,34.7680],
        'Siaya'=>[0.0625,34.2422], 'Siaya-Kisumu'=>[-0.0500,34.5000],
        'Kakamega'=>[0.2827,34.7519], 'Vihiga'=>[0.0076,34.7234],
        'Vihiga-Nandi-Kisumu'=>[0.1000,34.8500], 'Busia'=>[0.4610,34.1110],
        'Homa Bay'=>[-0.5180,34.4570], 'Migori'=>[-1.0634,34.4731],
        'Narok'=>[-1.0834,35.8730], 'Migori-Narok'=>[-1.0634,34.4731],
        'Narok-Kajiado'=>[-1.0834,35.8730], 'Kajiado'=>[-1.8520,36.7760],
        'Machakos'=>[-1.5177,37.2634], 'Kitui'=>[-1.3671,38.0106],
        'Kitui-Machakos'=>[-1.3671,38.0106], 'Makueni'=>[-1.8018,37.6209],
        'Meru'=>[0.0476,37.6493], 'Embu'=>[-0.5330,37.4580],
        'Tharaka Nithi'=>[-0.2960,37.9570], 'Tharaka Nithi-Embu'=>[-0.5330,37.4580],
        'Nakuru'=>[-0.3031,36.0800], 'Laikipia'=>[0.3600,36.7810],
        'Nyeri'=>[-0.4167,36.9481], "Murang'a"=>[-0.7833,37.0370],
        'Kirinyaga'=>[-0.6580,37.3310], 'Nyandarua'=>[-0.1110,36.3610],
        'Kericho'=>[-0.3690,35.2840], 'Bomet'=>[-0.7820,35.3420],
        'Kisii'=>[-0.6773,34.7796], 'Nyamira'=>[-0.5670,34.9370],
        'Trans Nzoia'=>[1.0570,35.0000], 'Elgeyo Marakwet'=>[0.7980,35.5060],
        'Baringo'=>[0.4640,35.7510], 'West Pokot'=>[1.6230,35.0940],
        'Turkana'=>[3.1190,35.5960], 'Samburu'=>[1.2160,36.6950],
        'Isiolo'=>[0.3540,37.5820], 'Mombasa'=>[-4.0435,39.6682],
        'Kilifi'=>[-3.6300,39.8500], 'Kwale'=>[-4.1750,39.4520],
        'Taita Taveta'=>[-3.3160,38.4800], 'Yala'=>[0.1028,34.3437],
        'Uganda'=>[1.3733,32.2903], 'kenya'=>[-0.0236,37.9062], 'tanzania'=>[-6.3690,34.8888],
    ];
}

// Geocode a single query via Nominatim (OpenStreetMap). Returns [lat,lng] or null.
// Respects Nominatim's usage policy: identifying UA, max 1 req/sec.
function nominatimGeocode(string $query): ?array {
    if (!function_exists('curl_init')) return null;
    $url = 'https://nominatim.openstreetmap.org/search?format=json&limit=1&q=' . rawurlencode($query);
    $ch  = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_USERAGENT      => 'ARISE-Education-Platform/1.0 (contact: admin@ariseci.org)',
    ]);
    $resp = curl_exec($ch);
    curl_close($ch);
    if (!$resp) return null;
    $arr = json_decode($resp, true);
    if (!is_array($arr) || empty($arr[0]['lat']) || empty($arr[0]['lon'])) return null;
    return [(float)$arr[0]['lat'], (float)$arr[0]['lon']];
}

// Fill missing lat/lng on schools and clusters using countyCoords first, then
// Nominatim for anything still unresolved. Limits Nominatim calls per run to
// avoid request timeouts; whatever isn't filled this run gets retried next sync.
function backfillSchoolCoords(int $maxOnlineLookups = 5): array {
    $d = db();
    $coords = countyCoords();
    $stats  = ['county_filled' => 0, 'online_filled' => 0, 'still_missing' => 0];

    // Pass 1: county-based lookup (fast, offline).
    foreach ($coords as $county => $xy) {
        $cE = SQLite3::escapeString($county);
        $d->exec("UPDATE schools  SET lat={$xy[0]},lng={$xy[1]} WHERE county='$cE' AND (lat IS NULL OR lat=0)");
        $stats['county_filled'] += $d->changes();
        $d->exec("UPDATE clusters SET lat={$xy[0]},lng={$xy[1]} WHERE name  ='$cE' AND (lat IS NULL OR lat=0)");
    }

    // Pass 2: online lookup for the stragglers, capped to keep sync responsive.
    $r = $d->query("SELECT id, name, COALESCE(county,'') AS county FROM schools WHERE (lat IS NULL OR lat=0) AND is_active=1 ORDER BY id LIMIT $maxOnlineLookups");
    while ($row = $r->fetchArray(SQLITE3_ASSOC)) {
        $q  = trim($row['name'] . ', ' . $row['county'] . ', Kenya', ", ");
        $xy = nominatimGeocode($q);
        if ($xy === null && $row['county'] !== '') {
            // Try county alone as a last resort.
            $xy = nominatimGeocode($row['county'] . ', Kenya');
        }
        if ($xy !== null) {
            $stmt = $d->prepare("UPDATE schools SET lat=:la, lng=:ln WHERE id=:id");
            $stmt->bindValue(':la', $xy[0], SQLITE3_FLOAT);
            $stmt->bindValue(':ln', $xy[1], SQLITE3_FLOAT);
            $stmt->bindValue(':id', (int)$row['id'], SQLITE3_INTEGER);
            $stmt->execute();
            $stats['online_filled']++;
        }
        usleep(1100000); // ~1.1s — respect Nominatim's 1 req/sec policy
    }

    $stats['still_missing'] = (int)$d->querySingle("SELECT COUNT(*) FROM schools WHERE (lat IS NULL OR lat=0) AND is_active=1");
    return $stats;
}

// Pushes the local clusters + schools definition tables to the cloud receiver
// so locations.php's INNER JOIN on schools/clusters resolves new entries.
// Returns ['ok'=>bool, 'clusters'=>int, 'schools'=>int, 'geocode'=>array, 'error'=>?string].
function pushClusterDefinitions(): array {
    if (!defined('CLOUD_SYNC_URL') || !defined('CLOUD_SYNC_SECRET')) {
        return ['ok' => false, 'error' => 'CLOUD_SYNC_URL/SECRET not configured'];
    }
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'error' => 'php-curl not installed'];
    }

    $geocode = backfillSchoolCoords();

    $d = db();
    $clusters = [];
    $r = $d->query("SELECT id, name, COALESCE(password_hash,'') AS hash, lat, lng FROM clusters ORDER BY id");
    while ($row = $r->fetchArray(SQLITE3_ASSOC)) $clusters[] = $row;

    $schools = [];
    $r = $d->query("SELECT name, COALESCE(county,'') AS county, cluster_id, COALESCE(password_hash,'') AS hash, COALESCE(is_active,1) AS active, lat, lng FROM schools");
    while ($row = $r->fetchArray(SQLITE3_ASSOC)) $schools[] = $row;

    $ch = curl_init(CLOUD_SYNC_URL);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => ['secret' => CLOUD_SYNC_SECRET, 'payload' => json_encode(['clusters' => $clusters, 'schools' => $schools])],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
    ]);
    $resp = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($resp === false || $resp === '') return ['ok' => false, 'error' => 'Could not reach cluster receiver', 'geocode' => $geocode];
    $j = json_decode($resp, true);
    if (!is_array($j) || empty($j['ok'])) {
        return ['ok' => false, 'error' => $j['error'] ?? "Unexpected response (HTTP $code)", 'geocode' => $geocode];
    }
    return ['ok' => true, 'clusters' => (int)($j['clusters'] ?? 0), 'schools' => (int)($j['schools'] ?? 0), 'geocode' => $geocode];
}

function getLogoUrl(): ?string {
    foreach (['png','jpg','gif','webp','svg'] as $ext) {
        $path = LOGO_PATH . 'school_logo.' . $ext;
        if (file_exists($path)) {
            return '/arise/uploads/logos/school_logo.' . $ext . '?v=' . filemtime($path);
        }
    }
    return null;
}

// ============================================
// DATABASE CONNECTION
// ============================================

function db(): SQLite3 {
    static $db = null;
    if ($db === null) {
        $dbDir = dirname(DB_PATH);
        if (!is_dir($dbDir)) {
            mkdir($dbDir, 0755, true);
        }
        $db = new SQLite3(DB_PATH);
        $db->enableExceptions(true);
        $db->exec('PRAGMA journal_mode=WAL');
        $db->exec('PRAGMA foreign_keys=ON');
    }
    return $db;
}

function initDatabase(): void {
    $schema = file_get_contents(__DIR__ . '/../sql/schema.sql');
    // Split by semicolons and execute each statement individually
    $statements = explode(';', $schema);
    foreach ($statements as $stmt) {
        $stmt = trim($stmt);
        if (!empty($stmt) && !str_starts_with($stmt, '--')) {
            try {
                db()->exec($stmt);
            } catch (Exception $e) {
                // Ignore "already exists" errors on re-init
            }
        }
    }
}

// ============================================
// SESSION & TRACKING (Anonymous)
// ============================================

function getSessionHash(): string {
    if (session_status() === PHP_SESSION_NONE) {
        // Extend server-side session lifetime (cPanel default is only 24 min)
        @ini_set('session.gc_maxlifetime', 86400 * 30);
        // Long-lived PHP session cookie (30 days)
        session_set_cookie_params([
            'lifetime' => 86400 * 30,
            'path'     => '/arise/',
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }
    if (!isset($_SESSION['arise_hash'])) {
        // Restore from persistent cookie if available (survives PHP session expiry)
        if (!empty($_COOKIE['arise_ph'])) {
            $_SESSION['arise_hash'] = $_COOKIE['arise_ph'];
        } else {
            $_SESSION['arise_hash'] = substr(md5(uniqid(mt_rand(), true)), 0, 16);
        }
    }
    // Keep persistent backup cookie in sync (refresh expiry each request)
    $h = $_SESSION['arise_hash'];
    if (!headers_sent() && (empty($_COOKIE['arise_ph']) || $_COOKIE['arise_ph'] !== $h)) {
        setcookie('arise_ph', $h, [
            'expires'  => time() + 86400 * 30,
            'path'     => '/arise/',
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
    return $h;
}

function getDeviceHash(): string {
    $raw = ($_SERVER['HTTP_USER_AGENT'] ?? 'unknown') . ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    return substr(md5($raw), 0, 16);
}

function trackSession(): void {
    $hash = getSessionHash();
    $device = getDeviceHash();
    
    $stmt = db()->prepare('INSERT OR IGNORE INTO sessions (session_hash, device_hash) VALUES (:hash, :device)');
    $stmt->bindValue(':hash', $hash);
    $stmt->bindValue(':device', $device);
    $stmt->execute();
    
    // Update last active
    $stmt = db()->prepare('UPDATE sessions SET last_active = CURRENT_TIMESTAMP WHERE session_hash = :hash');
    $stmt->bindValue(':hash', $hash);
    $stmt->execute();
}

function trackPageView(string $pageType, ?string $pageSlug = null, ?int $moduleId = null): void {
    $stmt = db()->prepare('INSERT INTO page_views (session_hash, page_type, page_slug, module_id) VALUES (:hash, :type, :slug, :mod)');
    $stmt->bindValue(':hash', getSessionHash());
    $stmt->bindValue(':type', $pageType);
    $stmt->bindValue(':slug', $pageSlug);
    $stmt->bindValue(':mod', $moduleId);
    $stmt->execute();
    
    updateDailyStats();
}

function updateDailyStats(): void {
    $today = date('Y-m-d');
    
    $devices = db()->querySingle("SELECT COUNT(DISTINCT device_hash) FROM sessions WHERE DATE(started_at) = '$today'");
    $sessions = db()->querySingle("SELECT COUNT(*) FROM sessions WHERE DATE(started_at) = '$today'");
    $views = db()->querySingle("SELECT COUNT(*) FROM page_views WHERE DATE(viewed_at) = '$today'");
    $quizzes = db()->querySingle("SELECT COUNT(*) FROM quiz_attempts WHERE DATE(completed_at) = '$today'");
    $questions = db()->querySingle("SELECT COUNT(*) FROM anonymous_questions WHERE DATE(submitted_at) = '$today'");
    
    $stmt = db()->prepare('INSERT OR REPLACE INTO daily_stats (stat_date, unique_devices, total_sessions, total_page_views, total_quiz_attempts, total_questions_asked) VALUES (:date, :devices, :sessions, :views, :quizzes, :questions)');
    $stmt->bindValue(':date', $today);
    $stmt->bindValue(':devices', $devices);
    $stmt->bindValue(':sessions', $sessions);
    $stmt->bindValue(':views', $views);
    $stmt->bindValue(':quizzes', $quizzes);
    $stmt->bindValue(':questions', $questions);
    $stmt->execute();
}

// ============================================
// HELPER FUNCTIONS
// ============================================

function getModules(): array {
    $result = db()->query('SELECT * FROM modules WHERE is_active = 1 ORDER BY sort_order');
    $modules = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $modules[] = $row;
    }
    return $modules;
}

function getModule(string $slug): ?array {
    $stmt = db()->prepare('SELECT * FROM modules WHERE slug = :slug AND is_active = 1');
    $stmt->bindValue(':slug', $slug);
    $result = $stmt->execute();
    $row = $result->fetchArray(SQLITE3_ASSOC);
    return $row ?: null;
}

function getLessons(int $moduleId): array {
    $stmt = db()->prepare('SELECT * FROM lessons WHERE module_id = :id AND is_active = 1 ORDER BY sort_order');
    $stmt->bindValue(':id', $moduleId);
    $result = $stmt->execute();
    $lessons = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $lessons[] = $row;
    }
    return $lessons;
}

function getQuizQuestions(int $moduleId): array {
    $stmt = db()->prepare('SELECT * FROM quiz_questions WHERE module_id = :id ORDER BY sort_order');
    $stmt->bindValue(':id', $moduleId);
    $result = $stmt->execute();
    $questions = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $questions[] = $row;
    }
    return $questions;
}

function getSchoolConfig(): ?array {
    $result = db()->query('SELECT * FROM datapost_config LIMIT 1');
    $row = $result->fetchArray(SQLITE3_ASSOC);
    return $row ?: null;
}

function e(string $str): string {
    return htmlspecialchars($str, ENT_QUOTES, 'UTF-8');
}

function isSetupComplete(): bool {
    try {
        $config = getSchoolConfig();
        return $config !== null;
    } catch (Exception $e) {
        return false;
    }
}

// ============================================
// PERMISSIONS
// ============================================

function getUserPermissions(int $userId): array {
    $stmt = db()->prepare('SELECT permission FROM admin_permissions WHERE user_id = :uid');
    $stmt->bindValue(':uid', $userId);
    $result = $stmt->execute();
    $perms = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $perms[] = $row['permission'];
    }
    return $perms;
}

function hasPermission(string $permission): bool {
    if (session_status() === PHP_SESSION_NONE) session_start();
    $role = $_SESSION['arise_role'] ?? '';
    if ($role === 'superadmin') return true;
    $perms = $_SESSION['arise_permissions'] ?? [];
    return in_array($permission, $perms);
}

function getAllPermissions(): array {
    return [
        'dashboard' => ['label' => '📊 Dashboard', 'desc' => 'View usage statistics'],
        'content_view' => ['label' => '📖 View Content', 'desc' => 'Browse modules and lessons'],
        'content_manage' => ['label' => '📝 Manage Content', 'desc' => 'Create/edit modules, lessons, quizzes'],
        'questions_view' => ['label' => '❓ View Questions', 'desc' => 'See anonymous student questions'],
        'questions_answer' => ['label' => '💬 Answer Questions', 'desc' => 'Respond to anonymous questions'],
        'essays_grade' => ['label' => '✍️ Grade Essays', 'desc' => 'Review and grade essay responses'],
        'students_view' => ['label' => '👁️ View Students', 'desc' => 'See registered student list'],
        'students_manage' => ['label' => '👥 Manage Students', 'desc' => 'Add/edit/delete students'],
        'users_manage' => ['label' => '🔑 Manage Users', 'desc' => 'Create/edit admin accounts'],
        'setup' => ['label' => '⚙️ School Setup', 'desc' => 'Configure school and server'],
        'datapost' => ['label' => '📡 DataPost', 'desc' => 'Access data pickup/delivery'],
        'backup' => ['label' => '💾 Backups', 'desc' => 'Download and manage backups'],
    ];
}

// ============================================
// STUDENT FUNCTIONS
// ============================================

function getStudentBySession(): ?array {
    $hash = getSessionHash();
    $stmt = db()->prepare('SELECT * FROM students WHERE session_hash = :hash AND is_active = 1');
    $stmt->bindValue(':hash', $hash);
    $row = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
    if ($row) {
        // Keep arise_uid cookie alive (refresh expiry on every request)
        $sid = $row['id'];
        if (!headers_sent() && (empty($_COOKIE['arise_uid']) || (int)$_COOKIE['arise_uid'] !== $sid)) {
            setcookie('arise_uid', $sid, ['expires'=>time()+86400*30,'path'=>'/arise/','httponly'=>true,'samesite'=>'Lax']);
        }
        $_SESSION['arise_student_id'] = $sid;
        return $row;
    }

    // Fallback: recover by student_id from session or persistent cookie (survives any session loss)
    $sid = (int)($_SESSION['arise_student_id'] ?? 0);
    if ($sid <= 0) $sid = (int)($_COOKIE['arise_uid'] ?? 0);
    if ($sid > 0) {
        $stmt2 = db()->prepare('SELECT * FROM students WHERE id = :id AND is_active = 1');
        $stmt2->bindValue(':id', $sid);
        $row2 = $stmt2->execute()->fetchArray(SQLITE3_ASSOC);
        if ($row2) {
            // Re-sync session hash and refresh both persistent cookies
            try { db()->exec("UPDATE students SET session_hash='".SQLite3::escapeString($hash)."' WHERE id=$sid"); } catch(\Exception $e) {}
            if (!headers_sent()) setcookie('arise_uid', $sid, ['expires'=>time()+86400*30,'path'=>'/arise/','httponly'=>true,'samesite'=>'Lax']);
            $_SESSION['arise_student_id'] = $sid;
            return $row2;
        }
    }
    return null;
}

function registerStudent(string $name, string $school, string $class): int {
    $hash = getSessionHash();
    $stmt = db()->prepare('INSERT INTO students (full_name, school_name, class_name, session_hash) VALUES (:name, :school, :class, :hash)');
    $stmt->bindValue(':name', $name);
    $stmt->bindValue(':school', $school);
    $stmt->bindValue(':class', $class);
    $stmt->bindValue(':hash', $hash);
    $stmt->execute();
    $studentId = db()->lastInsertRowID();
    
    // Link session to student
    $stmt = db()->prepare('UPDATE sessions SET student_id = :sid WHERE session_hash = :hash');
    $stmt->bindValue(':sid', $studentId);
    $stmt->bindValue(':hash', $hash);
    $stmt->execute();
    
    return $studentId;
}

function getStudentId(): ?int {
    $student = getStudentBySession();
    return $student ? $student['id'] : null;
}

// ============================================
// BACKUP FUNCTIONS
// ============================================

function generateStudentCSV(): string {
    $result = db()->query("SELECT s.id, s.full_name, s.school_name, s.class_name, s.registered_at, s.last_seen,
        (SELECT COUNT(*) FROM quiz_attempts qa WHERE qa.student_id = s.id) AS quiz_count,
        (SELECT ROUND(AVG(qa.percentage),1) FROM quiz_attempts qa WHERE qa.student_id = s.id) AS avg_score,
        (SELECT COUNT(*) FROM essay_responses er WHERE er.student_id = s.id) AS essay_count,
        (SELECT COUNT(*) FROM certificates c WHERE c.student_id = s.id) AS cert_count
        FROM students s WHERE s.is_active = 1 ORDER BY s.full_name");
    
    $csv = "ID,Full Name,School,Class,Registered,Last Seen,Quizzes Taken,Avg Score %,Essays Submitted,Certificates\n";
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $csv .= implode(',', [
            $row['id'],
            '"' . str_replace('"', '""', $row['full_name']) . '"',
            '"' . str_replace('"', '""', $row['school_name'] ?? '') . '"',
            '"' . str_replace('"', '""', $row['class_name'] ?? '') . '"',
            $row['registered_at'],
            $row['last_seen'],
            $row['quiz_count'] ?? 0,
            $row['avg_score'] ?? 0,
            $row['essay_count'] ?? 0,
            $row['cert_count'] ?? 0
        ]) . "\n";
    }
    return $csv;
}

function generateFullBackupCSV(): string {
    $csv = "=== ARISE FULL BACKUP ===\n";
    $csv .= "Generated: " . date('Y-m-d H:i:s') . "\n\n";
    
    // Students
    $csv .= "=== STUDENTS ===\n";
    $csv .= generateStudentCSV();
    
    // Quiz attempts
    $csv .= "\n=== QUIZ ATTEMPTS ===\n";
    $csv .= "Student,Module,Score,Total,Percentage,Date\n";
    $result = db()->query("SELECT s.full_name, m.title, qa.score, qa.total_questions, qa.percentage, qa.completed_at 
        FROM quiz_attempts qa LEFT JOIN students s ON qa.student_id = s.id JOIN modules m ON qa.module_id = m.id ORDER BY qa.completed_at DESC");
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $csv .= '"' . ($row['full_name'] ?? 'Anonymous') . '","' . $row['title'] . '",' . $row['score'] . ',' . $row['total_questions'] . ',' . $row['percentage'] . ',' . $row['completed_at'] . "\n";
    }
    
    // Essay responses
    $csv .= "\n=== ESSAY RESPONSES ===\n";
    $csv .= "Student,Question,Response,Words,Grade,Feedback,Date\n";
    $result = db()->query("SELECT s.full_name, qq.question, er.response_text, er.word_count, er.grade, er.feedback, er.submitted_at 
        FROM essay_responses er LEFT JOIN students s ON er.student_id = s.id JOIN quiz_questions qq ON er.question_id = qq.id ORDER BY er.submitted_at DESC");
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $csv .= '"' . ($row['full_name'] ?? 'Anonymous') . '","' . str_replace('"','""',$row['question']) . '","' . str_replace('"','""',substr($row['response_text'],0,200)) . '",' . $row['word_count'] . ',' . ($row['grade'] ?? 'Ungraded') . ',"' . str_replace('"','""',$row['feedback'] ?? '') . '",' . $row['submitted_at'] . "\n";
    }
    
    return $csv;
}

function runAutoBackup(): void {
    $config = getSchoolConfig();
    if (!$config || !($config['auto_backup_enabled'] ?? 1)) return;
    
    $backupDir = $config['auto_backup_path'] ?? DATAPOST_PATH . 'backups/';
    if (!is_dir($backupDir)) mkdir($backupDir, 0755, true);
    
    $filename = 'arise_backup_' . date('Y-m-d') . '.csv';
    $filepath = $backupDir . $filename;
    
    // Only backup once per day
    if (file_exists($filepath)) return;
    
    $csv = generateFullBackupCSV();
    file_put_contents($filepath, $csv);
    
    // Also copy the SQLite database
    $dbBackup = $backupDir . 'arise_db_' . date('Y-m-d') . '.sqlite';
    if (!file_exists($dbBackup)) {
        copy(DB_PATH, $dbBackup);
    }
    
    // Clean backups older than 30 days
    $files = glob($backupDir . 'arise_*');
    foreach ($files as $file) {
        if (filemtime($file) < time() - 30 * 86400) unlink($file);
    }
}

// Initialize DB on first load
try {
    if (!file_exists(DB_PATH)) {
        initDatabase();
    }
} catch (Exception $e) {
    // Will be initialized on first proper request
}

// ============================================
// GAMIFICATION ENGINE
// ============================================

function awardXP(int $studentId, int $xp, string $action, string $desc): void {
    if ($studentId <= 0 || $xp <= 0) return;

    // Init XP row if needed
    db()->exec("INSERT OR IGNORE INTO student_xp (student_id,xp_points,level,streak_days,last_activity) VALUES ($studentId,0,1,0,'" . date('Y-m-d') . "')");

    // Add XP
    db()->exec("UPDATE student_xp SET xp_points=xp_points+$xp, updated_at=CURRENT_TIMESTAMP WHERE student_id=$studentId");

    // Log it
    $stmt = db()->prepare('INSERT INTO xp_log (student_id,action,xp_earned,description) VALUES (:s,:a,:x,:d)');
    $stmt->bindValue(':s',$studentId); $stmt->bindValue(':a',$action);
    $stmt->bindValue(':x',$xp); $stmt->bindValue(':d',$desc);
    $stmt->execute();

    // Update level (every 200*level XP = new level)
    $row = db()->querySingle("SELECT xp_points,level FROM student_xp WHERE student_id=$studentId", true);
    if ($row) {
        $newLevel = max(1, intval(sqrt($row['xp_points']/100)) + 1);
        if ($newLevel > $row['level']) {
            db()->exec("UPDATE student_xp SET level=$newLevel WHERE student_id=$studentId");
        }
    }

    // Update streak
    $row2 = db()->querySingle("SELECT last_activity,streak_days FROM student_xp WHERE student_id=$studentId", true);
    if ($row2) {
        $today = date('Y-m-d');
        $yesterday = date('Y-m-d', strtotime('-1 day'));
        $last = $row2['last_activity'];
        if ($last === $today) {
            // same day — no change
        } elseif ($last === $yesterday) {
            // consecutive day
            db()->exec("UPDATE student_xp SET streak_days=streak_days+1, last_activity='$today' WHERE student_id=$studentId");
        } else {
            // streak broken
            db()->exec("UPDATE student_xp SET streak_days=1, last_activity='$today' WHERE student_id=$studentId");
        }
    }

    checkBadges($studentId);
}

function checkBadges(int $studentId): void {
    $xpRow = db()->querySingle("SELECT * FROM student_xp WHERE student_id=$studentId", true);
    if (!$xpRow) return;

    $streak   = intval($xpRow['streak_days'] ?? 0);
    $lessons  = intval($xpRow['total_lessons_completed'] ?? 0);
    $quizPass = intval($xpRow['total_quizzes_passed'] ?? 0);
    $certs    = db()->querySingle("SELECT COUNT(*) FROM certificates WHERE student_id=$studentId") ?? 0;
    $bestScore= db()->querySingle("SELECT MAX(percentage) FROM quiz_attempts WHERE student_id=$studentId") ?? 0;
    $forum    = db()->querySingle("SELECT COUNT(*) FROM forum_posts WHERE student_id=$studentId AND is_hidden=0") ?? 0;
    $questions= db()->querySingle("SELECT COUNT(*) FROM anonymous_questions WHERE is_answered=0") ?? 0;

    $conditions = [
        'first_lesson'  => $lessons >= 1,
        'first_pass'    => $quizPass >= 1,
        'first_cert'    => $certs >= 1,
        'streak_3'      => $streak >= 3,
        'streak_7'      => $streak >= 7,
        'lessons_5'     => $lessons >= 5,
        'lessons_10'    => $lessons >= 10,
        'quizzes_5'     => $quizPass >= 5,
        'perfect_score' => $bestScore >= 90,
        'forum_post'    => $forum >= 1,
    ];

    foreach ($conditions as $code => $met) {
        if (!$met) continue;
        $badge = db()->querySingle("SELECT id,xp_reward FROM badges WHERE code='".SQLite3::escapeString($code)."'", true);
        if (!$badge) continue;
        $already = db()->querySingle("SELECT id FROM student_badges WHERE student_id=$studentId AND badge_id={$badge['id']}");
        if ($already) continue;
        // Award badge
        $stmt = db()->prepare('INSERT OR IGNORE INTO student_badges (student_id,badge_id) VALUES (:s,:b)');
        $stmt->bindValue(':s',$studentId); $stmt->bindValue(':b',$badge['id']);
        $stmt->execute();
        // Award XP for badge
        $xp = intval($badge['xp_reward']);
        if ($xp > 0) awardXP($studentId, $xp, 'badge', 'Badge earned: '.$code);
    }
}

// ── v2.1 helpers ──────────────────────────────────────────────────────────────

function getModuleQuizCount(int $moduleId): int {
    return (int)(db()->querySingle("SELECT COUNT(*) FROM quiz_questions WHERE module_id=$moduleId") ?? 0);
}

function ariseAuditLog(string $action, string $targetType='', int $targetId=0, string $details=''): void {
    $adminId = $_SESSION['arise_admin_id'] ?? 0;
    $adminName = $_SESSION['arise_admin_name'] ?? 'Unknown';
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $st = db()->prepare("INSERT INTO arise_audit_log (admin_id,admin_name,action,target_type,target_id,details,ip_address) VALUES (:ai,:an,:a,:tt,:ti,:d,:ip)");
    $st->bindValue(':ai',$adminId); $st->bindValue(':an',$adminName);
    $st->bindValue(':a',$action); $st->bindValue(':tt',$targetType);
    $st->bindValue(':ti',$targetId); $st->bindValue(':d',$details); $st->bindValue(':ip',$ip);
    try { $st->execute(); } catch(\Exception $e) {}
}

function getStudentNotifications(int $studentId): array {
    if (!$studentId) return [];
    $notifs = [];
    // Unread essay feedback
    $essays = db()->query("SELECT er.id,q.question,er.grade,er.feedback FROM essay_responses er JOIN quiz_questions q ON er.question_id=q.id WHERE er.student_id=$studentId AND er.is_graded=1 AND (er.notified IS NULL OR er.notified=0)");
    while ($e=$essays->fetchArray(SQLITE3_ASSOC)) {
        $notifs[] = ['type'=>'essay','text'=>'Your essay was graded: '.substr($e['question'],0,50).'…','score'=>$e['grade'],'id'=>$e['id']];
    }
    // Anonymous question answered
    $qs = db()->query("SELECT id,question FROM anonymous_questions WHERE session_hash='".SQLite3::escapeString(getSessionHash())."' AND is_answered=1 AND (notified IS NULL OR notified=0)");
    while ($q=$qs->fetchArray(SQLITE3_ASSOC)) {
        $notifs[] = ['type'=>'question','text'=>'Your question was answered: '.substr($q['question'],0,50).'…','id'=>$q['id']];
    }
    return $notifs;
}

function markNotificationsRead(int $studentId): void {
    if (!$studentId) return;
    db()->exec("UPDATE essay_responses SET notified=1 WHERE student_id=$studentId AND is_graded=1");
    db()->exec("UPDATE anonymous_questions SET notified=1 WHERE session_hash='".SQLite3::escapeString(getSessionHash())."' AND is_answered=1");
}

function syncClustersToCloud(): void {
    if (!CLOUD_SYNC_URL) return;
    try {
        // Resolve device_id (cached by cloud_push.php at /etc/arise_device_id, world-readable)
        $deviceId = '';
        if (is_readable('/etc/arise_device_id')) {
            $deviceId = trim((string)@file_get_contents('/etc/arise_device_id'));
        }
        if ($deviceId === '') {
            $mac = '';
            $out = (string)@shell_exec('ip -o link show 2>/dev/null');
            foreach (explode("\n", $out) as $line) {
                if (strpos($line, 'lo:') !== false) continue;
                if (preg_match('/link\/ether\s+([0-9a-f:]{17})/i', $line, $m)) {
                    $mac = strtoupper(str_replace(':', '', $m[1]));
                    break;
                }
            }
            if ($mac === '') $mac = strtoupper(md5(gethostname()));
            $deviceId = 'ARISE-' . $mac;
        }

        // Master vs clone — clones must not push cluster topology
        $isMaster = file_exists('/etc/arise_cluster_master');

        $clusters = [];
        if ($isMaster) {
            $r = db()->query("SELECT id, name, password_hash, lat, lng FROM clusters ORDER BY id");
            while ($row = $r->fetchArray(SQLITE3_ASSOC)) {
                $clusters[] = [
                    'id'   => (int)$row['id'],
                    'name' => $row['name'],
                    'hash' => $row['password_hash'],
                    'lat'  => $row['lat'],
                    'lng'  => $row['lng'],
                ];
            }
        }

        $schools = [];
        $r = db()->query("SELECT name, county, cluster_id, is_active, password_hash, lat, lng FROM schools");
        while ($row = $r->fetchArray(SQLITE3_ASSOC)) {
            $schools[] = [
                'name'       => $row['name'],
                'county'     => $row['county'] ?? '',
                'cluster_id' => $row['cluster_id'] ? (int)$row['cluster_id'] : null,
                'active'     => (int)$row['is_active'],
                'hash'       => $row['password_hash'] ?? '',
                'lat'        => $row['lat'],
                'lng'        => $row['lng'],
                'device_id'  => $deviceId,
            ];
        }

        $data = compact('schools', 'deviceId');
        if ($isMaster) $data['clusters'] = $clusters;

        $ctx = stream_context_create(['http' => [
            'method'        => 'POST',
            'header'        => 'Content-Type: application/x-www-form-urlencoded',
            'content'       => http_build_query(['secret' => CLOUD_SYNC_SECRET, 'payload' => json_encode($data)]),
            'timeout'       => 5,
            'ignore_errors' => true,
        ]]);
        @file_get_contents(CLOUD_SYNC_URL, false, $ctx);
    } catch (\Throwable $e) {}
}
