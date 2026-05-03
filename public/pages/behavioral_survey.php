<?php
/**
 * ARISE — Behavioral Survey (PUBLIC page)
 * URL: /arise/?p=survey&module=SLUG
 * Shown after completing a module's post-test or all lessons.
 */

$moduleSlug = $_GET['module'] ?? '';
$module = $moduleSlug
    ? db()->querySingle(
        "SELECT * FROM modules WHERE slug='" . SQLite3::escapeString($moduleSlug) . "' AND is_active=1",
        true
      )
    : null;

if (!$module) {
    echo '<div class="container"><div class="alert">Module not found.</div></div>';
    return;
}

$sid  = getStudentId();
$hash = getSessionHash();
$mid  = intval($module['id']);

// ── Check for existing submission ───────────────────────────────────────────
$alreadyDone = db()->querySingle(
    "SELECT id FROM behavioral_surveys
     WHERE session_hash='" . SQLite3::escapeString($hash) . "'
       AND module_id=$mid
     LIMIT 1"
);

// ── POST handler ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$alreadyDone) {
    $q1c = isset($_POST['q1_changed']) ? intval($_POST['q1_changed']) : null;
    $q1d = trim($_POST['q1_detail'] ?? '');
    $q2s = isset($_POST['q2_shared']) ? intval($_POST['q2_shared']) : null;
    $q2d = trim($_POST['q2_detail'] ?? '');
    $q3c = isset($_POST['q3_confident']) ? intval($_POST['q3_confident']) : null;
    $q3d = trim($_POST['q3_detail'] ?? '');

    if ($q1c !== null && $q2s !== null && $q3c !== null) {
        $st = db()->prepare(
            "INSERT INTO behavioral_surveys
                (student_id, session_hash, module_id,
                 q1_changed, q1_detail,
                 q2_shared,  q2_detail,
                 q3_confident, q3_detail)
             VALUES (:sid,:hash,:mid,:q1c,:q1d,:q2s,:q2d,:q3c,:q3d)"
        );
        $st->bindValue(':sid',  $sid ?? 0,  SQLITE3_INTEGER);
        $st->bindValue(':hash', $hash,       SQLITE3_TEXT);
        $st->bindValue(':mid',  $mid,        SQLITE3_INTEGER);
        $st->bindValue(':q1c',  $q1c,        SQLITE3_INTEGER);
        $st->bindValue(':q1d',  $q1d,        SQLITE3_TEXT);
        $st->bindValue(':q2s',  $q2s,        SQLITE3_INTEGER);
        $st->bindValue(':q2d',  $q2d,        SQLITE3_TEXT);
        $st->bindValue(':q3c',  $q3c,        SQLITE3_INTEGER);
        $st->bindValue(':q3d',  $q3d,        SQLITE3_TEXT);
        $st->execute();

        header('Location: /arise/?p=module&slug=' . urlencode($moduleSlug));
        exit;
    }
    // Validation failed — fall through to show form with error
    $formError = 'Please answer all three questions before submitting.';
}

?>
<style>
.survey-wrap { max-width: 640px; margin: 0 auto; }
.survey-q {
    background: #fff;
    border: 1px solid #e5e7eb;
    border-radius: 14px;
    padding: 20px 22px;
    margin-bottom: 16px;
    box-shadow: 0 1px 4px rgba(0,0,0,.04);
}
.survey-q .q-label {
    font-size: .92rem;
    font-weight: 700;
    color: #111;
    margin-bottom: 14px;
    line-height: 1.4;
}
.survey-q .q-num {
    display: inline-block;
    width: 26px; height: 26px;
    background: var(--green, #0ea271);
    color: #fff;
    border-radius: 50%;
    text-align: center;
    line-height: 26px;
    font-size: .8rem;
    font-weight: 800;
    margin-right: 8px;
    flex-shrink: 0;
}
.radio-row {
    display: flex;
    gap: 10px;
    margin-bottom: 12px;
}
.radio-pill {
    flex: 1;
    text-align: center;
}
.radio-pill input[type=radio] { display: none; }
.radio-pill label {
    display: block;
    padding: 9px 0;
    border: 2px solid #e5e7eb;
    border-radius: 10px;
    font-size: .88rem;
    font-weight: 600;
    cursor: pointer;
    color: #374151;
    transition: border-color .15s, background .15s;
    user-select: none;
}
.radio-pill input:checked + label {
    border-color: var(--green, #0ea271);
    background: #f0fdf4;
    color: #065f46;
}
.radio-pill.no input:checked + label {
    border-color: #f59e0b;
    background: #fffbeb;
    color: #92400e;
}
.detail-area {
    margin-top: 4px;
}
.detail-area textarea {
    width: 100%;
    border: 1px solid #e5e7eb;
    border-radius: 8px;
    padding: 9px 12px;
    font-size: .84rem;
    font-family: inherit;
    resize: vertical;
    min-height: 64px;
    color: #374151;
    box-sizing: border-box;
}
.detail-area textarea:focus {
    outline: none;
    border-color: var(--green, #0ea271);
    box-shadow: 0 0 0 3px rgba(14,162,113,.1);
}
.survey-header {
    text-align: center;
    margin-bottom: 24px;
}
.survey-header .icon-wrap {
    width: 58px; height: 58px;
    background: linear-gradient(135deg,#0ea271,#f59e0b);
    border-radius: 16px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 1.6rem;
    margin-bottom: 10px;
    box-shadow: 0 4px 14px rgba(14,162,113,.25);
}
.survey-header h2 {
    font-size: 1.15rem;
    font-weight: 800;
    color: #111;
    margin-bottom: 4px;
}
.survey-header p {
    font-size: .84rem;
    color: #6b7280;
}
.submit-btn {
    width: 100%;
    padding: 15px;
    background: linear-gradient(135deg,#0ea271,#059669);
    color: #fff;
    border: none;
    border-radius: 12px;
    font-size: 1rem;
    font-weight: 700;
    cursor: pointer;
    font-family: inherit;
    transition: transform .15s, box-shadow .15s;
    margin-top: 4px;
}
.submit-btn:hover {
    transform: translateY(-1px);
    box-shadow: 0 6px 20px rgba(14,162,113,.3);
}
.already-done {
    text-align: center;
    padding: 32px 24px;
}
.already-done .big-check {
    font-size: 2.8rem;
    margin-bottom: 12px;
}
.already-done h3 {
    font-size: 1.15rem;
    font-weight: 800;
    color: #065f46;
    margin-bottom: 6px;
}
.already-done p {
    font-size: .88rem;
    color: #6b7280;
    margin-bottom: 18px;
}
</style>

<div class="container">
  <div class="breadcrumb">
    <a href="/arise/">Home</a>
    <span class="sep">&#8250;</span>
    <a href="/arise/?p=module&slug=<?= e($moduleSlug) ?>"><?= e($module['title']) ?></a>
    <span class="sep">&#8250;</span>
    <span>Reflection Survey</span>
  </div>

  <div class="survey-wrap">

    <?php if ($alreadyDone): ?>
    <!-- Already submitted -->
    <div class="dp-card already-done">
      <div class="big-check">&#10003;</div>
      <h3>Survey already submitted</h3>
      <p>Thank you — your reflection has been recorded for this module.</p>
      <a href="/arise/?p=module&slug=<?= e($moduleSlug) ?>" class="btn btn-primary">
        &#8592; Back to Module
      </a>
    </div>

    <?php else: ?>

    <div class="survey-header">
      <div class="icon-wrap">&#128172;</div>
      <h2>Reflection Survey</h2>
      <p>
        <?= e($module['icon'] ?? '') ?> <?= e($module['title']) ?> &mdash;
        Quick 3-question check-in
      </p>
    </div>

    <?php if (!empty($formError)): ?>
      <div class="alert" style="background:#fef2f2;color:#991b1b;border:1px solid #fecaca;border-radius:10px;padding:12px 16px;margin-bottom:16px;font-size:.88rem;font-weight:600;">
        &#9888; <?= e($formError) ?>
      </div>
    <?php endif; ?>

    <form method="POST" id="surveyForm">
      <input type="hidden" name="module_id" value="<?= $mid ?>">

      <!-- Q1 -->
      <div class="survey-q">
        <div class="q-label">
          <span class="q-num">1</span>
          Since starting this module, have you made any changes to your behaviour or habits?
        </div>
        <div class="radio-row">
          <div class="radio-pill yes">
            <input type="radio" name="q1_changed" id="q1_yes" value="1" required>
            <label for="q1_yes">&#10003; Yes</label>
          </div>
          <div class="radio-pill no">
            <input type="radio" name="q1_changed" id="q1_no" value="0" required>
            <label for="q1_no">&#10007; No</label>
          </div>
        </div>
        <div class="detail-area">
          <textarea name="q1_detail" placeholder="Optional: tell us more..." rows="2"></textarea>
        </div>
      </div>

      <!-- Q2 -->
      <div class="survey-q">
        <div class="q-label">
          <span class="q-num">2</span>
          Have you shared what you learned with a friend, family member, or peer?
        </div>
        <div class="radio-row">
          <div class="radio-pill yes">
            <input type="radio" name="q2_shared" id="q2_yes" value="1" required>
            <label for="q2_yes">&#10003; Yes</label>
          </div>
          <div class="radio-pill no">
            <input type="radio" name="q2_shared" id="q2_no" value="0" required>
            <label for="q2_no">&#10007; No</label>
          </div>
        </div>
        <div class="detail-area">
          <textarea name="q2_detail" placeholder="Optional: who did you share with and what did you share?" rows="2"></textarea>
        </div>
      </div>

      <!-- Q3 -->
      <div class="survey-q">
        <div class="q-label">
          <span class="q-num">3</span>
          Do you feel more confident handling situations related to this topic?
        </div>
        <div class="radio-row">
          <div class="radio-pill yes">
            <input type="radio" name="q3_confident" id="q3_yes" value="1" required>
            <label for="q3_yes">&#10003; Yes</label>
          </div>
          <div class="radio-pill no">
            <input type="radio" name="q3_confident" id="q3_no" value="0" required>
            <label for="q3_no">&#10007; No</label>
          </div>
        </div>
        <div class="detail-area">
          <textarea name="q3_detail" placeholder="Optional: what still feels uncertain or difficult?" rows="2"></textarea>
        </div>
      </div>

      <button type="submit" class="submit-btn">
        &#128172; Submit Reflection
      </button>

      <div style="text-align:center;margin-top:12px;">
        <a href="/arise/?p=module&slug=<?= e($moduleSlug) ?>" style="font-size:.82rem;color:#9ca3af;">
          Skip for now &rarr;
        </a>
      </div>
    </form>

    <?php endif; ?>

  </div><!-- /survey-wrap -->
</div><!-- /container -->

<script>
// Highlight selected radio pills on change
document.querySelectorAll('.radio-pill input[type=radio]').forEach(function(radio) {
    radio.addEventListener('change', function() {
        var name = this.name;
        document.querySelectorAll('input[name="' + name + '"]').forEach(function(r) {
            r.closest('.radio-pill').querySelector('label').style.fontWeight = '';
        });
    });
});
</script>
