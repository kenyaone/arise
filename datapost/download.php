<?php
/**
 * DataPost Download — Generates and serves JSON data bundle
 */

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /datapost/?action=pickup');
    exit;
}

$courierName = trim($_POST['courier_name'] ?? '');
$courierEmail = trim($_POST['courier_email'] ?? '');
$dateFrom = $_POST['date_from'] ?? date('Y-m-01');
$dateTo = $_POST['date_to'] ?? date('Y-m-d');

if (empty($courierName) || empty($courierEmail)) {
    header('Location: /datapost/?action=pickup');
    exit;
}

$school = getSchoolConfig();
$schoolId = $school ? $school['school_id'] : DEFAULT_SCHOOL_ID;
$schoolName = $school ? $school['school_name'] : 'Unconfigured';

// ============================================
// GATHER ALL DATA
// ============================================

// Summary stats
$totalSessions = db()->querySingle("SELECT COUNT(*) FROM sessions WHERE DATE(started_at) BETWEEN '$dateFrom' AND '$dateTo'") ?? 0;
$uniqueDevices = db()->querySingle("SELECT COUNT(DISTINCT device_hash) FROM sessions WHERE DATE(started_at) BETWEEN '$dateFrom' AND '$dateTo'") ?? 0;
$totalPageViews = db()->querySingle("SELECT COUNT(*) FROM page_views WHERE DATE(viewed_at) BETWEEN '$dateFrom' AND '$dateTo'") ?? 0;
$totalQuizzes = db()->querySingle("SELECT COUNT(*) FROM quiz_attempts WHERE DATE(completed_at) BETWEEN '$dateFrom' AND '$dateTo'") ?? 0;
$avgQuizScore = db()->querySingle("SELECT ROUND(AVG(percentage), 1) FROM quiz_attempts WHERE DATE(completed_at) BETWEEN '$dateFrom' AND '$dateTo'") ?? 0;
$totalQuestions = db()->querySingle("SELECT COUNT(*) FROM anonymous_questions WHERE DATE(submitted_at) BETWEEN '$dateFrom' AND '$dateTo'") ?? 0;

// Daily stats
$dailyResult = db()->query("SELECT * FROM daily_stats WHERE stat_date BETWEEN '$dateFrom' AND '$dateTo' ORDER BY stat_date");
$dailyStats = [];
while ($row = $dailyResult->fetchArray(SQLITE3_ASSOC)) {
    $dailyStats[] = $row;
}

// Topic distribution
$topicResult = db()->query("
    SELECT m.slug, m.title, m.icon, COUNT(*) as views 
    FROM page_views pv 
    JOIN modules m ON pv.page_slug = m.slug 
    WHERE pv.page_type IN ('module', 'lesson') 
    AND DATE(pv.viewed_at) BETWEEN '$dateFrom' AND '$dateTo'
    GROUP BY m.slug 
    ORDER BY views DESC
");
$topicDistribution = [];
while ($row = $topicResult->fetchArray(SQLITE3_ASSOC)) {
    $topicDistribution[$row['slug']] = [
        'title' => $row['title'],
        'icon' => $row['icon'],
        'views' => $row['views']
    ];
}

// Quiz performance by module
$quizResult = db()->query("
    SELECT m.slug, m.title, COUNT(*) as attempts, ROUND(AVG(qa.percentage), 1) as avg_score
    FROM quiz_attempts qa
    JOIN modules m ON qa.module_id = m.id
    WHERE DATE(qa.completed_at) BETWEEN '$dateFrom' AND '$dateTo'
    GROUP BY m.slug
    ORDER BY attempts DESC
");
$quizPerformance = [];
while ($row = $quizResult->fetchArray(SQLITE3_ASSOC)) {
    $quizPerformance[$row['slug']] = [
        'title' => $row['title'],
        'attempts' => $row['attempts'],
        'avg_score' => $row['avg_score']
    ];
}

// Language distribution
$langEn = db()->querySingle("SELECT COUNT(*) FROM sessions WHERE language = 'en' AND DATE(started_at) BETWEEN '$dateFrom' AND '$dateTo'") ?? 0;
$langSw = db()->querySingle("SELECT COUNT(*) FROM sessions WHERE language = 'sw' AND DATE(started_at) BETWEEN '$dateFrom' AND '$dateTo'") ?? 0;

// Essay stats
$totalEssays = db()->querySingle("SELECT COUNT(*) FROM essay_responses WHERE DATE(submitted_at) BETWEEN '$dateFrom' AND '$dateTo'") ?? 0;
$gradedEssays = db()->querySingle("SELECT COUNT(*) FROM essay_responses WHERE is_graded = 1 AND DATE(submitted_at) BETWEEN '$dateFrom' AND '$dateTo'") ?? 0;
$avgEssayGrade = db()->querySingle("SELECT ROUND(AVG(grade * 100.0 / qq.max_marks), 1) FROM essay_responses er JOIN quiz_questions qq ON er.question_id = qq.id WHERE er.is_graded = 1 AND DATE(er.submitted_at) BETWEEN '$dateFrom' AND '$dateTo'") ?? 0;

// ============================================
// BUILD DATA BUNDLE
// ============================================

$bundle = [
    'arise_datapost_version' => '1.0',
    'school_id' => $schoolId,
    'school_name' => $schoolName,
    'county' => $school['county'] ?? '',
    'sub_county' => $school['sub_county'] ?? '',
    'server_version' => ARISE_VERSION,
    'bundle_generated' => date('c'),
    'period' => [
        'from' => $dateFrom,
        'to' => $dateTo
    ],
    'summary' => [
        'total_sessions' => (int)$totalSessions,
        'unique_devices' => (int)$uniqueDevices,
        'total_page_views' => (int)$totalPageViews,
        'total_quiz_attempts' => (int)$totalQuizzes,
        'avg_quiz_score' => (float)$avgQuizScore,
        'total_questions_asked' => (int)$totalQuestions,
        'total_essay_responses' => (int)$totalEssays,
        'graded_essays' => (int)$gradedEssays,
        'avg_essay_grade_pct' => (float)$avgEssayGrade,
        'language_distribution' => [
            'english' => (int)$langEn,
            'kiswahili' => (int)$langSw
        ]
    ],
    'daily_stats' => $dailyStats,
    'topic_distribution' => $topicDistribution,
    'quiz_performance' => $quizPerformance,
    'courier' => [
        'name' => $courierName,
        'email' => $courierEmail,
        'pickup_time' => date('c')
    ]
];

$json = json_encode($bundle, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
$bundleHash = md5($json);

// ============================================
// LOG THE PICKUP
// ============================================

$stmt = db()->prepare('INSERT INTO datapost_pickups (courier_email, courier_name, data_from, data_to, bundle_size_kb, bundle_hash) VALUES (:email, :name, :from, :to, :size, :hash)');
$stmt->bindValue(':email', $courierEmail);
$stmt->bindValue(':name', $courierName);
$stmt->bindValue(':from', $dateFrom);
$stmt->bindValue(':to', $dateTo);
$stmt->bindValue(':size', round(strlen($json) / 1024, 1));
$stmt->bindValue(':hash', $bundleHash);
$stmt->execute();

// ============================================
// SAVE TO DISK AND SERVE DOWNLOAD
// ============================================

$datapostDir = DATAPOST_PATH;
if (!is_dir($datapostDir)) {
    mkdir($datapostDir, 0755, true);
}

$filename = "arise_{$schoolId}_{$dateFrom}_to_{$dateTo}.json";
$filepath = $datapostDir . $filename;
file_put_contents($filepath, $json);

// Serve as download
header('Content-Type: application/json');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . strlen($json));
echo $json;
exit;
