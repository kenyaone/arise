<?php
/**
 * Shared builder for the ARISE cloud-sync payload — used by both:
 *   - cloud_push.php (the box's cron, direct POST to ariseci.org)
 *   - public/pages/pwa_datapost.php ?action=courier_bundle (phone courier pickup)
 *
 * Returns exactly the JSON shape that cloud/cron_receiver.php expects:
 *   { deviceId, schools[], students[], clusters[]? }
 *
 * clusters[] is only present on the "master" box (fresh install) so clones
 * never re-add clusters that master has deleted.
 */

if (!defined('COURIER_DEVICE_ID_FILE')) define('COURIER_DEVICE_ID_FILE', '/etc/arise_device_id');
if (!defined('COURIER_MASTER_MARKER'))  define('COURIER_MASTER_MARKER',  '/etc/arise_cluster_master');

function courier_device_id(): string {
    if (is_file(COURIER_DEVICE_ID_FILE)) {
        $id = trim((string)@file_get_contents(COURIER_DEVICE_ID_FILE));
        if ($id !== '') return $id;
    }
    $mac = '';
    $out = @shell_exec("ip -o link show 2>/dev/null");
    foreach (explode("\n", $out ?? '') as $line) {
        if (strpos($line, 'lo:') !== false) continue;
        if (preg_match('/link\/ether\s+([0-9a-f:]{17})/i', $line, $m)) {
            $mac = strtoupper(str_replace(':', '', $m[1]));
            break;
        }
    }
    if ($mac === '') $mac = strtoupper(md5(gethostname()));
    $id = 'ARISE-' . $mac;
    @file_put_contents(COURIER_DEVICE_ID_FILE, $id);
    return $id;
}

/**
 * Build the {deviceId, schools, students, clusters?} bundle from a live SQLite DB.
 *
 * @param SQLite3 $db  read-only handle on the arise SQLite database
 * @return array       assoc array ready for json_encode
 */
function build_courier_bundle(SQLite3 $db): array {
    $deviceId = courier_device_id();
    $isMaster = is_file(COURIER_MASTER_MARKER);

    // ── clusters (master-only) ───────────────────────────────────────────────
    $clusters = [];
    if ($isMaster) {
        $r = $db->query("SELECT id, name, password_hash, lat, lng FROM clusters ORDER BY id");
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

    // local cluster id → name (used to denormalise cluster label on every school row)
    $clusterNameById = [];
    $r = $db->query("SELECT id, name FROM clusters");
    while ($row = $r->fetchArray(SQLITE3_ASSOC)) $clusterNameById[(int)$row['id']] = $row['name'];

    // Cluster centroid fallback — mirror of the hardcoded map in cloud_push.php.
    // Keep in lockstep with that file if either changes.
    $CLUSTER_COORDS = [
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
    ];

    // ── schools: one row per active school with all the aggregated metrics ───
    $schools = [];
    $r = $db->query("SELECT name, county, is_active, password_hash, lat, lng, cluster_id FROM schools WHERE is_active=1");
    while ($row = $r->fetchArray(SQLITE3_ASSOC)) {
        $name = $row['name'];
        $cid  = $row['cluster_id'] !== null ? (int)$row['cluster_id'] : null;
        $schools[$name] = [
            'name'                 => $name,
            'county'               => $row['county'] ?? '',
            'active'               => (int)$row['is_active'],
            'hash'                 => $row['password_hash'] ?? '',
            'lat'                  => ($row['lat'] !== null && $row['lat'] !== '') ? $row['lat']
                                     : ($cid !== null && isset($clusterNameById[$cid], $CLUSTER_COORDS[$clusterNameById[$cid]]) ? $CLUSTER_COORDS[$clusterNameById[$cid]][0] : null),
            'lng'                  => ($row['lng'] !== null && $row['lng'] !== '') ? $row['lng']
                                     : ($cid !== null && isset($clusterNameById[$cid], $CLUSTER_COORDS[$clusterNameById[$cid]]) ? $CLUSTER_COORDS[$clusterNameById[$cid]][1] : null),
            'device_id'            => $deviceId,
            'cluster_local_id'     => $cid,
            'cluster_name'         => $cid !== null ? ($clusterNameById[$cid] ?? '') : '',
            'learner_count'        => 0,
            'quiz_count'           => 0,
            'pretest_count'        => 0,
            'posttest_count'       => 0,
            'avg_score'            => 0.0,
            'cert_count'           => 0,
            'cert_rate'            => 0.0,
            'quiz_pass_count'      => 0,
            'quiz_pass_rate'       => 0.0,
            'avg_pre_score'        => null,
            'avg_post_score'       => null,
            'knowledge_gain'       => null,
            'behavior_surveys'     => 0,
            'pct_changed'          => 0.0,
            'pct_shared'           => 0.0,
            'pct_confident'        => 0.0,
            'retention_count'      => 0,
            'avg_retention_score'  => 0.0,
            'lesson_completions'   => 0,
            'active_last_30_days'  => 0,
            'first_registration'   => null,
            'latest_activity'      => null,
            'facilitator_sessions' => 0,
        ];
    }

    $apply = function (string $sql, callable $cb) use ($db, &$schools): void {
        try {
            $r = @$db->query($sql);
            if (!$r) return;
            while ($row = $r->fetchArray(SQLITE3_ASSOC)) {
                $name = $row['school_name'] ?? '';
                if ($name !== '' && isset($schools[$name])) $cb($schools[$name], $row);
            }
        } catch (Throwable $e) { /* table missing on this box — skip */ }
    };

    $apply(
        "SELECT school_name, COUNT(*) v FROM students
           WHERE is_active=1 AND deleted_at IS NULL GROUP BY school_name",
        function (array &$o, array $r) { $o['learner_count'] = (int)$r['v']; }
    );

    $apply(
        "SELECT s.school_name,
            COUNT(qa.id) c,
            ROUND(AVG(qa.percentage),1) avg,
            SUM(CASE WHEN qa.percentage>=60 THEN 1 ELSE 0 END) pass
         FROM quiz_attempts qa
         JOIN students s ON s.id = qa.student_id
         GROUP BY s.school_name",
        function (array &$o, array $r) {
            $o['quiz_count']      = (int)$r['c'];
            $o['avg_score']       = (float)($r['avg'] ?? 0);
            $o['quiz_pass_count'] = (int)$r['pass'];
            $o['quiz_pass_rate']  = (int)$r['c'] > 0 ? round((int)$r['pass'] / (int)$r['c'] * 100, 1) : 0.0;
        }
    );

    $apply(
        "SELECT s.school_name, COUNT(*) v
         FROM certificates c JOIN students s ON s.id = c.student_id
         GROUP BY s.school_name",
        function (array &$o, array $r) {
            $o['cert_count'] = (int)$r['v'];
            $o['cert_rate']  = $o['learner_count'] > 0 ? round($o['cert_count'] / $o['learner_count'] * 100, 1) : 0.0;
        }
    );

    $apply(
        "SELECT s.school_name,
            ROUND(AVG(CASE WHEN pa.test_type='pre'  THEN pa.percentage END),1) avg_pre,
            ROUND(AVG(CASE WHEN pa.test_type='post' THEN pa.percentage END),1) avg_post,
            COUNT(CASE WHEN pa.test_type='pre'  THEN 1 END) pre_n,
            COUNT(CASE WHEN pa.test_type='post' THEN 1 END) post_n
         FROM pretest_attempts pa JOIN students s ON s.id = pa.student_id
         GROUP BY s.school_name",
        function (array &$o, array $r) {
            $pre  = $r['avg_pre']  !== null ? (float)$r['avg_pre']  : null;
            $post = $r['avg_post'] !== null ? (float)$r['avg_post'] : null;
            $o['avg_pre_score']  = $pre;
            $o['avg_post_score'] = $post;
            $o['knowledge_gain'] = ($pre !== null && $post !== null) ? round($post - $pre, 1) : null;
            $o['pretest_count']  = (int)$r['pre_n'];
            $o['posttest_count'] = (int)$r['post_n'];
        }
    );

    $apply(
        "SELECT s.school_name,
            COUNT(bs.id) v,
            SUM(COALESCE(bs.q1_changed,0))   changed,
            SUM(COALESCE(bs.q2_shared,0))    shared,
            SUM(COALESCE(bs.q3_confident,0)) confident
         FROM behavioral_surveys bs JOIN students s ON s.id = bs.student_id
         GROUP BY s.school_name",
        function (array &$o, array $r) {
            $n = (int)$r['v'];
            $o['behavior_surveys'] = $n;
            $o['pct_changed']      = $n > 0 ? round((int)$r['changed']   / $n * 100, 1) : 0.0;
            $o['pct_shared']       = $n > 0 ? round((int)$r['shared']    / $n * 100, 1) : 0.0;
            $o['pct_confident']    = $n > 0 ? round((int)$r['confident'] / $n * 100, 1) : 0.0;
        }
    );

    $apply(
        "SELECT s.school_name,
            COUNT(rt.id) v,
            ROUND(AVG(rt.percentage),1) avg
         FROM retention_tests rt JOIN students s ON s.id = rt.student_id
         GROUP BY s.school_name",
        function (array &$o, array $r) {
            $o['retention_count']     = (int)$r['v'];
            $o['avg_retention_score'] = (float)($r['avg'] ?? 0);
        }
    );

    $apply(
        "SELECT s.school_name, COUNT(*) v
         FROM lesson_progress lp JOIN students s ON s.id = lp.student_id
         WHERE lp.completed = 1
         GROUP BY s.school_name",
        function (array &$o, array $r) { $o['lesson_completions'] = (int)$r['v']; }
    );

    $apply(
        "SELECT school_name, COUNT(*) v FROM students
         WHERE is_active=1 AND deleted_at IS NULL
           AND last_seen >= datetime('now','-30 days')
         GROUP BY school_name",
        function (array &$o, array $r) { $o['active_last_30_days'] = (int)$r['v']; }
    );

    $apply(
        "SELECT school_name,
            MIN(registered_at) first_reg,
            MAX(last_seen)     last_act
         FROM students WHERE is_active=1 AND deleted_at IS NULL
         GROUP BY school_name",
        function (array &$o, array $r) {
            $o['first_registration'] = $r['first_reg'] ?: null;
            $o['latest_activity']    = $r['last_act']  ?: null;
        }
    );

    $apply(
        "SELECT school_name, COUNT(*) v FROM facilitator_sessions GROUP BY school_name",
        function (array &$o, array $r) { $o['facilitator_sessions'] = (int)$r['v']; }
    );

    $schools = array_values($schools);

    // ── students ────────────────────────────────────────────────────────────
    $students = [];
    $r = $db->query("SELECT id, full_name, school_name, class_name, session_hash, is_active, registered_at, deleted_at FROM students");
    while ($row = $r->fetchArray(SQLITE3_ASSOC)) {
        $row['device_id'] = $deviceId;
        $students[] = $row;
    }

    $bundle = compact('schools', 'students', 'deviceId');
    if ($isMaster) $bundle['clusters'] = $clusters;
    return $bundle;
}

/**
 * Open the arise SQLite DB read-only against a temp copy so we don't block
 * writers on the live file.
 *
 * @return array{db: SQLite3, tmp: string}  caller must unlink $tmp after use
 */
function courier_open_readonly_copy(string $livePath): array {
    $tmp = tempnam(sys_get_temp_dir(), 'arise_bundle_');
    if (!copy($livePath, $tmp)) {
        @unlink($tmp);
        throw new RuntimeException('Failed to copy live DB to temp for read-only bundle build');
    }
    $db = new SQLite3($tmp, SQLITE3_OPEN_READONLY);
    $db->busyTimeout(3000);
    return ['db' => $db, 'tmp' => $tmp];
}
