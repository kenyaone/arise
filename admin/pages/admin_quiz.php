<?php
/**
 * ARISE Admin — Quiz Question Builder
 */
$auth_ok = isset($_SESSION['arise_admin_id']);
if (!$auth_ok) { echo '<div class="alert alert-danger">Not logged in.</div>'; return; }

$msg = '';
$importCount = null;
$moduleId = intval($_GET['module_id'] ?? 0);

// Handle POST
if ($_SERVER['REQUEST_METHOD']==='POST') {
    $action = $_POST['action'] ?? '';

    // ── CSV import ────────────────────────────────────────────────────────────
    if ($action === 'import_questions') {
        $csvContent = '';
        if (!empty($_FILES['csv_file']['tmp_name']) && $_FILES['csv_file']['error'] === UPLOAD_ERR_OK) {
            $csvContent = file_get_contents($_FILES['csv_file']['tmp_name']);
        } elseif (!empty($_POST['csv_text'])) {
            $csvContent = $_POST['csv_text'];
        }

        if ($csvContent) {
            // Build module lookup: title => id
            $modLookup = [];
            $mlr = db()->query("SELECT id, title FROM modules WHERE is_active=1");
            while ($mr = $mlr->fetchArray(SQLITE3_ASSOC)) {
                $modLookup[strtolower(trim($mr['title']))] = $mr['id'];
                $modLookup[$mr['id']] = $mr['id']; // also by numeric id
            }

            $lines = preg_split('/\r?\n/', trim($csvContent));
            $imported = 0;
            $skipped  = 0;
            $headerSkipped = false;

            foreach ($lines as $line) {
                $line = trim($line);
                if (!$line) continue;

                $cols = str_getcsv($line);

                // Detect & skip header row
                if (!$headerSkipped) {
                    $first = strtolower(trim($cols[0] ?? ''));
                    if (in_array($first, ['module_id','module_title','module'])) {
                        $headerSkipped = true;
                        continue;
                    }
                    $headerSkipped = true; // treat first row as data if not a header keyword
                }

                // Expected columns: module_id/module_title, question_type, question,
                //                   option_a, option_b, option_c, option_d, correct_option, explanation
                if (count($cols) < 3) { $skipped++; continue; }

                $colModule   = trim($cols[0] ?? '');
                $colQtype    = strtolower(trim($cols[1] ?? 'mcq'));
                $colQuestion = trim($cols[2] ?? '');
                $colOptA     = trim($cols[3] ?? '');
                $colOptB     = trim($cols[4] ?? '');
                $colOptC     = trim($cols[5] ?? '');
                $colOptD     = trim($cols[6] ?? '');
                $colCorrect  = strtolower(trim($cols[7] ?? ''));
                $colExpl     = trim($cols[8] ?? '');

                if (!$colQuestion) { $skipped++; continue; }

                // Resolve module id
                $resolvedMid = 0;
                if (is_numeric($colModule)) {
                    $resolvedMid = intval($colModule);
                } else {
                    $resolvedMid = $modLookup[strtolower($colModule)] ?? 0;
                }
                if (!$resolvedMid) { $skipped++; continue; }

                if (!in_array($colQtype, ['mcq','essay'])) $colQtype = 'mcq';

                $st = db()->prepare(
                    "INSERT INTO quiz_questions
                     (module_id, question_type, question, option_a, option_b, option_c, option_d, correct_option, explanation, sort_order)
                     VALUES (:m,:qt,:q,:a,:b,:c,:d,:co,:ex,
                       (SELECT COALESCE(MAX(sort_order),0)+1 FROM quiz_questions WHERE module_id=:m2))"
                );
                $st->bindValue(':m',   $resolvedMid, SQLITE3_INTEGER);
                $st->bindValue(':m2',  $resolvedMid, SQLITE3_INTEGER);
                $st->bindValue(':qt',  $colQtype,  SQLITE3_TEXT);
                $st->bindValue(':q',   $colQuestion, SQLITE3_TEXT);
                $st->bindValue(':a',   $colOptA,   SQLITE3_TEXT);
                $st->bindValue(':b',   $colOptB,   SQLITE3_TEXT);
                $st->bindValue(':c',   $colOptC,   SQLITE3_TEXT);
                $st->bindValue(':d',   $colOptD,   SQLITE3_TEXT);
                $st->bindValue(':co',  $colCorrect, SQLITE3_TEXT);
                $st->bindValue(':ex',  $colExpl,   SQLITE3_TEXT);
                $st->execute();
                $imported++;
            }
            $importCount = $imported;
            if ($skipped > 0) {
                $msg = "&#9989; Imported $imported question".($imported!=1?'s':'').". ($skipped row".($skipped!=1?'s':'')." skipped — missing question text or unknown module)";
            } else {
                $msg = "&#9989; Successfully imported $imported question".($imported!=1?'s':'').".";
            }
            ariseAuditLog('import_quiz_questions','quiz_questions',0,"CSV import: $imported questions");
        } else {
            $msg = '&#10060; No CSV data provided. Please upload a file or paste CSV text.';
        }
    }

    if ($action === 'add') {
        $mid = intval($_POST['module_id']);
        $st = db()->prepare("INSERT INTO quiz_questions (module_id,question_type,question,option_a,option_b,option_c,option_d,correct_option,explanation,sort_order) VALUES (:m,:qt,:q,:a,:b,:c,:d,:co,:ex,(SELECT COALESCE(MAX(sort_order),0)+1 FROM quiz_questions WHERE module_id=:m2))");
        $st->bindValue(':m',$mid);$st->bindValue(':m2',$mid);
        $st->bindValue(':qt',$_POST['question_type']??'mcq');
        $st->bindValue(':q',trim($_POST['question']??''));
        $st->bindValue(':a',trim($_POST['option_a']??''));$st->bindValue(':b',trim($_POST['option_b']??''));
        $st->bindValue(':c',trim($_POST['option_c']??''));$st->bindValue(':d',trim($_POST['option_d']??''));
        $st->bindValue(':co',trim($_POST['correct_option']??''));
        $st->bindValue(':ex',trim($_POST['explanation']??''));
        $st->execute();
        ariseAuditLog('add_quiz_question','modules',$mid,'Added question to module '.$mid);
        $msg = '✅ Question added!';
    } elseif ($action === 'delete') {
        $qid = intval($_POST['question_id']);
        db()->exec("DELETE FROM quiz_questions WHERE id=$qid");
        ariseAuditLog('delete_quiz_question','quiz_questions',$qid,'');
        $msg = '🗑 Question deleted.';
    }
    $moduleId = intval($_POST['module_id'] ?? $moduleId);
}

$modules = db()->query("SELECT id,title,icon FROM modules WHERE is_active=1 ORDER BY sort_order");
$modList = []; while($r=$modules->fetchArray(SQLITE3_ASSOC)) $modList[]=$r;
$questions = $moduleId ? db()->query("SELECT * FROM quiz_questions WHERE module_id=$moduleId ORDER BY sort_order") : null;
$qList = []; if($questions) while($r=$questions->fetchArray(SQLITE3_ASSOC)) $qList[]=$r;

// Shared input style
$inputStyle = 'width:100%;padding:8px;border:1px solid #d1d5db;border-radius:6px;font-size:.9rem;font-family:inherit;box-sizing:border-box;';
?>
<div style="padding:0 0 30px">
<h4>🧠 Quiz Question Builder</h4>
<?php if($msg): ?><div class="alert alert-success"><?=$msg?></div><?php endif; ?>

<!-- Two-column layout: question list on left, add form on right -->
<div style="display:flex;gap:16px;flex-wrap:wrap;align-items:flex-start;">

  <!-- Left column: Module selector + Stats summary -->
  <div style="flex:0 0 280px;min-width:220px;max-width:320px;">
    <div class="dp-card">
      <form method="get">
        <input type="hidden" name="p" value="quiz">
        <label style="display:block;font-weight:700;font-size:.85rem;margin-bottom:8px;">Select Module</label>
        <select name="module_id"
                style="<?=$inputStyle?>margin-bottom:10px;"
                onchange="this.form.submit()">
          <option value="">— Choose module —</option>
          <?php foreach($modList as $m): ?>
            <option value="<?=$m['id']?>" <?=$m['id']==$moduleId?'selected':''?>><?=$m['icon']?> <?=htmlspecialchars($m['title'])?></option>
          <?php endforeach; ?>
        </select>
      </form>

      <?php if($moduleId): ?>
        <?php
          $mcqCount   = count(array_filter($qList, fn($q) => $q['question_type']==='mcq'));
          $essayCount = count(array_filter($qList, fn($q) => $q['question_type']==='essay'));
          $total      = count($qList);
        ?>
        <div style="background:#f0fdf4;border-radius:10px;padding:14px;border:1px solid #bbf7d0;">
          <div style="font-size:2rem;font-weight:900;color:#065f46;line-height:1;"><?=$total?></div>
          <div style="font-size:.72rem;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.4px;margin-bottom:10px;">
            Questions in this module
          </div>
          <div style="display:flex;gap:8px;">
            <span style="background:#fff;border:1px solid #bbf7d0;border-radius:6px;padding:4px 10px;font-size:.78rem;font-weight:700;color:#065f46;">
              MCQ: <?=$mcqCount?>
            </span>
            <span style="background:#fff;border:1px solid #ddd6fe;border-radius:6px;padding:4px 10px;font-size:.78rem;font-weight:700;color:#4c1d95;">
              Essay: <?=$essayCount?>
            </span>
          </div>
        </div>
        <?php if($total > 0): ?>
        <div style="margin-top:12px;">
          <a href="?p=reports&module_id=<?=$moduleId?>"
             style="font-size:.78rem;color:#0ea271;text-decoration:underline;">
            View difficulty report &rarr;
          </a>
        </div>
        <?php endif; ?>
      <?php endif; ?>
    </div>
  </div>

  <!-- Right column: Add question form -->
  <div style="flex:1;min-width:280px;">
    <div class="dp-card">
      <h5 style="font-weight:700;margin-bottom:16px;">Add New Question</h5>
      <form method="post">
        <input type="hidden" name="action" value="add">
        <input type="hidden" name="module_id" value="<?=$moduleId?>">

        <!-- Module (mirrored in form for POST) -->
        <div style="margin-bottom:12px;">
          <label style="display:block;font-weight:700;font-size:.85rem;margin-bottom:6px;">Module</label>
          <select name="module_id" style="<?=$inputStyle?>" required>
            <option value="">— Select —</option>
            <?php foreach($modList as $m): ?>
              <option value="<?=$m['id']?>" <?=$m['id']==$moduleId?'selected':''?>><?=$m['icon']?> <?=htmlspecialchars($m['title'])?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <!-- Question Type -->
        <div style="margin-bottom:12px;">
          <label style="display:block;font-weight:700;font-size:.85rem;margin-bottom:6px;">Question Type</label>
          <select name="question_type" id="qtype" style="<?=$inputStyle?>"
                  onchange="toggleEssay(this.value)">
            <option value="mcq">Multiple Choice (MCQ)</option>
            <option value="essay">Essay / Open Response</option>
          </select>
        </div>

        <!-- Question Text -->
        <div style="margin-bottom:12px;">
          <label style="display:block;font-weight:700;font-size:.85rem;margin-bottom:6px;">Question Text</label>
          <textarea name="question" rows="3" required
                    placeholder="Enter the question..."
                    style="<?=$inputStyle?>resize:vertical;"></textarea>
        </div>

        <!-- MCQ Fields -->
        <div id="mcqFields">
          <!-- Options A & B side by side -->
          <div style="display:flex;gap:10px;margin-bottom:10px;flex-wrap:wrap;">
            <div style="flex:1;min-width:140px;">
              <label style="display:block;font-size:.82rem;margin-bottom:4px;">Option A</label>
              <input type="text" name="option_a" placeholder="Option A" style="<?=$inputStyle?>">
            </div>
            <div style="flex:1;min-width:140px;">
              <label style="display:block;font-size:.82rem;margin-bottom:4px;">Option B</label>
              <input type="text" name="option_b" placeholder="Option B" style="<?=$inputStyle?>">
            </div>
          </div>
          <!-- Options C & D side by side -->
          <div style="display:flex;gap:10px;margin-bottom:10px;flex-wrap:wrap;">
            <div style="flex:1;min-width:140px;">
              <label style="display:block;font-size:.82rem;margin-bottom:4px;">Option C</label>
              <input type="text" name="option_c" placeholder="Option C" style="<?=$inputStyle?>">
            </div>
            <div style="flex:1;min-width:140px;">
              <label style="display:block;font-size:.82rem;margin-bottom:4px;">Option D</label>
              <input type="text" name="option_d" placeholder="Option D" style="<?=$inputStyle?>">
            </div>
          </div>
          <!-- Correct Answer -->
          <div style="margin-bottom:12px;">
            <label style="display:block;font-weight:700;font-size:.85rem;margin-bottom:6px;">Correct Answer</label>
            <select name="correct_option" style="<?=$inputStyle?>">
              <option value="a">A</option>
              <option value="b">B</option>
              <option value="c">C</option>
              <option value="d">D</option>
            </select>
          </div>
        </div>

        <!-- Explanation -->
        <div style="margin-bottom:16px;">
          <label style="display:block;font-size:.85rem;margin-bottom:6px;">Explanation <span style="font-weight:400;color:#9ca3af;">(shown after quiz)</span></label>
          <textarea name="explanation" rows="2"
                    placeholder="Explain the correct answer..."
                    style="<?=$inputStyle?>resize:vertical;"></textarea>
        </div>

        <!-- Submit -->
        <?php if($moduleId): ?>
          <button type="submit" class="btn btn-primary" style="width:100%;">Add Question</button>
        <?php else: ?>
          <button type="submit" class="btn btn-primary"
                  style="width:100%;opacity:.5;cursor:not-allowed;" disabled>Add Question</button>
          <div style="text-align:center;font-size:.82rem;color:#9ca3af;margin-top:6px;">Select a module first</div>
        <?php endif; ?>

      </form>
    </div>
  </div>

</div>
</div>

<!-- ── CSV Import card ────────────────────────────────────────────────────── -->
<div class="dp-card" style="margin-top:24px;border-left:4px solid #16a34a;">
  <h5 style="font-weight:700;font-size:.95rem;margin-bottom:4px;">&#128196; Bulk Import Questions via CSV</h5>
  <p style="font-size:.82rem;color:#6b7280;margin-bottom:14px;">
    Upload a CSV file or paste rows below. Required columns:
    <code style="background:#f0fdf4;padding:2px 6px;border-radius:4px;font-size:.8rem;">module_id&nbsp;or&nbsp;module_title, question_type, question, option_a, option_b, option_c, option_d, correct_option, explanation</code>
  </p>

  <?php if ($importCount !== null): ?>
  <div style="background:#f0fdf4;border:1px solid #86efac;border-radius:8px;padding:10px 14px;margin-bottom:14px;font-size:.88rem;color:#166534;font-weight:600;">
    &#9989; <?= intval($importCount) ?> question<?= $importCount!=1?'s':'' ?> imported successfully.
  </div>
  <?php endif; ?>

  <!-- Template download -->
  <div style="margin-bottom:14px;">
    <a href="data:text/csv;charset=utf-8,<?= rawurlencode("module_title,question_type,question,option_a,option_b,option_c,option_d,correct_option,explanation\nHealth Basics,mcq,What is the safest response to peer pressure?,Say no firmly,Ignore them,Walk away,All of the above,d,All three strategies help resist peer pressure.\n") ?>"
       download="quiz_import_template.csv"
       style="display:inline-flex;align-items:center;gap:6px;font-size:.82rem;font-weight:700;color:#059669;text-decoration:underline;">
      &#11015; Download CSV Template
    </a>
    <span style="font-size:.78rem;color:#9ca3af;margin-left:10px;">Includes a sample row with all columns</span>
  </div>

  <form method="post" enctype="multipart/form-data">
    <input type="hidden" name="action" value="import_questions">

    <div style="display:flex;gap:14px;flex-wrap:wrap;margin-bottom:12px;align-items:flex-end;">
      <div>
        <label style="display:block;font-size:.78rem;font-weight:700;color:#6b7280;margin-bottom:5px;text-transform:uppercase;letter-spacing:.4px;">Upload CSV File</label>
        <input type="file" name="csv_file" accept=".csv,.txt"
               style="font-size:.85rem;padding:6px 0;">
      </div>
    </div>

    <label style="display:block;font-size:.78rem;font-weight:700;color:#6b7280;margin-bottom:5px;text-transform:uppercase;letter-spacing:.4px;">
      Or Paste CSV Rows
    </label>
    <textarea name="csv_text" rows="5"
              style="<?=$inputStyle?>font-family:monospace;font-size:.8rem;resize:vertical;margin-bottom:12px;"
              placeholder="Health Basics,mcq,What is the main cause of...,Option A,Option B,Option C,Option D,b,Explanation here"></textarea>

    <button type="submit" class="btn btn-primary"
            style="background:#16a34a;border-color:#16a34a;">
      &#128196; Import Questions
    </button>
  </form>
</div>

<script>
function toggleEssay(v){
  document.getElementById('mcqFields').style.display = v==='essay' ? 'none' : 'block';
}
</script>
