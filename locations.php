<?php
// ARISE — public locations map (cloud / MySQL)
// Leaflet map with click-for-details popups on each project pin.
// Tiles via CartoCDN (Fastly), independent of tile.openstreetmap.org.

const CONFIG_PATH = '/home/cpmsfdav/cloud_db_config.php';
// Pins go grey after 2 hours of no sync — devices sync every minute when online.
const STALE_SECS = 7200; // 2 hours

$cfg = is_file(CONFIG_PATH) ? require CONFIG_PATH : ['host'=>'localhost'];
mysqli_report(MYSQLI_REPORT_OFF);
$mysqli = @new mysqli($cfg['host'] ?? 'localhost', $cfg['user'] ?? '', $cfg['pass'] ?? '', $cfg['db'] ?? '');
$dbError = $mysqli->connect_errno ? $mysqli->connect_error : null;
if (!$dbError) $mysqli->set_charset('utf8mb4');

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function num($n, $dp = 0) { return $n === null ? '—' : number_format((float)$n, $dp); }
function pct($n, $dp = 1) { return $n === null || $n === '' ? '—' : number_format((float)$n, $dp) . '%'; }

$groups = [];
$markers = [];
$totalProjects = 0; $totalLearners = 0; $totalQuizzes = 0; $totalCerts = 0;
$recentlySyncedProjects = 0;
$weightedScoreNum = 0.0; $weightedScoreDen = 0;
$nowTs = time();

// Human-readable "last seen" — "just now", "12 min ago", "3 days ago", etc.
function lastSeen(?int $ageSec): string {
    if ($ageSec === null) return 'never';
    if ($ageSec < 120)    return 'just now';
    if ($ageSec < 3600)   return floor($ageSec / 60) . ' min ago';
    if ($ageSec < 86400)  return floor($ageSec / 3600) . ' hr ago';
    $days = floor($ageSec / 86400);
    return $days === 1.0 ? 'yesterday' : ((int)$days) . ' days ago';
}

if (!$dbError) {
    $sql = "SELECT name, county, lat, lng, cluster_name, device_id,
                   learner_count, quiz_count, avg_score, cert_count, cert_rate,
                   quiz_pass_rate, avg_pre_score, avg_post_score, knowledge_gain,
                   active_last_30_days, lesson_completions,
                   last_sync_at
            FROM schools
            WHERE is_active = 1
              AND last_sync_at IS NOT NULL
            ORDER BY cluster_name, name";
    $r = $mysqli->query($sql);
    if ($r) {
        while ($row = $r->fetch_assoc()) {
            $totalProjects++;
            $totalLearners += (int)($row['learner_count'] ?? 0);
            $totalQuizzes  += (int)($row['quiz_count'] ?? 0);
            $totalCerts    += (int)($row['cert_count'] ?? 0);
            if ((int)$row['quiz_count'] > 0 && $row['avg_score'] !== null) {
                $weightedScoreNum += (float)$row['avg_score'] * (int)$row['quiz_count'];
                $weightedScoreDen += (int)$row['quiz_count'];
            }

            // Compute staleness from last_sync_at. NULL = never synced.
            $syncTs   = $row['last_sync_at'] ? strtotime((string)$row['last_sync_at']) : null;
            $ageSec   = $syncTs === null ? null : max(0, $nowTs - $syncTs);
            $isStale  = $ageSec === null || $ageSec > STALE_SECS;
            if (!$isStale) $recentlySyncedProjects++;
            // Offline duration label
            $offlineLabel = null;
            if ($isStale && $ageSec !== null) {
                $days  = floor($ageSec / 86400);
                $hours = floor(($ageSec % 86400) / 3600);
                $offlineLabel = $days > 0 ? "Offline {$days}d " . ($hours > 0 ? "{$hours}h" : '') : "Offline {$hours}h";
            } elseif ($isStale) {
                $offlineLabel = 'Never synced';
            }
            $row['_age_sec']      = $ageSec;
            $row['_is_stale']     = $isStale;
            $row['_offline_label']= $offlineLabel;

            $cluster = trim((string)($row['cluster_name'] ?? ''));
            $group   = $cluster !== '' ? $cluster : (trim((string)$row['county']) ?: 'Unassigned');
            $isCluster = $cluster !== '';
            if (!isset($groups[$group])) $groups[$group] = ['is_cluster'=>$isCluster, 'projects'=>[]];
            $groups[$group]['projects'][] = $row;

            if ($row['lat'] !== null && $row['lng'] !== null) {
                $markers[] = [
                    'name'         => $row['name'],
                    'cluster'      => $group,
                    'is_cluster'   => $isCluster,
                    'county'       => $row['county'],
                    'lat'          => (float)$row['lat'],
                    'lng'          => (float)$row['lng'],
                    'learners'     => (int)$row['learner_count'],
                    'quiz_count'   => (int)$row['quiz_count'],
                    'avg_score'    => $row['avg_score'] === null ? null : (float)$row['avg_score'],
                    'cert_count'   => (int)$row['cert_count'],
                    'active_30'    => (int)$row['active_last_30_days'],
                    'lessons_done' => (int)$row['lesson_completions'],
                    'know_gain'    => $row['knowledge_gain'] === null ? null : (float)$row['knowledge_gain'],
                    'stale'        => $isStale,
                    'last_seen'    => lastSeen($ageSec),
                    'device_id'    => $row['device_id'],
                    'offline_label'=> $offlineLabel,
                ];
            }
        }
    }
}
$globalAvgScore = $weightedScoreDen > 0 ? round($weightedScoreNum / $weightedScoreDen, 1) : null;

uksort($groups, function($a, $b) use ($groups) {
    $ac = $groups[$a]['is_cluster'] ? 0 : 1;
    $bc = $groups[$b]['is_cluster'] ? 0 : 1;
    return $ac === $bc ? strcasecmp($a, $b) : $ac - $bc;
});

$markersJson = json_encode($markers, JSON_UNESCAPED_UNICODE);
?>
<!DOCTYPE html>
<html lang="en">
<head><meta charset="utf-8">

<meta name="viewport" content="width=device-width,initial-scale=1">
<title>ARISE — Projects Map</title>
<link rel="stylesheet" href="/leaflet.css">
<style>
*{box-sizing:border-box;margin:0;padding:0;}
body{font-family:'Segoe UI',Roboto,Arial,sans-serif;background:#f5f7fa;color:#1f2937;line-height:1.5;}
header{background:linear-gradient(135deg,#0a5e2a,#0ea271);color:#fff;padding:24px 20px;text-align:center;}
header h1{font-size:1.6rem;margin-bottom:4px;}
header p{font-size:.9rem;opacity:.9;}
.kpis{display:flex;gap:12px;max-width:1100px;margin:20px auto;padding:0 16px;flex-wrap:wrap;}
.kpi{background:#fff;border-radius:12px;padding:18px;flex:1 1 180px;text-align:center;box-shadow:0 1px 3px rgba(0,0,0,.05);}
.kpi .num{font-size:1.8rem;font-weight:800;color:#0a5e2a;}
.kpi .lbl{font-size:.8rem;color:#6b7280;text-transform:uppercase;letter-spacing:.04em;margin-top:4px;}
#map{height:480px;max-width:1100px;margin:16px auto;border-radius:12px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,.05);background:#e5e7eb;}
.map-cap{max-width:1100px;margin:6px auto 0;padding:0 16px;font-size:.78rem;color:#6b7280;text-align:right;}
.map-cap a{color:#6b7280;text-decoration:none;}
.groups{max-width:1100px;margin:16px auto 60px;padding:0 16px;}
.group{margin-bottom:28px;}
.group-head{display:flex;align-items:center;gap:10px;margin-bottom:12px;padding-bottom:8px;border-bottom:2px solid #d1fae5;}
.group-head h2{font-size:1.15rem;color:#0a5e2a;}
.group-head .chip{font-size:.7rem;font-weight:700;text-transform:uppercase;padding:3px 9px;border-radius:10px;}
.chip.cluster{background:#dcfce7;color:#166534;}
.chip.county{background:#fef3c7;color:#92400e;}
.cards{display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:14px;}
.card{background:#fff;border-radius:10px;padding:16px;border-left:4px solid #0ea271;box-shadow:0 1px 3px rgba(0,0,0,.05);}
.card.stale{border-left-color:#9ca3af;background:#fafafa;}
.card.stale h3{color:#6b7280;}
.metric .val.sync{font-weight:600;font-size:.8rem;padding:1px 8px;border-radius:10px;}
.metric .val.sync.fresh{background:#dcfce7;color:#166534;}
.metric .val.sync.stale{background:#f3f4f6;color:#6b7280;}
.card h3{font-size:1rem;margin-bottom:4px;color:#111827;}
.card .sub{font-size:.78rem;color:#6b7280;margin-bottom:10px;}
.metric{display:flex;justify-content:space-between;padding:3px 0;font-size:.85rem;}
.metric .lbl{color:#555;}
.metric .val{font-weight:700;color:#0a5e2a;}
.empty{text-align:center;padding:40px 20px;color:#6b7280;}
.err{background:#fee2e2;color:#991b1b;padding:12px 16px;border-radius:8px;max-width:1100px;margin:16px auto;}
footer{text-align:center;font-size:.78rem;color:#6b7280;padding:18px;}
.leaflet-popup-content{margin:10px 14px;font-family:'Segoe UI',Roboto,Arial,sans-serif;}
.leaflet-popup-content h4{font-size:1rem;color:#0a5e2a;margin-bottom:6px;}
.leaflet-popup-content .pop-chip{display:inline-block;font-size:.72rem;font-weight:700;padding:3px 10px;border-radius:12px;text-transform:uppercase;letter-spacing:.03em;margin-bottom:6px;}
.leaflet-popup-content .pop-chip.cluster{background:#dcfce7;color:#166534;}
.leaflet-popup-content .pop-chip.county{background:#fef3c7;color:#92400e;}
.leaflet-popup-content .pop-county{font-size:.72rem;color:#9ca3af;margin-bottom:8px;}
.leaflet-popup-content .pop-row{display:flex;justify-content:space-between;gap:14px;font-size:.85rem;padding:2px 0;}
.leaflet-popup-content .pop-row .l{color:#555;}
.leaflet-popup-content .pop-row .v{font-weight:700;color:#0a5e2a;}
@media (max-width:600px){#map{height:340px;}.kpi .num{font-size:1.4rem;}}
</style>
</head>
<body>
<header>
  <h1>ARISE — Projects Map</h1>
  <p>Adolescent Reproductive Health · Education Impact</p>
</header>

<?php if ($dbError): ?>
  <div class="err">Database error: <?= h($dbError) ?></div>
<?php else: ?>

<section class="kpis">
  <div class="kpi"><div class="num"><?= num($totalProjects) ?></div><div class="lbl">Projects</div></div>
  <div class="kpi"><div class="num"><?= num($recentlySyncedProjects) ?>/<?= num($totalProjects) ?></div><div class="lbl">Synced &lt; 2h</div></div>
  <div class="kpi"><div class="num"><?= num($totalLearners) ?></div><div class="lbl">Learners</div></div>
  <div class="kpi"><div class="num"><?= num($totalQuizzes) ?></div><div class="lbl">Quiz Attempts</div></div>
  <div class="kpi"><div class="num"><?= pct($globalAvgScore) ?></div><div class="lbl">Avg Score</div></div>
  <div class="kpi"><div class="num"><?= num($totalCerts) ?></div><div class="lbl">Certificates</div></div>
</section>

<div id="map"></div>
<div class="map-cap">Click any pin for project details · &copy; <a href="https://openstreetmap.org/copyright" target="_blank" rel="noopener">OpenStreetMap</a> &copy; <a href="https://carto.com/attributions" target="_blank" rel="noopener">CARTO</a></div>

<section class="groups">
<?php if (!$groups): ?>
  <div class="empty">No active projects yet. Field boxes will appear here as soon as they sync.</div>
<?php else: foreach ($groups as $groupName => $g): ?>
  <div class="group">
    <div class="group-head">
      <h2><?= h($groupName) ?></h2>
      <span class="chip <?= $g['is_cluster'] ? 'cluster' : 'county' ?>"><?= $g['is_cluster'] ? 'Cluster' : 'County' ?></span>
      <span style="color:#9ca3af;font-size:.85rem;">· <?= count($g['projects']) ?> project<?= count($g['projects'])!==1?'s':'' ?></span>
    </div>
    <div class="cards">
      <?php foreach ($g['projects'] as $p): ?>
      <div class="card<?= $p['_is_stale'] ? ' stale' : '' ?>">
        <h3><?= h($p['name']) ?></h3>
        <div class="sub"><?= h($p['county'] ?: '—') ?></div>
        <div class="metric"><span class="lbl">📟 Device</span><span class="val" style="font-size:.78rem;font-family:monospace;color:#6b7280;"><?= h($p['device_id']) ?></span></div>
        <div class="metric"><span class="lbl">👥 Learners</span><span class="val"><?= num($p['learner_count']) ?></span></div>
        <div class="metric"><span class="lbl">📝 Quiz Avg</span><span class="val"><?= $p['quiz_count']>0 ? pct($p['avg_score']) : '—' ?></span></div>
        <div class="metric"><span class="lbl">🎓 Certificates</span><span class="val"><?= num($p['cert_count']) ?></span></div>
        <?php if ((int)$p['active_last_30_days'] > 0): ?>
        <div class="metric"><span class="lbl">🟢 Active (30d)</span><span class="val"><?= num($p['active_last_30_days']) ?></span></div>
        <?php endif; ?>
        <?php if ($p['knowledge_gain'] !== null): ?>
        <div class="metric"><span class="lbl">📈 Knowledge gain</span><span class="val"><?= pct($p['knowledge_gain']) ?></span></div>
        <?php endif; ?>
        <div class="metric"><span class="lbl">📡 Last sync</span><span class="val sync <?= $p['_is_stale'] ? 'stale' : 'fresh' ?>"><?= h(lastSeen($p['_age_sec'])) ?></span></div>
        <?php if ($p['_is_stale'] && isset($p['_offline_label'])): ?>
        <div class="metric"><span class="lbl">🔴 Status</span><span class="val" style="color:#dc2626;font-weight:700;"><?= h($p['_offline_label']) ?></span></div>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
<?php endforeach; endif; ?>
</section>

<?php endif; ?>

<footer>ARISE · live data from offline field boxes · updated automatically every minute</footer>

<script src="/leaflet.js"></script>
<script>
(function () {
  if (typeof L === 'undefined') {
    document.getElementById('map').innerHTML =
      '<div style="display:flex;align-items:center;justify-content:center;height:100%;color:#6b7280;">Map library failed to load.</div>';
    return;
  }
  var markers = <?= $markersJson ?: '[]' ?>;
  var map = L.map('map', { scrollWheelZoom: true }).setView([0.5, 37.5], 6);

  // CartoCDN positron — light minimal style, served from Fastly so it sails
  // through most ad-blockers and corporate filters.
  L.tileLayer('https://{s}.basemaps.cartocdn.com/light_all/{z}/{x}/{y}.png', {
    maxZoom: 19,
    subdomains: 'abcd',
    attribution: ''
  }).addTo(map);

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c];
    });
  }
  function fmt(n)  { return n == null ? '—' : Number(n).toLocaleString(); }
  function pct(n)  { return n == null ? '—' : Number(n).toFixed(1) + '%'; }

  if (!markers.length) {
    return;
  }
  var group = L.featureGroup();
  markers.forEach(function (m) {
    var rows = [
      ['📟 Device',     '<span style="font-family:monospace;font-size:.8em;color:#6b7280;">'+esc(m.device_id)+'</span>'],
      ['👥 Learners',   fmt(m.learners)],
    ];
    if (m.stale && m.offline_label) {
      rows.splice(1, 0, ['🔴 Status', '<span style="color:#dc2626;font-weight:700;">'+esc(m.offline_label)+'</span>']);
    }
    rows = rows.concat([
      ['📝 Quiz attempts', fmt(m.quiz_count)],
      ['📝 Quiz avg',   m.quiz_count > 0 ? pct(m.avg_score) : '—'],
      ['🎓 Certificates', fmt(m.cert_count)],
      ['🟢 Active 30d', fmt(m.active_30)],
      ['📚 Lessons done', fmt(m.lessons_done)],
      ['📡 Last sync',  m.last_seen],
    ]);
    if (m.know_gain !== null) rows.push(['📈 Knowledge gain', pct(m.know_gain)]);

    // Cluster is the headline grouping — show as prominent chip.
    // County drops below as small secondary text (or replaces chip if no cluster).
    var html = '<h4>' + esc(m.name) + '</h4>';
    if (m.is_cluster) {
      html += '<div><span class="pop-chip cluster">🏷 ' + esc(m.cluster) + '</span></div>';
      if (m.county && m.county !== m.cluster) {
        html += '<div class="pop-county">' + esc(m.county) + ' county</div>';
      }
    } else if (m.county) {
      html += '<div><span class="pop-chip county">📍 ' + esc(m.county) + ' county</span></div>';
    }
    rows.forEach(function (r) {
      html += '<div class="pop-row"><span class="l">' + r[0] + '</span><span class="v">' + r[1] + '</span></div>';
    });

    // Green circle = synced within 2h, grey = older / never.
    // Using circleMarker so the colour swap is one line, no icon assets needed.
    var color = m.stale ? '#9ca3af' : '#0ea271';
    L.circleMarker([m.lat, m.lng], {
      radius:      m.stale ? 7 : 9,
      color:       '#ffffff',
      weight:      2,
      fillColor:   color,
      fillOpacity: m.stale ? 0.65 : 0.95,
    })
      .bindPopup(html, { maxWidth: 280 })
      .addTo(group);
  });
  group.addTo(map);

  // Fit to all markers with a little padding; if only one, zoom in moderately.
  if (markers.length === 1) {
    map.setView([markers[0].lat, markers[0].lng], 10);
  } else {
    map.fitBounds(group.getBounds().pad(0.2));
  }
})();
</script>
</body>
</html>