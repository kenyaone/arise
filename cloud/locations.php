<?php
const CONFIG_PATH = '/home/cpmsfdav/cloud_db_config.php';
const STALE_SECS  = 7200;

$cfg = is_file(CONFIG_PATH) ? require CONFIG_PATH : ['host'=>'localhost'];
mysqli_report(MYSQLI_REPORT_OFF);
$mysqli = @new mysqli($cfg['host']??'localhost',$cfg['user']??'',$cfg['pass']??'',$cfg['db']??'');
$dbError = $mysqli->connect_errno ? $mysqli->connect_error : null;
if (!$dbError) $mysqli->set_charset('utf8mb4');

function h($s){ return htmlspecialchars((string)$s,ENT_QUOTES,'UTF-8'); }
function num($n,$dp=0){ return $n===null?'—':number_format((float)$n,$dp); }
function pct($n,$dp=1){ return ($n===null||$n==='')?'—':number_format((float)$n,$dp).'%'; }
function lastSeen(?int $s): string {
    if($s===null) return 'never';
    if($s<120)    return 'just now';
    if($s<3600)   return floor($s/60).' min ago';
    if($s<86400)  return floor($s/3600).' hr ago';
    $d=floor($s/86400); return $d===1.0?'yesterday':((int)$d).' days ago';
}
function milestone(int $n): ?string {
    if($n>=100) return '🏆';
    if($n>=50)  return '🌳';
    if($n>=25)  return '🌿';
    if($n>=10)  return '🌱';
    return null;
}

$groups=[]; $markers=[];
$totalProjects=0; $totalLearners=0; $totalQuizzes=0; $totalCerts=0;
$recentlySyncedProjects=0;
$weightedScoreNum=0.0; $weightedScoreDen=0;
$weightedGainNum=0.0;  $weightedGainDen=0;
$nowTs=time();

if (!$dbError) {
    $sql = "SELECT name, county, lat, lng, cluster_name, device_id,
                   learner_count, learner_count_prev, avg_sync_interval_secs,
                   quiz_count, avg_score, cert_count, cert_rate,
                   avg_pre_score, avg_post_score, knowledge_gain,
                   active_last_30_days, lesson_completions,
                   latest_activity, first_registration,
                   last_sync_at
            FROM schools
            WHERE is_active=1 AND last_sync_at IS NOT NULL
            ORDER BY cluster_name, name";
    $r = $mysqli->query($sql);
    if ($r) while ($row = $r->fetch_assoc()) {
        $totalProjects++;
        $totalLearners += (int)$row['learner_count'];
        $totalQuizzes  += (int)$row['quiz_count'];
        $totalCerts    += (int)$row['cert_count'];
        if ((int)$row['quiz_count']>0 && $row['avg_score']!==null) {
            $weightedScoreNum += (float)$row['avg_score']*(int)$row['quiz_count'];
            $weightedScoreDen += (int)$row['quiz_count'];
        }
        if ($row['knowledge_gain']!==null && (int)$row['learner_count']>0) {
            $weightedGainNum += (float)$row['knowledge_gain']*(int)$row['learner_count'];
            $weightedGainDen += (int)$row['learner_count'];
        }

        $syncTs  = $row['last_sync_at'] ? strtotime($row['last_sync_at']) : null;
        $ageSec  = $syncTs ? max(0,$nowTs-$syncTs) : null;
        $isStale = $ageSec===null || $ageSec>STALE_SECS;
        if (!$isStale) $recentlySyncedProjects++;

        $offlineLabel = null;
        if ($isStale && $ageSec!==null) {
            $days=floor($ageSec/86400); $hrs=floor(($ageSec%86400)/3600);
            $offlineLabel = $days>0?"Offline {$days}d".($hrs>0?" {$hrs}h":''):"Offline {$hrs}h";
        } elseif ($isStale) { $offlineLabel='Never synced'; }

        // Last learner activity age
        $actTs  = $row['latest_activity'] ? strtotime($row['latest_activity']) : null;
        $actAge = $actTs ? max(0,$nowTs-$actTs) : null;

        // Cert rate
        $certRate = ($row['learner_count']>0 && $row['cert_count']>0)
            ? round($row['cert_count']/$row['learner_count']*100,1) : null;

        $row['_age_sec']       = $ageSec;
        $row['_is_stale']      = $isStale;
        $row['_offline_label'] = $offlineLabel;
        $row['_act_age']       = $actAge;
        $row['_cert_rate']     = $certRate;

        $cluster   = trim((string)($row['cluster_name']??''));
        $group     = $cluster!=='' ? $cluster : (trim((string)$row['county'])?:'Unassigned');
        $isCluster = $cluster!=='';
        if (!isset($groups[$group])) $groups[$group]=['is_cluster'=>$isCluster,'projects'=>[]];
        $groups[$group]['projects'][] = $row;

        if ($row['lat']!==null && $row['lng']!==null) {
            $markers[] = [
                'name'          => $row['name'],
                'cluster'       => $group,
                'is_cluster'    => $isCluster,
                'county'        => $row['county'],
                'lat'           => (float)$row['lat'],
                'lng'           => (float)$row['lng'],
                'learners'      => (int)$row['learner_count'],
                'quiz_count'    => (int)$row['quiz_count'],
                'avg_score'     => $row['avg_score']===null?null:(float)$row['avg_score'],
                'cert_count'    => (int)$row['cert_count'],
                'cert_rate'     => $certRate,
                'active_30'     => (int)$row['active_last_30_days'],
                'lessons_done'  => (int)$row['lesson_completions'],
                'pre_score'     => $row['avg_pre_score']===null?null:(float)$row['avg_pre_score'],
                'post_score'    => $row['avg_post_score']===null?null:(float)$row['avg_post_score'],
                'know_gain'     => $row['knowledge_gain']===null?null:(float)$row['knowledge_gain'],
                'stale'         => $isStale,
                'last_seen'     => lastSeen($ageSec),
                'last_activity' => lastSeen($actAge),
                'device_id'     => $row['device_id'],
                'offline_label' => $offlineLabel,
                'sync_interval' => $row['avg_sync_interval_secs']?round($row['avg_sync_interval_secs']/60,1):null,
                'learner_delta' => $row['learner_count_prev']!==null?((int)$row['learner_count']-(int)$row['learner_count_prev']):null,
                'milestone'     => milestone((int)$row['learner_count']),
            ];
        }
    }
}
$globalAvgScore = $weightedScoreDen>0?round($weightedScoreNum/$weightedScoreDen,1):null;
$globalAvgGain  = $weightedGainDen>0?round($weightedGainNum/$weightedGainDen,1):null;
$globalCertRate = $totalLearners>0?round($totalCerts/$totalLearners*100,1):null;

// Cluster rollup
$clusterRollup = [];
foreach ($groups as $gName => $g) {
    $cl=['learners'=>0,'certs'=>0,'projects'=>count($g['projects']),'online'=>0,
         'pre_sum'=>0,'pre_n'=>0,'post_sum'=>0,'post_n'=>0];
    foreach ($g['projects'] as $p) {
        $cl['learners']+=(int)$p['learner_count'];
        $cl['certs']+=(int)$p['cert_count'];
        if (!$p['_is_stale']) $cl['online']++;
        if ($p['avg_pre_score'])  { $cl['pre_sum']+=(float)$p['avg_pre_score'];  $cl['pre_n']++; }
        if ($p['avg_post_score']) { $cl['post_sum']+=(float)$p['avg_post_score'];$cl['post_n']++; }
    }
    $cl['cert_rate'] = $cl['learners']>0?round($cl['certs']/$cl['learners']*100,1):null;
    $cl['avg_pre']   = $cl['pre_n']>0?round($cl['pre_sum']/$cl['pre_n'],1):null;
    $cl['avg_post']  = $cl['post_n']>0?round($cl['post_sum']/$cl['post_n'],1):null;
    $cl['gain']      = ($cl['avg_pre']!==null&&$cl['avg_post']!==null)?round($cl['avg_post']-$cl['avg_pre'],1):null;
    $clusterRollup[$gName] = $cl;
}

uksort($groups,function($a,$b) use($groups){
    $ac=$groups[$a]['is_cluster']?0:1; $bc=$groups[$b]['is_cluster']?0:1;
    return $ac===$bc?strcasecmp($a,$b):$ac-$bc;
});
$markersJson=json_encode($markers,JSON_UNESCAPED_UNICODE);
?>
<!DOCTYPE html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>ARISE — Projects Map</title>
<link rel="stylesheet" href="/leaflet.css">
<style>
*{box-sizing:border-box;margin:0;padding:0;}
body{font-family:'Segoe UI',Roboto,Arial,sans-serif;background:#f5f7fa;color:#1f2937;line-height:1.5;}
header{background:linear-gradient(135deg,#0a5e2a,#0ea271);color:#fff;padding:24px 20px;text-align:center;}
header h1{font-size:1.6rem;margin-bottom:4px;}
header p{font-size:.9rem;opacity:.9;}
.kpis{display:flex;gap:12px;max-width:1100px;margin:20px auto;padding:0 16px;flex-wrap:wrap;}
.kpi{background:#fff;border-radius:12px;padding:16px;flex:1 1 140px;text-align:center;box-shadow:0 1px 3px rgba(0,0,0,.05);}
.kpi .num{font-size:1.7rem;font-weight:800;color:#0a5e2a;}
.kpi .lbl{font-size:.75rem;color:#6b7280;text-transform:uppercase;letter-spacing:.04em;margin-top:4px;}
#map{height:480px;max-width:1100px;margin:16px auto;border-radius:12px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,.05);background:#e5e7eb;}
.map-cap{max-width:1100px;margin:6px auto 0;padding:0 16px;font-size:.78rem;color:#6b7280;text-align:right;}
.map-cap a{color:#6b7280;text-decoration:none;}
/* toolbar */
.toolbar{max-width:1100px;margin:16px auto 0;padding:0 16px;display:flex;gap:10px;flex-wrap:wrap;align-items:center;}
.toolbar input{flex:1;min-width:180px;padding:8px 14px;border:1px solid #d1d5db;border-radius:8px;font-size:.9rem;outline:none;}
.toolbar input:focus{border-color:#0ea271;}
.view-btns{display:flex;border:1px solid #d1d5db;border-radius:8px;overflow:hidden;}
.view-btn{padding:7px 16px;font-size:.82rem;font-weight:600;background:#fff;border:none;cursor:pointer;color:#374151;}
.view-btn.active{background:#0a5e2a;color:#fff;}
/* groups */
.groups{max-width:1100px;margin:16px auto 60px;padding:0 16px;}
.group{margin-bottom:28px;}
.group-head{display:flex;align-items:center;gap:10px;margin-bottom:12px;padding-bottom:8px;border-bottom:2px solid #d1fae5;}
.group-head h2{font-size:1.15rem;color:#0a5e2a;}
.chip{font-size:.7rem;font-weight:700;text-transform:uppercase;padding:3px 9px;border-radius:10px;}
.chip.cluster{background:#dcfce7;color:#166534;}
.chip.county{background:#fef3c7;color:#92400e;}
.cards{display:grid;grid-template-columns:repeat(auto-fill,minmax(270px,1fr));gap:14px;}
/* card */
.card{background:#fff;border-radius:10px;padding:16px;border-left:4px solid #0ea271;box-shadow:0 1px 3px rgba(0,0,0,.05);transition:box-shadow .15s;}
.card:hover{box-shadow:0 4px 12px rgba(0,0,0,.1);}
.card.stale{border-left-color:#9ca3af;background:#fafafa;}
.card.stale h3{color:#6b7280;}
.card-title{display:flex;align-items:flex-start;justify-content:space-between;gap:6px;margin-bottom:4px;}
.card h3{font-size:1rem;color:#111827;flex:1;}
.milestone-badge{font-size:1.2rem;line-height:1;}
.card .sub{font-size:.78rem;color:#6b7280;margin-bottom:10px;display:flex;gap:6px;flex-wrap:wrap;align-items:center;}
.cluster-tag{background:#dcfce7;color:#166534;font-size:.7rem;font-weight:700;padding:2px 8px;border-radius:8px;}
.metric{display:flex;justify-content:space-between;padding:3px 0;font-size:.85rem;}
.metric .lbl{color:#555;}
.metric .val{font-weight:700;color:#0a5e2a;}
.metric .val.sync{font-size:.8rem;padding:1px 8px;border-radius:10px;}
.val.fresh{background:#dcfce7;color:#166534;}
.val.stale-lbl{background:#f3f4f6;color:#6b7280;}
/* knowledge gain bar */
.kg-wrap{margin:8px 0 4px;}
.kg-labels{display:flex;justify-content:space-between;font-size:.75rem;color:#6b7280;margin-bottom:3px;}
.kg-gain-label{font-weight:700;color:#166534;}
.kg-track{height:8px;background:#f3f4f6;border-radius:4px;position:relative;overflow:hidden;}
.kg-pre-bar{position:absolute;top:0;left:0;height:100%;background:#fbbf24;border-radius:4px;}
.kg-post-bar{position:absolute;top:0;left:0;height:100%;background:#0ea271;border-radius:4px;opacity:.85;}
/* cert rate */
.cert-rate{display:inline-flex;align-items:center;gap:4px;background:#eff6ff;color:#1d4ed8;font-size:.75rem;font-weight:700;padding:2px 8px;border-radius:8px;}
/* cluster rollup */
.rollup-card{background:#fff;border-radius:10px;padding:16px;box-shadow:0 1px 3px rgba(0,0,0,.05);border-top:4px solid #0a5e2a;}
.rollup-card h3{font-size:1.05rem;color:#0a5e2a;margin-bottom:12px;}
.rollup-stats{display:grid;grid-template-columns:repeat(3,1fr);gap:8px;margin-bottom:12px;}
.rs{text-align:center;padding:8px;background:#f9fafb;border-radius:8px;}
.rs .rv{font-size:1.2rem;font-weight:800;color:#0a5e2a;}
.rs .rl{font-size:.7rem;color:#6b7280;text-transform:uppercase;}
.empty{text-align:center;padding:40px 20px;color:#6b7280;}
.err{background:#fee2e2;color:#991b1b;padding:12px 16px;border-radius:8px;max-width:1100px;margin:16px auto;}
footer{text-align:center;font-size:.78rem;color:#6b7280;padding:18px;}
.hidden{display:none!important;}
/* popup */
.leaflet-popup-content{margin:10px 14px;font-family:'Segoe UI',Roboto,Arial,sans-serif;min-width:220px;}
.leaflet-popup-content h4{font-size:1rem;color:#0a5e2a;margin-bottom:6px;}
.pop-chip{display:inline-block;font-size:.72rem;font-weight:700;padding:3px 10px;border-radius:12px;text-transform:uppercase;letter-spacing:.03em;margin-bottom:6px;}
.pop-chip.cluster{background:#dcfce7;color:#166534;}
.pop-chip.county{background:#fef3c7;color:#92400e;}
.pop-county{font-size:.72rem;color:#9ca3af;margin-bottom:8px;}
.pop-row{display:flex;justify-content:space-between;gap:14px;font-size:.84rem;padding:2px 0;}
.pop-row .l{color:#555;}.pop-row .v{font-weight:700;color:#0a5e2a;}
.pop-kg{margin:6px 0 2px;padding:6px 8px;background:#f0fdf4;border-radius:6px;font-size:.82rem;}
.pop-kg .gain{color:#166534;font-weight:700;}
@media(max-width:600px){#map{height:340px;}.kpi .num{font-size:1.4rem;}.rollup-stats{grid-template-columns:repeat(2,1fr);}}
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
  <div class="kpi"><div class="num"><?= $recentlySyncedProjects ?>/<?= $totalProjects ?></div><div class="lbl">Online &lt;2h</div></div>
  <div class="kpi"><div class="num"><?= num($totalLearners) ?></div><div class="lbl">Learners</div></div>
  <div class="kpi"><div class="num"><?= $globalCertRate!==null?pct($globalCertRate):'—' ?></div><div class="lbl">Cert Rate</div></div>
  <div class="kpi"><div class="num"><?= $globalAvgGain!==null?'+'.num($globalAvgGain,1).'pts':'—' ?></div><div class="lbl">Avg Knowledge Gain</div></div>
  <div class="kpi"><div class="num"><?= pct($globalAvgScore) ?></div><div class="lbl">Avg Quiz Score</div></div>
  <div class="kpi"><div class="num"><?= num($totalCerts) ?></div><div class="lbl">Certificates</div></div>
</section>

<div id="map"></div>
<div class="map-cap">Click any pin · &copy; <a href="https://openstreetmap.org/copyright" target="_blank" rel="noopener">OpenStreetMap</a> &copy; <a href="https://carto.com/attributions" target="_blank" rel="noopener">CARTO</a></div>

<div class="toolbar">
  <input type="search" id="search" placeholder="🔍  Search projects..." oninput="filterCards()">
  <div class="view-btns">
    <button class="view-btn active" id="btn-projects" onclick="setView('projects')">Projects</button>
    <button class="view-btn" id="btn-clusters" onclick="setView('clusters')">Clusters</button>
  </div>
</div>

<!-- Projects view -->
<section class="groups" id="view-projects">
<?php if (!$groups): ?>
  <div class="empty">No active projects yet.</div>
<?php else: foreach ($groups as $groupName => $g): ?>
  <div class="group" data-group="<?= h($groupName) ?>">
    <div class="group-head">
      <h2><?= h($groupName) ?></h2>
      <span class="chip <?= $g['is_cluster']?'cluster':'county' ?>"><?= $g['is_cluster']?'Cluster':'County' ?></span>
      <span style="color:#9ca3af;font-size:.85rem;">· <?= count($g['projects']) ?> project<?= count($g['projects'])!==1?'s':'' ?></span>
    </div>
    <div class="cards">
    <?php foreach ($g['projects'] as $p):
        $ms = milestone((int)$p['learner_count']);
        $cr = $p['_cert_rate'];
        $hasPre  = $p['avg_pre_score']!==null && (float)$p['avg_pre_score']>0;
        $hasPost = $p['avg_post_score']!==null && (float)$p['avg_post_score']>0;
    ?>
      <div class="card<?= $p['_is_stale']?' stale':'' ?>" data-name="<?= h(strtolower($p['name'])) ?>" data-cluster="<?= h(strtolower($groupName)) ?>">
        <div class="card-title">
          <h3><?= h($p['name']) ?></h3>
          <?php if ($ms): ?><span class="milestone-badge" title="<?= $ms==='🏆'?'100+ learners':($ms==='🌳'?'50+ learners':($ms==='🌿'?'25+ learners':'10+ learners')) ?>"><?= $ms ?></span><?php endif; ?>
        </div>
        <div class="sub">
          <?php if (!empty($p['cluster_name'])): ?><span class="cluster-tag">📍 <?= h($p['cluster_name']) ?></span><?php endif; ?>
          <?= h($p['county']?:'—') ?>
          <?php if ($cr!==null): ?><span class="cert-rate">🎓 <?= pct($cr) ?> certified</span><?php endif; ?>
        </div>

        <?php if ($hasPre && $hasPost): $pre=(float)$p['avg_pre_score']; $post=(float)$p['avg_post_score']; $gain=round($post-$pre,1); ?>
        <div class="kg-wrap">
          <div class="kg-labels">
            <span>Pre <?= num($pre,1) ?>%</span>
            <span class="kg-gain-label"><?= $gain>=0?'+':'' ?><?= num($gain,1) ?> pts gain</span>
            <span>Post <?= num($post,1) ?>%</span>
          </div>
          <div class="kg-track">
            <div class="kg-post-bar" style="width:<?= min(100,$post) ?>%"></div>
            <div class="kg-pre-bar"  style="width:<?= min(100,$pre) ?>%"></div>
          </div>
        </div>
        <?php endif; ?>

        <div class="metric"><span class="lbl">📟 Device</span><span class="val" style="font-family:monospace;font-size:.76rem;color:#6b7280;"><?= h($p['device_id']) ?></span></div>
        <div class="metric"><span class="lbl">👥 Learners</span><span class="val"><?= num($p['learner_count']) ?>
          <?php if (isset($p['learner_count_prev']) && $p['learner_count_prev']!==null): $d=(int)$p['learner_count']-(int)$p['learner_count_prev']; if($d>0): ?><span style="color:#166534;font-size:.78rem"> +<?= $d ?></span><?php elseif($d<0): ?><span style="color:#dc2626;font-size:.78rem"> <?= $d ?></span><?php endif; endif; ?></span></div>
        <div class="metric"><span class="lbl">🎓 Certificates</span><span class="val"><?= num($p['cert_count']) ?></span></div>
        <?php if ($p['quiz_count']>0): ?>
        <div class="metric"><span class="lbl">📝 Quiz Avg</span><span class="val"><?= pct($p['avg_score']) ?></span></div>
        <?php endif; ?>
        <?php if ($p['active_last_30_days']>0): ?>
        <div class="metric"><span class="lbl">🟢 Active (30d)</span><span class="val"><?= num($p['active_last_30_days']) ?></span></div>
        <?php endif; ?>
        <?php if ($p['_act_age']!==null): ?>
        <div class="metric"><span class="lbl">🕐 Last learner</span><span class="val" style="font-weight:400;color:#6b7280;font-size:.82rem;"><?= lastSeen($p['_act_age']) ?></span></div>
        <?php endif; ?>
        <?php if (!empty($p['avg_sync_interval_secs'])): $mins=round($p['avg_sync_interval_secs']/60,1); $col=$mins<=2?'#166534':($mins<=10?'#92400e':'#991b1b'); ?>
        <div class="metric"><span class="lbl">🔁 Sync rate</span><span class="val" style="color:<?= $col ?>;font-size:.82rem;">every ~<?= $mins ?> min</span></div>
        <?php endif; ?>
        <div class="metric"><span class="lbl">📡 Last sync</span><span class="val sync <?= $p['_is_stale']?'stale-lbl':'fresh' ?>"><?= lastSeen($p['_age_sec']) ?></span></div>
        <?php if ($p['_is_stale'] && $p['_offline_label']): ?>
        <div class="metric"><span class="lbl">🔴 Status</span><span class="val" style="color:#dc2626;"><?= h($p['_offline_label']) ?></span></div>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
    </div>
  </div>
<?php endforeach; endif; ?>
</section>

<!-- Cluster rollup view -->
<section class="groups hidden" id="view-clusters">
  <div class="cards">
  <?php foreach ($clusterRollup as $cName => $cl): ?>
  <div class="rollup-card">
    <h3>📍 <?= h($cName) ?> <span style="font-size:.8rem;color:#6b7280;font-weight:400;"><?= $cl['projects'] ?> project<?= $cl['projects']!==1?'s':'' ?> · <?= $cl['online'] ?> online</span></h3>
    <div class="rollup-stats">
      <div class="rs"><div class="rv"><?= num($cl['learners']) ?></div><div class="rl">Learners</div></div>
      <div class="rs"><div class="rv"><?= $cl['cert_rate']!==null?pct($cl['cert_rate']):'—' ?></div><div class="rl">Cert rate</div></div>
      <div class="rs"><div class="rv"><?= $cl['gain']!==null?(($cl['gain']>=0?'+':'').num($cl['gain'],1).'pts'):'—' ?></div><div class="rl">Knowledge gain</div></div>
    </div>
    <?php if ($cl['avg_pre']!==null && $cl['avg_post']!==null): ?>
    <div class="kg-wrap">
      <div class="kg-labels">
        <span>Pre <?= num($cl['avg_pre'],1) ?>%</span>
        <span class="kg-gain-label"><?= $cl['gain']>=0?'+':'' ?><?= num($cl['gain'],1) ?> pts</span>
        <span>Post <?= num($cl['avg_post'],1) ?>%</span>
      </div>
      <div class="kg-track">
        <div class="kg-post-bar" style="width:<?= min(100,$cl['avg_post']) ?>%"></div>
        <div class="kg-pre-bar"  style="width:<?= min(100,$cl['avg_pre']) ?>%"></div>
      </div>
    </div>
    <?php endif; ?>
  </div>
  <?php endforeach; ?>
  </div>
</section>

<?php endif; ?>
<footer>ARISE · live data from offline field boxes · updates every minute</footer>

<script src="/leaflet.js"></script>
<script>
// ── Map ───────────────────────────────────────────────────────────────────────
(function(){
  if(typeof L==='undefined'){document.getElementById('map').innerHTML='<div style="display:flex;align-items:center;justify-content:center;height:100%;color:#6b7280;">Map unavailable</div>';return;}
  var markers=<?= $markersJson?:'[]' ?>;
  var map=L.map('map',{scrollWheelZoom:true}).setView([0.5,37.5],6);
  L.tileLayer('https://{s}.basemaps.cartocdn.com/light_all/{z}/{x}/{y}.png',{maxZoom:19,subdomains:'abcd',attribution:''}).addTo(map);
  function esc(s){return String(s==null?'':s).replace(/[&<>"']/g,function(c){return({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'})[c];});}
  function fmt(n){return n==null?'—':Number(n).toLocaleString();}
  function pc(n,dp){return n==null?'—':Number(n).toFixed(dp||1)+'%';}
  if(!markers.length)return;
  var grp=L.featureGroup();
  markers.forEach(function(m){
    // Pin size scales with learner count
    var r = m.learners>=100?13:(m.learners>=50?11:(m.learners>=25?10:(m.learners>=10?9:8)));
    var color=m.stale?'#9ca3af':'#0ea271';
    // Knowledge gain popup section
    var kgHtml='';
    if(m.pre_score!==null&&m.post_score!==null){
      var gain=(m.post_score-m.pre_score).toFixed(1);
      var pct_pre=Math.min(100,m.pre_score);
      var pct_post=Math.min(100,m.post_score);
      kgHtml='<div class="pop-kg">📈 Pre <b>'+pc(m.pre_score)+'</b> → Post <b>'+pc(m.post_score)+'</b> <span class="gain">'+(gain>=0?'+':'')+gain+' pts</span>'
        +'<div style="height:6px;background:#f3f4f6;border-radius:3px;margin-top:4px;position:relative;overflow:hidden;">'
        +'<div style="position:absolute;top:0;left:0;height:100%;width:'+pct_post+'%;background:#0ea271;opacity:.85;border-radius:3px;"></div>'
        +'<div style="position:absolute;top:0;left:0;height:100%;width:'+pct_pre+'%;background:#fbbf24;border-radius:3px;"></div>'
        +'</div></div>';
    }
    var rows=[
      ['📟 Device','<span style="font-family:monospace;font-size:.78em;color:#6b7280;">'+esc(m.device_id)+'</span>'],
      ['👥 Learners', fmt(m.learners)+(m.milestone?' '+m.milestone:'')+(m.learner_delta>0?' <span style="color:#166534">+'+m.learner_delta+'</span>':(m.learner_delta<0?' <span style="color:#dc2626">'+m.learner_delta+'</span>':''))],
      m.cert_rate!==null?['🎓 Cert rate','<b style="color:#1d4ed8">'+pc(m.cert_rate)+'</b> ('+fmt(m.cert_count)+')']:null,
      ['📝 Quiz avg', m.quiz_count>0?pc(m.avg_score):'—'],
      m.active_30>0?['🟢 Active 30d',fmt(m.active_30)]:null,
      ['🕐 Last learner', m.last_activity],
      m.sync_interval?['🔁 Sync rate','every ~'+m.sync_interval+' min']:null,
      ['📡 Last sync', m.last_seen],
      m.stale&&m.offline_label?['🔴 Status','<span style="color:#dc2626;font-weight:700;">'+esc(m.offline_label)+'</span>']:null,
    ].filter(Boolean);
    var html='<h4>'+esc(m.name)+(m.milestone?' '+m.milestone:'')+'</h4>';
    if(m.is_cluster){html+='<div><span class="pop-chip cluster">🏷 '+esc(m.cluster)+'</span></div>';
      if(m.county&&m.county!==m.cluster)html+='<div class="pop-county">'+esc(m.county)+' county</div>';}
    else if(m.county){html+='<div><span class="pop-chip county">📍 '+esc(m.county)+' county</span></div>';}
    html+=kgHtml;
    rows.forEach(function(r){html+='<div class="pop-row"><span class="l">'+r[0]+'</span><span class="v">'+r[1]+'</span></div>';});
    L.circleMarker([m.lat,m.lng],{radius:r,color:'#fff',weight:2,fillColor:color,fillOpacity:m.stale?.65:.95})
      .bindPopup(html,{maxWidth:300}).addTo(grp);
  });
  grp.addTo(map);
  if(markers.length===1){map.setView([markers[0].lat,markers[0].lng],10);}
  else{map.fitBounds(grp.getBounds().pad(0.2));}
})();

// ── Search filter ─────────────────────────────────────────────────────────────
function filterCards(){
  var q=document.getElementById('search').value.toLowerCase().trim();
  document.querySelectorAll('#view-projects .card').forEach(function(c){
    var match=!q||c.dataset.name.includes(q)||c.dataset.cluster.includes(q);
    c.closest('.group') && (c.style.display=match?'':'none');
  });
  // hide empty groups
  document.querySelectorAll('#view-projects .group').forEach(function(g){
    var visible=[].slice.call(g.querySelectorAll('.card')).some(function(c){return c.style.display!=='none';});
    g.style.display=visible?'':'none';
  });
}

// ── View toggle ───────────────────────────────────────────────────────────────
function setView(v){
  document.getElementById('view-projects').classList.toggle('hidden',v!=='projects');
  document.getElementById('view-clusters').classList.toggle('hidden',v!=='clusters');
  document.getElementById('btn-projects').classList.toggle('active',v==='projects');
  document.getElementById('btn-clusters').classList.toggle('active',v==='clusters');
}
</script>
</body>
</html>
