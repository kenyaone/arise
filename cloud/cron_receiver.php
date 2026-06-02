<?php
declare(strict_types=1);
header('Content-Type: application/json');

const SYNC_SECRET = 'arise_sync_k3nya_2026';
const CONFIG_PATH = '/home/cpmsfdav/cloud_db_config.php';

function fail(string $msg, int $code = 400): void {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg]);
    exit;
}

if (($_POST['secret'] ?? '') !== SYNC_SECRET) fail('bad secret', 403);

$payload = json_decode($_POST['payload'] ?? '', true);
if (!is_array($payload)) fail('bad payload');

$deviceId = trim((string)($payload['deviceId'] ?? ''));
if ($deviceId === '') fail('missing device_id');


$clusters = is_array($payload['clusters'] ?? null) ? $payload['clusters'] : null; // null = clone (don't touch clusters)
$schools  = is_array($payload['schools']  ?? null) ? $payload['schools']  : [];
$students = is_array($payload['students'] ?? null) ? $payload['students'] : [];

if (!is_file(CONFIG_PATH)) fail('config missing: ' . CONFIG_PATH, 500);
$cfg = require CONFIG_PATH;

mysqli_report(MYSQLI_REPORT_OFF);
$mysqli = @new mysqli($cfg['host'] ?? 'localhost', $cfg['user'] ?? '', $cfg['pass'] ?? '', $cfg['db'] ?? '');
if ($mysqli->connect_errno) fail('db connect: ' . $mysqli->connect_error, 500);
$mysqli->set_charset('utf8mb4');

$mysqli->begin_transaction();

try {
    // ── clusters: master-only per-device full replace ──────────────────────
    $clustersInserted = 0;
    if ($clusters !== null) {
        $stmt = $mysqli->prepare('DELETE FROM clusters WHERE device_id = ?');
        $stmt->bind_param('s', $deviceId); $stmt->execute(); $stmt->close();
        if ($clusters) {
            $stmt = $mysqli->prepare(
                'INSERT INTO clusters (name, lat, lng, device_id, local_id) VALUES (?, ?, ?, ?, ?)'
            );
            foreach ($clusters as $c) {
                $name    = (string)($c['name'] ?? '');
                if ($name === '') continue;
                $lat     =        $c['lat']   ?? null;
                $lng     =        $c['lng']   ?? null;
                $localId = (int) ($c['id']    ?? 0);
                $stmt->bind_param('sddsi', $name, $lat, $lng, $deviceId, $localId);
                $stmt->execute();
                $clustersInserted++;
            }
            $stmt->close();
        }
    }

    // ── schools: upsert — never delete a device's record, only update stats ──
    // Pick the school with the most learners if device sends multiple
    if (count($schools) > 1) {
        usort($schools, fn($a,$b) => (int)($b['learner_count']??0) - (int)($a['learner_count']??0));
        $schools = [array_shift($schools)];
    }

    // Pre-fetch existing record to calculate sync interval and learner trend
    $existing = null;
    $chk = $mysqli->prepare('SELECT last_sync_at, avg_sync_interval_secs, learner_count FROM schools WHERE device_id=?');
    $chk->bind_param('s', $deviceId); $chk->execute();
    $existing = $chk->get_result()->fetch_assoc(); $chk->close();

    // Rolling average sync interval (70% old, 30% new measurement)
    $avgInterval = null;
    if ($existing && $existing['last_sync_at']) {
        $gap = max(0, time() - strtotime($existing['last_sync_at']));
        $old = $existing['avg_sync_interval_secs'];
        $avgInterval = (int)($old ? (0.7 * $old + 0.3 * $gap) : $gap);
    }
    // Previous learner count for trend display
    $prevLearners = $existing ? (int)$existing['learner_count'] : null;

    $schoolsInserted = 0;
    if ($schools) {
        $s = $schools[0];
        $name           = (string)($s['name']                ?? '');
        if ($name !== '') {
            $county         = (string)($s['county']               ?? '');
            $active         = (int)   ($s['active']               ?? 1);
            $latNew         =          $s['lat']                  ?? null;
            $lngNew         =          $s['lng']                  ?? null;
            $clusterName    = (string)($s['cluster_name']         ?? '');
            $clusterLocalId =          $s['cluster_local_id']     ?? null;
            $learnerCount   = (int)   ($s['learner_count']        ?? 0);
            $quizCount      = (int)   ($s['quiz_count']           ?? 0);
            $pretestCount   = (int)   ($s['pretest_count']        ?? 0);
            $posttestCount  = (int)   ($s['posttest_count']       ?? 0);
            $avgScore       = (float) ($s['avg_score']            ?? 0);
            $certCount      = (int)   ($s['cert_count']           ?? 0);
            $certRate       = (float) ($s['cert_rate']            ?? 0);
            $quizPassCount  = (int)   ($s['quiz_pass_count']      ?? 0);
            $quizPassRate   = (float) ($s['quiz_pass_rate']       ?? 0);
            $avgPre         =          $s['avg_pre_score']        ?? null;
            $avgPost        =          $s['avg_post_score']       ?? null;
            $knowledgeGain  =          $s['knowledge_gain']       ?? null;
            $behaviorN      = (int)   ($s['behavior_surveys']     ?? 0);
            $pctChanged     = (float) ($s['pct_changed']          ?? 0);
            $pctShared      = (float) ($s['pct_shared']           ?? 0);
            $pctConfident   = (float) ($s['pct_confident']        ?? 0);
            $retentionCount = (int)   ($s['retention_count']      ?? 0);
            $avgRetention   = (float) ($s['avg_retention_score']  ?? 0);
            $lessonsDone    = (int)   ($s['lesson_completions']   ?? 0);
            $active30       = (int)   ($s['active_last_30_days']  ?? 0);
            $firstReg       =          $s['first_registration']   ?? null;
            $latestAct      =          $s['latest_activity']      ?? null;
            $facSessions    = (int)   ($s['facilitator_sessions'] ?? 0);

            // INSERT new device or UPDATE stats only — name/county/cluster locked on first sync,
            // never overwritten. lat/lng preserved if not sent by device.
            $stmt = $mysqli->prepare(
                'INSERT INTO schools (
                    device_id, name, county, is_active, lat, lng,
                    cluster_name, cluster_local_id,
                    learner_count, quiz_count, pretest_count, posttest_count,
                    avg_score, cert_count, cert_rate,
                    quiz_pass_count, quiz_pass_rate,
                    avg_pre_score, avg_post_score, knowledge_gain,
                    behavior_surveys, pct_changed, pct_shared, pct_confident,
                    retention_count, avg_retention_score,
                    lesson_completions, active_last_30_days,
                    first_registration, latest_activity, facilitator_sessions,
                    avg_sync_interval_secs, learner_count_prev,
                    last_sync_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE
                    learner_count_prev=learner_count,
                    learner_count=VALUES(learner_count), quiz_count=VALUES(quiz_count),
                    pretest_count=VALUES(pretest_count), posttest_count=VALUES(posttest_count),
                    avg_score=VALUES(avg_score), cert_count=VALUES(cert_count),
                    cert_rate=VALUES(cert_rate), quiz_pass_count=VALUES(quiz_pass_count),
                    quiz_pass_rate=VALUES(quiz_pass_rate), avg_pre_score=VALUES(avg_pre_score),
                    avg_post_score=VALUES(avg_post_score), knowledge_gain=VALUES(knowledge_gain),
                    behavior_surveys=VALUES(behavior_surveys), pct_changed=VALUES(pct_changed),
                    pct_shared=VALUES(pct_shared), pct_confident=VALUES(pct_confident),
                    retention_count=VALUES(retention_count), avg_retention_score=VALUES(avg_retention_score),
                    lesson_completions=VALUES(lesson_completions), active_last_30_days=VALUES(active_last_30_days),
                    first_registration=VALUES(first_registration), latest_activity=VALUES(latest_activity),
                    facilitator_sessions=VALUES(facilitator_sessions),
                    avg_sync_interval_secs=VALUES(avg_sync_interval_secs),
                    lat=IFNULL(VALUES(lat), lat), lng=IFNULL(VALUES(lng), lng),
                    last_sync_at=NOW()
                    /* name, county, cluster_name intentionally excluded — locked on first sync */'
            );
            $stmt->bind_param('ssisddsiiiiidididdddidddidiissiii',
                $deviceId, $name, $county, $active, $latNew, $lngNew,
                $clusterName, $clusterLocalId,
                $learnerCount, $quizCount, $pretestCount, $posttestCount,
                $avgScore, $certCount, $certRate,
                $quizPassCount, $quizPassRate,
                $avgPre, $avgPost, $knowledgeGain,
                $behaviorN, $pctChanged, $pctShared, $pctConfident,
                $retentionCount, $avgRetention,
                $lessonsDone, $active30,
                $firstReg, $latestAct, $facSessions,
                $avgInterval, $prevLearners);
            $stmt->execute();
            $stmt->close();
            $schoolsInserted = 1;
        }
    }

    // ── students: per-device full replace, skip soft-deleted ──────────────
    $stmt = $mysqli->prepare('DELETE FROM students WHERE device_id = ?');
    $stmt->bind_param('s', $deviceId); $stmt->execute(); $stmt->close();

    $studentsInserted = 0;
    if ($students) {
        $stmt = $mysqli->prepare(
            'INSERT INTO students (full_name, school_name, class_name, session_hash, is_active, registered_at, device_id) VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        foreach ($students as $st) {
            if (!empty($st['deleted_at'])) continue;
            $name    = (string)($st['full_name']     ?? '');
            $school  = (string)($st['school_name']   ?? '');
            $class   = (string)($st['class_name']    ?? '');
            $hash    = (string)($st['session_hash']  ?? '');
            $active  = (int)   ($st['is_active']     ?? 1);
            $reg     = (string)($st['registered_at'] ?? date('Y-m-d H:i:s'));
            if ($name === '') continue;
            $stmt->bind_param('ssssiss', $name, $school, $class, $hash, $active, $reg, $deviceId);
            $stmt->execute();
            $studentsInserted++;
        }
        $stmt->close();
    }

    $mysqli->commit();
    echo json_encode([
        'ok'       => true,
        'device'   => $deviceId,
        'clusters' => $clusters === null ? null : $clustersInserted,
        'schools'  => $schoolsInserted,
        'students' => $studentsInserted,
    ]);
} catch (Throwable $e) {
    $mysqli->rollback();
    fail('write failed: ' . $e->getMessage(), 500);
}