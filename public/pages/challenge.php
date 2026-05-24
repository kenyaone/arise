<?php
trackPageView('challenge');
$student = getStudentBySession();

// Handle submission
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['response_text']) && $student) {
    $cid = intval($_POST['challenge_id']);
    $text = trim($_POST['response_text']);
    if ($cid && strlen($text) >= 10) {
        $already = db()->querySingle("SELECT id FROM challenge_responses WHERE challenge_id=$cid AND session_hash='".SQLite3::escapeString(getSessionHash())."'");
        if (!$already) {
            $st = db()->prepare("INSERT INTO challenge_responses (challenge_id,student_id,session_hash,student_name,response_text,word_count) VALUES (:c,:s,:h,:n,:t,:w)");
            $st->bindValue(':c',$cid); $st->bindValue(':s',$student['id']); $st->bindValue(':h',getSessionHash());
            $st->bindValue(':n',$student['full_name']); $st->bindValue(':t',$text); $st->bindValue(':w',str_word_count($text));
            $st->execute();
        }
        header('Location: /arise/?p=challenge&done=1'); exit;
    }
}

$challenge = db()->querySingle("SELECT * FROM weekly_challenges WHERE is_active=1 AND date('now') BETWEEN week_start AND week_end ORDER BY id DESC LIMIT 1", true);
if (!$challenge) $challenge = db()->querySingle("SELECT * FROM weekly_challenges WHERE is_active=1 ORDER BY id DESC LIMIT 1", true);

$responses = $challenge ? db()->query("SELECT student_name,response_text,submitted_at FROM challenge_responses WHERE challenge_id=".intval($challenge['id'])." ORDER BY submitted_at DESC LIMIT 20") : null;
$myResp = ($challenge && $student) ? db()->querySingle("SELECT id FROM challenge_responses WHERE challenge_id=".intval($challenge['id'])." AND session_hash='".SQLite3::escapeString(getSessionHash())."'") : null;
?>
<div class="container">
  <h1 class="page-title">💪 Weekly Challenge</h1>
  <?php if (isset($_GET['done'])): ?>
    <div class="notif-banner"><div class="notif-banner-icon">✅</div><div class="notif-banner-body"><div class="notif-banner-title">Response submitted!</div><div class="notif-banner-text">Thanks for participating in this week's challenge.</div></div></div>
  <?php endif; ?>
  <?php if ($challenge): ?>
    <div class="challenge-card">
      <div class="challenge-week">📅 <?= date('M j',strtotime($challenge['week_start'])) ?> – <?= date('M j, Y',strtotime($challenge['week_end'])) ?></div>
      <div class="challenge-title"><?= htmlspecialchars($challenge['title']) ?></div>
      <div class="challenge-desc"><?= nl2br(htmlspecialchars($challenge['description'])) ?></div>
    </div>
    <?php if (!$student): ?>
      <div class="dp-card" style="text-align:center"><p>Please <a href="/arise/?p=login">sign in</a> or <a href="/arise/?p=register">register</a> to participate.</p></div>
    <?php elseif ($myResp): ?>
      <div class="notif-banner"><div class="notif-banner-icon">✅</div><div class="notif-banner-body"><div class="notif-banner-title">You've already responded this week!</div><div class="notif-banner-text">See what others shared below.</div></div></div>
    <?php else: ?>
      <form method="post" class="dp-card">
        <input type="hidden" name="challenge_id" value="<?= $challenge['id'] ?>">
        <label style="font-weight:700;display:block;margin-bottom:8px">Your Response</label>
        <textarea name="response_text" class="essay-textarea" placeholder="Share your thoughts... (at least 10 characters)" required minlength="10"></textarea>
        <button type="submit" class="btn btn-primary" style="margin-top:12px">Submit Response</button>
      </form>
    <?php endif; ?>
    <h3 style="font-size:.95rem;font-weight:800;margin:20px 0 10px">Responses from the community</h3>
    <?php if ($responses): $count=0; while($r=$responses->fetchArray(SQLITE3_ASSOC)): $count++; ?>
      <div class="challenge-resp-item">
        <div class="crp-name">🌿 <?= htmlspecialchars($r['student_name']) ?> &middot; <span style="font-weight:400;color:#999"><?= date('M j',strtotime($r['submitted_at'])) ?></span></div>
        <div class="crp-text"><?= nl2br(htmlspecialchars($r['response_text'])) ?></div>
      </div>
    <?php endwhile; if(!$count): ?><div class="dp-card" style="text-align:center;color:#999">No responses yet — be the first!</div><?php endif; ?>
    <?php else: ?><div class="dp-card" style="text-align:center;color:#999">No responses yet — be the first!</div><?php endif; ?>
  <?php else: ?>
    <div class="dp-card" style="text-align:center"><div style="font-size:2rem;margin-bottom:10px">🗓️</div><p>No active challenge this week. Check back soon!</p></div>
  <?php endif; ?>
</div>
