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
define('DATAPOST_PATH', __DIR__ . '/../data/datapost/');
define('CONTENT_PATH', __DIR__ . '/../data/content/');
define('LOGO_PATH', __DIR__ . '/../data/uploads/logos/');

// School config — set during first setup
define('DEFAULT_SCHOOL_ID', 'ARISE-SETUP-000');

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
    if ($row) return $row;

    // Fallback: recover by student_id from session or persistent cookie (survives any session loss)
    $sid = (int)($_SESSION['arise_student_id'] ?? 0);
    if ($sid <= 0) $sid = (int)($_COOKIE['arise_uid'] ?? 0);
    if ($sid > 0) {
        $stmt2 = db()->prepare('SELECT * FROM students WHERE id = :id AND is_active = 1');
        $stmt2->bindValue(':id', $sid);
        $row2 = $stmt2->execute()->fetchArray(SQLITE3_ASSOC);
        if ($row2) {
            // Re-sync session hash and refresh persistent cookie
            try { db()->exec("UPDATE students SET session_hash='".SQLite3::escapeString($hash)."' WHERE id=$sid"); } catch(\Exception $e) {}
            if (!headers_sent()) setcookie('arise_uid', $sid, ['expires'=>time()+86400*30,'path'=>'/arise/','httponly'=>true,'samesite'=>'Lax']);
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
