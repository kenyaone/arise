<?php
header('Content-Type: application/json');
/**
 * ARISE API — Interactive Lesson Endpoints
 * Handles: save_progress, save_score, set_language
 * Called via fetch() from interactive HTML lessons
 */

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'POST required']);
    exit;
}

$action = $_GET['action'] ?? '';
$body = json_decode(file_get_contents('php://input'), true) ?? [];

switch ($action) {

    // ── save_progress: track which slide the student is on ──────────────
    case 'save_progress':
        $lessonId  = intval($body['lesson_id'] ?? 0);
        $slide     = intval($body['slide'] ?? 0);

        if ($lessonId > 0) {
            $hash = getSessionHash();
            $sid  = getStudentId();

            // Upsert: one progress row per session+lesson
            $exists = db()->querySingle(
                "SELECT id FROM lesson_progress
                 WHERE session_hash = '" . SQLite3::escapeString($hash) . "'
                 AND lesson_id = $lessonId"
            );

            if ($exists) {
                $stmt = db()->prepare(
                    'UPDATE lesson_progress
                     SET last_slide = :slide, updated_at = CURRENT_TIMESTAMP
                     WHERE session_hash = :hash AND lesson_id = :lid'
                );
            } else {
                $stmt = db()->prepare(
                    'INSERT INTO lesson_progress
                     (session_hash, student_id, lesson_id, last_slide)
                     VALUES (:hash, :sid, :lid, :slide)'
                );
                $stmt->bindValue(':sid', $sid);
            }
            $stmt->bindValue(':hash',  $hash);
            $stmt->bindValue(':lid',   $lessonId);
            $stmt->bindValue(':slide', $slide);
            $stmt->execute();
        }
        echo json_encode(['status' => 'ok']);
        break;

    // ── save_score: record interactive lesson quiz score ────────────────
    case 'save_score':
        $lessonSlug  = trim($body['lesson_slug']  ?? '');
        $moduleSlug  = trim($body['module_slug']  ?? '');
        $score       = intval($body['score']       ?? 0);
        $total       = intval($body['total']       ?? 25);
        $percent     = floatval($body['percent']   ?? 0);

        if ($moduleSlug) {
            $moduleId = db()->querySingle(
                "SELECT id FROM modules WHERE slug = '" . SQLite3::escapeString($moduleSlug) . "'"
            );

            if ($moduleId) {
                $hash = getSessionHash();
                $sid  = getStudentId();

                // Save to quiz_attempts so dashboard picks it up
                $stmt = db()->prepare(
                    'INSERT INTO quiz_attempts
                     (session_hash, student_id, module_id, score, total_questions, percentage)
                     VALUES (:hash, :sid, :mod, :score, :total, :pct)'
                );
                $stmt->bindValue(':hash',  $hash);
                $stmt->bindValue(':sid',   $sid);
                $stmt->bindValue(':mod',   $moduleId);
                $stmt->bindValue(':score', $score);
                $stmt->bindValue(':total', $total);
                $stmt->bindValue(':pct',   $percent);
                $stmt->execute();

                updateDailyStats();

                // Award XP for quiz attempt
                if ($sid) {
                    $xpEarned = $percent >= 60 ? 150 : 50;
                    awardXP($sid, $xpEarned, 'quiz', 'Quiz completed: ' . $percent . '%');
                    // Update quiz pass counter
                    if ($percent >= 60) {
                        db()->exec("UPDATE student_xp SET total_quizzes_passed=total_quizzes_passed+1 WHERE student_id=$sid");
                    }
                }

                // Auto-issue certificate if score >= 60%
                if ($percent >= 60 && $sid) {
                    $student = getStudentBySession();
                    $module  = getModule($moduleSlug);
                    if ($student && $module) {
                        $existing = db()->querySingle(
                            "SELECT id FROM certificates
                             WHERE student_id = $sid AND module_id = $moduleId"
                        );
                        if (!$existing) {
                            // Generate unique cert number
                            do {
                                $certNum = 'ARISE-' . date('Y') . '-' . str_pad(mt_rand(10000,99999), 5, '0', STR_PAD_LEFT);
                            } while (db()->querySingle("SELECT id FROM certificates WHERE cert_number = '" . SQLite3::escapeString($certNum) . "'"));

                            $stmt2 = db()->prepare(
                                'INSERT INTO certificates
                                 (cert_number, student_id, student_name, module_id, module_title, score, percentage)
                                 VALUES (:cert, :sid, :name, :mid, :mtitle, :score, :pct)'
                            );
                            $stmt2->bindValue(':cert',   $certNum);
                            $stmt2->bindValue(':sid',    $sid);
                            $stmt2->bindValue(':name',   $student['full_name']);
                            $stmt2->bindValue(':mid',    $moduleId);
                            $stmt2->bindValue(':mtitle', $module['title']);
                            $stmt2->bindValue(':score',  $score);
                            $stmt2->bindValue(':pct',    $percent);
                            $stmt2->execute();
                        } elseif ($percent > db()->querySingle("SELECT percentage FROM certificates WHERE student_id = $sid AND module_id = $moduleId")) {
                            // Update score if improved
                            $stmt2 = db()->prepare('UPDATE certificates SET score = :s, percentage = :p WHERE student_id = :sid AND module_id = :mid');
                            $stmt2->bindValue(':s',   $score);
                            $stmt2->bindValue(':p',   $percent);
                            $stmt2->bindValue(':sid', $sid);
                            $stmt2->bindValue(':mid', $moduleId);
                            $stmt2->execute();
                        }
                    }
                }
            }
        }
        echo json_encode(['status' => 'ok']);
        break;

    // ── ack_cw: acknowledge content warning for a module ───────────────
    case 'ack_cw':
        $moduleId = intval($body['module_id'] ?? 0);
        if ($moduleId > 0) {
            $hash = getSessionHash();
            $_SESSION['cw_ack_' . $moduleId] = true;
        }
        echo json_encode(['status' => 'ok']);
        break;

    // ── log_sync: log courier sync event ───────────────────────────────
    case 'log_sync':
        $action2  = SQLite3::escapeString(substr($body['action'] ?? '', 0, 50));
        $entries  = intval($body['entries'] ?? 0);
        $dest     = SQLite3::escapeString(substr($body['destination'] ?? '', 0, 100));
        $status   = SQLite3::escapeString(substr($body['status'] ?? '', 0, 50));
        $device   = SQLite3::escapeString(substr($body['device'] ?? '', 0, 150));
        db()->exec("INSERT INTO arise_audit_log (admin_id,admin_name,action,target_type,target_id,details,ip_address,created_at) VALUES (0,'courier','sync_$action2','datapost',0,'entries=$entries,dest=$dest,status=$status,device=$device','".SQLite3::escapeString($_SERVER['REMOTE_ADDR']??'')."',CURRENT_TIMESTAMP)");
        echo json_encode(['status' => 'ok']);
        break;

    // ── set_language: persist language preference ───────────────────────
    case 'set_language':
        $lang = trim($body['lang'] ?? 'en');
        if (in_array($lang, ['en', 'sw', 'sh'])) {
            $hash = getSessionHash();
            $stmt = db()->prepare(
                'UPDATE sessions SET language = :lang WHERE session_hash = :hash'
            );
            $stmt->bindValue(':lang', $lang);
            $stmt->bindValue(':hash', $hash);
            $stmt->execute();
        }
        echo json_encode(['status' => 'ok']);
        break;

    default:
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Unknown action']);
}
