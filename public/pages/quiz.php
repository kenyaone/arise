<?php
$moduleSlug = $_GET['module'] ?? '';
$module = getModule($moduleSlug);

if (!$module) {
    echo '<div class="container"><div class="alert alert-danger">Module not found.</div><a href="?p=modules" class="btn btn-secondary">← Back</a></div>';
    return;
}

// ── Spaced-repetition question selection ─────────────────────────────────────
// Priority: never-seen (-1) → wrong (0) → correct (1)
// Within each tier, randomise. Uses the learner's current session hash.
$hash = SQLite3::escapeString(getSessionHash());
$mid  = intval($module['id']);

$srQuery = "
SELECT qq.*,
  COALESCE(last_attempt.is_correct, -1) AS last_result,
  COALESCE(attempt_count.cnt, 0)        AS times_seen
FROM quiz_questions qq
LEFT JOIN (
  SELECT question_id, is_correct
  FROM quiz_answers
  WHERE attempt_id = (
    SELECT id FROM quiz_attempts
    WHERE session_hash='$hash' AND module_id=$mid
    ORDER BY id DESC LIMIT 1
  )
) last_attempt ON last_attempt.question_id = qq.id
LEFT JOIN (
  SELECT question_id, COUNT(*) AS cnt
  FROM quiz_answers qa
  JOIN quiz_attempts qat ON qat.id = qa.attempt_id
  WHERE qat.session_hash='$hash' AND qat.module_id=$mid
  GROUP BY question_id
) attempt_count ON attempt_count.question_id = qq.id
WHERE qq.module_id=$mid
ORDER BY last_result ASC, times_seen ASC, RANDOM()
";

$srResult = db()->query($srQuery);
$questions = [];
while ($row = $srResult->fetchArray(SQLITE3_ASSOC)) {
    $questions[] = $row;
}

if (count($questions) === 0) {
    echo '<div class="container"><div class="alert alert-info">No quiz questions available for this module yet.</div><a href="?p=module&slug=' . e($moduleSlug) . '" class="btn btn-secondary">← Back to Module</a></div>';
    return;
}

trackPageView('quiz', $moduleSlug, $module['id']);

// Retry cooldown (24h)
$lastAttempt = db()->querySingle("SELECT last_attempt, attempt_count FROM quiz_retry_log WHERE session_hash='".SQLite3::escapeString(getSessionHash())."' AND module_id=".intval($module['id']), true);
$cooldownRemaining = 0;
if ($lastAttempt && $lastAttempt['attempt_count'] >= 2) {
    $elapsed = time() - strtotime($lastAttempt['last_attempt']);
    $cooldownRemaining = max(0, 86400 - $elapsed);
}

$mcqQuestions = array_filter($questions, fn($q) => ($q['question_type'] ?? 'mcq') === 'mcq');
$essayQuestions = array_filter($questions, fn($q) => ($q['question_type'] ?? 'mcq') === 'essay');
$mcqQuestions = array_values($mcqQuestions);
$essayQuestions = array_values($essayQuestions);
?>

<div class="container">
    <div class="breadcrumb">
        <a href="/">Home</a> <span class="sep">›</span>
        <a href="?p=modules">Modules</a> <span class="sep">›</span>
        <a href="?p=module&slug=<?= e($moduleSlug) ?>"><?= e($module['title']) ?></a> <span class="sep">›</span>
        <span>Quiz</span>
    </div>

    <?php if ($cooldownRemaining > 0):
    $hrs = floor($cooldownRemaining/3600); $mins = floor(($cooldownRemaining%3600)/60); ?>
<div class="cooldown-box" style="max-width:500px;margin:30px auto">
  <div style="font-size:1.8rem;margin-bottom:8px">⏳</div>
  <div style="font-size:1rem;font-weight:800;color:#e65100">Quiz Cooldown Active</div>
  <div class="cooldown-timer"><?= $hrs ?>h <?= $mins ?>m remaining</div>
  <div style="font-size:.82rem;color:#666;margin-bottom:16px">You've taken this quiz twice. Please review the material and try again after <?= $hrs ?>h <?= $mins ?>m.</div>
  <a href="/arise/?p=module&slug=<?= e($moduleSlug) ?>" class="btn btn-secondary">← Back to Module</a>
</div>
<?php else: ?>
<div class="quiz-container" id="quiz-container">
        <h2 style="margin-bottom:5px;"><?= $module['icon'] ?> <?= e($module['title']) ?> Quiz</h2>
        <p class="text-muted mb-2">
            <?php if (count($mcqQuestions) > 0 && count($essayQuestions) > 0): ?>
                <?= count($mcqQuestions) ?> multiple choice + <?= count($essayQuestions) ?> essay question<?= count($essayQuestions) > 1 ? 's' : '' ?>
            <?php elseif (count($essayQuestions) > 0): ?>
                <?= count($essayQuestions) ?> essay question<?= count($essayQuestions) > 1 ? 's' : '' ?>
            <?php else: ?>
                <?= count($mcqQuestions) ?> multiple choice questions
            <?php endif; ?>
        </p>
        <p class="text-muted text-small" style="margin-bottom:12px; font-style:italic;">
            ✨ Questions are personalised to focus on topics you find challenging.
        </p>

        <div class="quiz-progress">
            <div class="quiz-progress-bar" id="progress-bar" style="width: 0%"></div>
        </div>

        <div id="quiz-area"></div>
        <div id="quiz-results" class="hidden"></div>
        <script>
        var QUIZ_QUESTIONS = <?php
          $qdata=[];
          foreach(array_merge($mcqQuestions,$essayQuestions) as $q){
            $qdata[]=['id'=>$q['id'],'question'=>$q['question'],'type'=>$q['question_type']??'mcq','options'=>['a'=>$q['option_a'],'b'=>$q['option_b'],'c'=>$q['option_c'],'d'=>$q['option_d']],'correct'=>$q['correct_option'],'explanation'=>$q['explanation']];
          }
          echo json_encode($qdata);
        ?>;
        var MODULE_SLUG = '<?= addslashes($moduleSlug) ?>';
        var MODULE_ID = <?= $module['id'] ?>;
        </script>
    </div>

    <div class="mt-2">
        <a href="?p=module&slug=<?= e($moduleSlug) ?>" class="btn btn-secondary">← Back to Module</a>
    </div>
</div>

<style>
.essay-textarea {
    width: 100%;
    min-height: 160px;
    padding: 14px;
    border: 2px solid var(--border);
    border-radius: var(--radius);
    font-size: 0.95rem;
    font-family: inherit;
    line-height: 1.7;
    resize: vertical;
    transition: var(--transition);
}
.essay-textarea:focus {
    outline: none;
    border-color: var(--primary);
    box-shadow: 0 0 0 3px rgba(108, 92, 231, 0.1);
}
.word-counter {
    font-size: 0.8rem;
    color: var(--mid);
    margin-top: 5px;
    text-align: right;
}
.word-counter.warning { color: var(--danger); }
.word-counter.good { color: var(--success); }
.essay-hint {
    font-size: 0.85rem;
    color: var(--mid);
    background: #F0F7FF;
    padding: 10px 14px;
    border-radius: 8px;
    margin-bottom: 12px;
    border-left: 3px solid var(--primary);
}
.q-type-badge {
    display: inline-block;
    padding: 3px 10px;
    border-radius: 20px;
    font-size: 0.75rem;
    font-weight: 600;
    margin-bottom: 8px;
}
.badge-mcq { background: #E6FFF5; color: var(--success); }
.badge-essay { background: #F0EEFF; color: var(--primary); }
.marks-badge {
    display: inline-block;
    padding: 3px 10px;
    border-radius: 20px;
    font-size: 0.75rem;
    font-weight: 600;
    background: var(--light);
    color: var(--mid);
    margin-left: 5px;
}
.quiz-options label {
    display: flex;
    align-items: center;
    padding: 12px;
    margin-bottom: 8px;
    border: 2px solid var(--border);
    border-radius: 8px;
    cursor: pointer;
    background: transparent;
    transition: all 0.2s ease;
}
.quiz-options label:hover {
    border-color: var(--primary);
    background: rgba(108, 92, 231, 0.04);
}
.quiz-options input[type="checkbox"] {
    width: 18px;
    height: 18px;
    cursor: pointer;
    margin-right: 10px;
    flex-shrink: 0;
}
.quiz-options input[type="checkbox"]:checked + span {
    font-weight: 600;
}
</style>

<script>
const mcqData = <?= json_encode(array_map(function($q) {
    return [
        'id' => $q['id'],
        'type' => 'mcq',
        'question' => $q['question'],
        'options' => ['A' => $q['option_a'], 'B' => $q['option_b'], 'C' => $q['option_c'], 'D' => $q['option_d']],
        'correct' => $q['correct_option'],
        'explanation' => $q['explanation'],
        'max_marks' => intval($q['max_marks'] ?? 1)
    ];
}, $mcqQuestions)) ?>;

const essayData = <?= json_encode(array_map(function($q) {
    return [
        'id' => $q['id'],
        'type' => 'essay',
        'question' => $q['question'],
        'hint' => $q['essay_hint'] ?? '',
        'min_words' => intval($q['min_words'] ?? 0),
        'max_marks' => intval($q['max_marks'] ?? 5)
    ];
}, $essayQuestions)) ?>;

const allQuestions = [...mcqData, ...essayData];
const moduleId = <?= $module['id'] ?>;
const moduleSlug = '<?= e($moduleSlug) ?>';
let currentQ = 0;
let mcqAnswers = {};
let essayAnswers = {};

function parseCorrectAnswers(str) {
    if (!str) return [];
    const s = str.toUpperCase().trim();
    // Handle comma/space-separated: "b,c" or "a, b" → ['B','C']
    if (/[,\s]/.test(s)) return s.split(/[,\s]+/).filter(x => /^[A-D]$/.test(x));
    // Handle concatenated: "abc" → ['A','B','C']
    return s.split('').filter(x => /^[A-D]$/.test(x));
}
function countWords(t) { return t.trim().split(/\s+/).filter(w => w.length > 0).length; }

function renderQuestion() {
    const q = allQuestions[currentQ];
    const total = allQuestions.length;
    document.getElementById('progress-bar').style.width = ((currentQ) / total * 100) + '%';

    let html = `<div class="quiz-counter">Question ${currentQ + 1} of ${total}</div>`;

    if (q.type === 'mcq') {
        const correctAnswers = parseCorrectAnswers(q.correct);
        const selectedAnswers = mcqAnswers[q.id] || [];
        const isMultiSelect = correctAnswers.length > 1;

        html += `<span class="q-type-badge badge-mcq">${isMultiSelect ? '☑ Select All That Apply' : '● Multiple Choice'}</span>
            <span class="marks-badge">${q.max_marks} mark${q.max_marks > 1 ? 's' : ''}</span>
            <div class="quiz-question">${q.question}</div><div class="quiz-options">`;

        ['A','B','C','D'].forEach(opt => {
            const isSelected = selectedAnswers.includes(opt);
            const inputId = `input-${q.id}-${opt}`;
            if (isMultiSelect) {
                html += `<label style="display:flex;align-items:center;padding:12px;margin-bottom:8px;border:2px solid ${isSelected?'var(--green)':'var(--border)'};border-radius:8px;cursor:pointer;background:${isSelected?'rgba(61,99,24,0.08)':'transparent'};transition:all 0.2s;">
                    <input type="checkbox" id="${inputId}" style="width:18px;height:18px;cursor:pointer;margin-right:10px;flex-shrink:0;" ${isSelected?'checked':''} onchange="toggleMCQ(${q.id},'${opt}',true)">
                    <span style="flex:1;cursor:pointer;">${opt}. ${q.options[opt]}</span>
                </label>`;
            } else {
                html += `<label style="display:flex;align-items:center;padding:12px;margin-bottom:8px;border:2px solid ${isSelected?'var(--green)':'var(--border)'};border-radius:8px;cursor:pointer;background:${isSelected?'rgba(61,99,24,0.08)':'transparent'};transition:all 0.2s;">
                    <input type="radio" name="radio-${q.id}" id="${inputId}" style="width:18px;height:18px;cursor:pointer;margin-right:10px;flex-shrink:0;accent-color:var(--green);" ${isSelected?'checked':''} onchange="toggleMCQ(${q.id},'${opt}',false)">
                    <span style="flex:1;cursor:pointer;">${opt}. ${q.options[opt]}</span>
                </label>`;
            }
        });
        html += `</div>`;
    } else {
        const txt = essayAnswers[q.id] || '';
        const wc = countWords(txt);
        const min = q.min_words || 0;
        const cls = min > 0 ? (wc >= min ? 'good' : 'warning') : '';
        html += `<span class="q-type-badge badge-essay">✍️ Essay</span>
            <span class="marks-badge">${q.max_marks} mark${q.max_marks > 1 ? 's' : ''}</span>
            <div class="quiz-question">${q.question}</div>`;
        if (q.hint) html += `<div class="essay-hint">💡 ${q.hint}</div>`;
        html += `<textarea class="essay-textarea" id="essay-${q.id}" oninput="updateEssay(${q.id},this.value)" placeholder="Write your answer here...">${txt}</textarea>
            <div class="word-counter ${cls}" id="wc-${q.id}">${wc} word${wc!==1?'s':''}${min > 0 ? ' / '+min+' minimum' : ''}</div>`;
    }

    html += `<div style="display:flex;justify-content:space-between;margin-top:20px;">`;
    html += currentQ > 0 ? `<button class="btn btn-secondary" onclick="prevQ()">← Previous</button>` : '<span></span>';
    html += currentQ < total - 1
        ? `<button class="btn btn-primary" onclick="nextQ()">Next →</button>`
        : `<button class="btn btn-success btn-lg" onclick="submitQuiz()">Submit Quiz ✓</button>`;
    html += `</div>`;

    document.getElementById('quiz-area').innerHTML = html;
}

function toggleMCQ(id, opt, isMulti) {
    if (isMulti) {
        if (!mcqAnswers[id]) mcqAnswers[id] = [];
        const idx = mcqAnswers[id].indexOf(opt);
        if (idx >= 0) mcqAnswers[id].splice(idx, 1);
        else mcqAnswers[id].push(opt);
    } else {
        mcqAnswers[id] = [opt];
    }
    renderQuestion();
}
function updateEssay(id, text) {
    essayAnswers[id] = text;
    const q = allQuestions.find(x => x.id === id);
    const wc = countWords(text), min = q ? (q.min_words||0) : 0;
    const el = document.getElementById('wc-'+id);
    if (el) { el.className = 'word-counter '+(min>0?(wc>=min?'good':'warning'):''); el.textContent = wc+' word'+(wc!==1?'s':'')+(min>0?' / '+min+' minimum':''); }
}
function nextQ() { if (currentQ < allQuestions.length-1) { currentQ++; renderQuestion(); } }
function prevQ() { if (currentQ > 0) { currentQ--; renderQuestion(); } }

function isAnswerCorrect(selected, correctStr) {
    const correctSet = new Set(parseCorrectAnswers(correctStr));
    const selectedSet = new Set(selected || []);
    if (correctSet.size === 0) return false;
    if (correctSet.size !== selectedSet.size) return false;
    for (let ans of correctSet) if (!selectedSet.has(ans)) return false;
    return true;
}

function submitQuiz() {
    let mcqScore = 0, mcqTotal = 0;
    mcqData.forEach(q => {
        mcqTotal += q.max_marks;
        if (isAnswerCorrect(mcqAnswers[q.id], q.correct)) mcqScore += q.max_marks;
    });
    const mcqPct = mcqTotal > 0 ? Math.round((mcqScore/mcqTotal)*100) : 0;

    document.getElementById('progress-bar').style.width = '100%';
    document.getElementById('quiz-area').classList.add('hidden');

    let html = '';

    if (mcqData.length > 0) {
        const pass = mcqPct >= 50;
        const certEligible = mcqPct >= 60;
        html += `<div class="quiz-result"><div class="score">${mcqPct}%</div>
            <div class="score-label">Multiple Choice: ${mcqScore} / ${mcqTotal} marks</div>
            <div class="grade ${pass?'grade-pass':'grade-fail'}">${pass?'✅ Passed!':'❌ Try Again'}</div></div>`;

        if (certEligible) {
            html += `<div style="text-align:center; margin:15px 0; padding:20px; background:linear-gradient(135deg, #FFF9E6, #FFF0F5); border:2px dashed #FFB800; border-radius:var(--radius-lg);">
                <div style="font-size:2rem;">🏆</div>
                <h3 style="margin:8px 0 5px; color:var(--primary);">Congratulations!</h3>
                <p style="color:var(--mid); margin-bottom:12px;">You scored ${mcqPct}% and earned a certificate for this module!</p>
                <a href="?p=certificate&module=${moduleSlug}&score=${mcqPct}" class="btn btn-primary btn-lg" target="_blank">🎓 View & Print Certificate</a>
            </div>`;
        }

        html += `<h3 style="margin:20px 0 15px;">📝 Quiz Review</h3>`;
        mcqData.forEach((q,i) => {
            const selected = mcqAnswers[q.id] || [];
            const correct = parseCorrectAnswers(q.correct);
            const isCorrect = isAnswerCorrect(selected, q.correct);
            const correctStr = correct.length > 0 ? correct.join(', ') : 'None';
            const selectedStr = selected.length > 0 ? selected.join(', ') : 'Not answered';
            const selectedDisplay = selected.map(opt => `${opt}. ${q.options[opt]}`).join(' • ') || '—';

            html += `<div style="margin-bottom:12px;padding:15px;background:${isCorrect?'#E6FFF5':'#FFF0ED'};border-radius:var(--radius);">
                <div style="font-weight:600;margin-bottom:5px;">${i+1}. ${q.question}</div>
                <div style="font-size:0.9rem;margin-bottom:4px;">Your answer${selected.length>1?'s':''}: <strong>${selectedDisplay}</strong></div>
                ${!isCorrect?`<div style="font-size:0.9rem;color:var(--success);margin-bottom:4px;">Correct answer${correct.length>1?'s':''}: <strong>${correct.map(o => `${o}. ${q.options[o]}`).join(' • ')}</strong></div>`:''}
                ${q.explanation?`<div class="quiz-explanation">${q.explanation}</div>`:''}
            </div>`;
        });
    }

    if (essayData.length > 0) {
        let essayMarks = 0;
        essayData.forEach(q => essayMarks += q.max_marks);
        html += `<h3 style="margin:25px 0 10px;">✍️ Essay Responses</h3>
            <div class="alert alert-info">Your essays have been submitted for grading. (${essayMarks} marks pending review)</div>`;
        essayData.forEach((q,i) => {
            const txt = essayAnswers[q.id]||'', wc = countWords(txt);
            html += `<div style="margin-bottom:12px;padding:15px;background:#F0EEFF;border-radius:var(--radius);">
                <div style="font-weight:600;margin-bottom:5px;">Essay ${i+1}: ${q.question}</div>
                <span class="marks-badge">${q.max_marks} marks • ${wc} words</span>
                <div style="margin-top:8px;padding:10px;background:white;border-radius:8px;font-size:0.9rem;white-space:pre-wrap;">${txt||'<em>No response</em>'}</div>
            </div>`;
        });
    }

    html += `<div style="display:flex;gap:10px;margin-top:20px;">
        <a href="?p=quiz&module=${moduleSlug}" class="btn btn-primary">🔄 Retake Quiz</a>
        <a href="?p=module&slug=${moduleSlug}" class="btn btn-secondary">← Back to Module</a></div>`;

    document.getElementById('quiz-results').innerHTML = html;
    document.getElementById('quiz-results').classList.remove('hidden');

    // Save MCQ (with per-question answers for review)
    if (mcqData.length > 0) {
        const answersJson = JSON.stringify(mcqData.map(q => ({
            question_id: q.id,
            chosen: (mcqAnswers[q.id] || []).join(','),
            correct: q.correct,
            is_correct: isAnswerCorrect(mcqAnswers[q.id], q.correct) ? 1 : 0
        })));
        fetch('?p=quiz_submit', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'},
            body:`module_id=${moduleId}&score=${mcqScore}&total=${mcqTotal}&percentage=${mcqPct}&answers_json=${encodeURIComponent(answersJson)}` })
        .then(r => r.json())
        .then(data => {
            if (data.attempt_id) {
                const reviewLink = document.createElement('a');
                reviewLink.href = `?p=quiz_review&attempt_id=${data.attempt_id}`;
                reviewLink.className = 'btn btn-secondary';
                reviewLink.style.marginTop = '10px';
                reviewLink.textContent = '📋 Detailed Review';
                const btns = document.querySelector('#quiz-results div[style*="display:flex"]');
                if (btns) btns.appendChild(reviewLink);
            }
        }).catch(()=>{});
    }
    // Save essays
    essayData.forEach(q => {
        const txt = essayAnswers[q.id]||'';
        if (txt.trim()) {
            fetch('?p=essay_submit', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'},
                body:`question_id=${q.id}&module_id=${moduleId}&response=${encodeURIComponent(txt)}&word_count=${countWords(txt)}` });
        }
    });
}

renderQuestion();
</script>

<?php endif; ?>
