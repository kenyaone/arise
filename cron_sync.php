<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);
require_once __DIR__ . '/includes/config.php';
$logFile = __DIR__ . '/data/cron_sync.log';
if (!is_dir(dirname($logFile))) @mkdir(dirname($logFile), 0755, true);
function cronLog($msg) {
    global $logFile;
    $ts = date('Y-m-d H:i:s');
    $line = "[$ts] $msg\n";
    error_log($line, 3, $logFile);
    echo $line;
}
cronLog('════════════════════════════════════════');
cronLog('🔄 ARISE Auto-Sync Started');
try {
    cronLog('📥 Syncing local data...');
    $snap = [
        'learners'  => (int)db()->querySingle("SELECT COUNT(*) FROM students WHERE is_active=1 AND deleted_at IS NULL"),
        'modules'   => (int)db()->querySingle("SELECT COUNT(*) FROM modules WHERE is_active=1"),
        'lessons'   => (int)db()->querySingle("SELECT COUNT(*) FROM lessons WHERE is_active=1"),
        'quizzes'   => (int)db()->querySingle("SELECT COUNT(*) FROM quiz_attempts"),
        'pretests'  => (int)db()->querySingle("SELECT COUNT(*) FROM pretest_attempts WHERE test_type='pre'"),
        'posttests' => (int)db()->querySingle("SELECT COUNT(*) FROM pretest_attempts WHERE test_type='post'"),
        'certs'     => (int)db()->querySingle("SELECT COUNT(*) FROM certificates"),
    ];
    $ts = date('Y-m-d H:i:s');
    $tsE = SQLite3::escapeString($ts);
    db()->exec("INSERT INTO datapost_sync_log (sync_timestamp, data_snapshot) VALUES ('$tsE','" . SQLite3::escapeString(json_encode($snap)) . "')");
    cronLog('✅ Local data synced');
} catch (Exception $e) {
    cronLog('❌ Local sync failed: ' . $e->getMessage());
}
try {
    $cfg = db()->querySingle("SELECT * FROM datapost_config LIMIT 1", true);
    $schoolRows = [];
    $result = db()->query("SELECT DISTINCT school_name FROM students WHERE is_active=1 AND deleted_at IS NULL AND school_name !='' ORDER BY school_name");
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $sn = $row['school_name'];
        $sne = SQLite3::escapeString($sn);
        $schoolRows[] = [
            'school_name' => $sn,
            'learner_count' => (int)db()->querySingle("SELECT COUNT(*) FROM students WHERE school_name='$sne' AND is_active=1 AND deleted_at IS NULL"),
            'quiz_count' => (int)db()->querySingle("SELECT COUNT(qa.id) FROM quiz_attempts qa JOIN students s ON s.id=qa.student_id WHERE s.school_name='$sne'"),
        ];
    }
    if (!empty($schoolRows)) {
        cronLog('☁️ Syncing ' . count($schoolRows) . ' school(s) to cloud...');
        $payload = json_encode(['api_key'=>'ARISE_CLOUD_SYNC_2026_KEY','device_id'=>$cfg['school_id']??'arise-auto','synced_at'=>date('Y-m-d H:i:s'),'schools'=>$schoolRows]);
        $syncUrl = $cfg['cloud_sync_url'] ?? 'https://ariseci.org/arise-sync.php';
        $ch = curl_init($syncUrl);
        curl_setopt_array($ch, [CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$payload,CURLOPT_RETURNTRANSFER=>true,CURLOPT_HTTPHEADER=>['Content-Type: application/json'],CURLOPT_TIMEOUT=>30]);
        $response = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($response && $httpCode < 300) {
            $decoded = json_decode($response, true);
            if (($decoded['status'] ?? '') === 'ok') {
                cronLog('✅ Cloud sync success');
            }
        } else {
            cronLog('⚠️ Cloud sync failed: HTTP ' . $httpCode);
        }
    } else {
        cronLog('⏭️ No active schools to sync');
    }
} catch (Exception $e) {
    cronLog('⚠️ Cloud error: ' . $e->getMessage());
}
cronLog('✅ Auto-Sync Completed');
cronLog('════════════════════════════════════════');
