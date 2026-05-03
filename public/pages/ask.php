<?php trackPageView('question_box'); ?>

<div class="container">
    <div class="breadcrumb">
        <a href="/">Home</a> <span class="sep">›</span> <span>Ask a Question</span>
    </div>

    <h1 class="page-title">🔒 Anonymous Question Box</h1>
    <p class="text-muted mb-2">Have a question you're too shy to ask in person? Submit it here anonymously. A qualified health educator will answer it. <strong>No one will know who asked.</strong></p>

    <?php if (isset($_GET['sent'])): ?>
        <div class="alert alert-success">✅ Your question has been submitted anonymously! Check back later for an answer.</div>
    <?php endif; ?>

    <div class="question-box">
        <form method="POST" action="?p=ask_submit">
            <label class="section-title" for="question">Your Question</label>
            <textarea name="question" id="question" placeholder="Type your question here... Nobody will know it's from you." required></textarea>

            <label class="section-title mt-1" for="module_id">Related Topic (optional)</label>
            <select name="module_id" id="module_id">
                <option value="">— Choose a topic —</option>
                <?php foreach ($modules as $m): ?>
                    <option value="<?= $m['id'] ?>"><?= $m['icon'] ?> <?= e($m['title']) ?></option>
                <?php endforeach; ?>
            </select>

            <button type="submit" class="btn btn-primary btn-block mt-2">Submit Anonymously 🔒</button>
        </form>
    </div>

    <!-- Previously Answered Questions -->
    <?php
    $answered = db()->query('SELECT aq.*, m.title AS module_title, m.icon FROM anonymous_questions aq LEFT JOIN modules m ON aq.module_id = m.id WHERE aq.is_answered = 1 ORDER BY aq.answered_at DESC LIMIT 10');
    $answeredList = [];
    while ($row = $answered->fetchArray(SQLITE3_ASSOC)) {
        $answeredList[] = $row;
    }
    ?>

    <?php if (count($answeredList) > 0): ?>
        <h2 class="section-title mt-3">💬 Recently Answered Questions</h2>
        <?php foreach ($answeredList as $aq): ?>
            <div class="dp-card">
                <?php if ($aq['module_title']): ?>
                    <span class="text-small text-muted"><?= $aq['icon'] ?> <?= e($aq['module_title']) ?></span>
                <?php endif; ?>
                <p style="font-weight:600; margin:8px 0;">Q: <?= e($aq['question']) ?></p>
                <div style="background:var(--light); padding:12px; border-radius:8px; border-left:3px solid var(--primary);">
                    <strong>A:</strong> <?= nl2br(e($aq['answer'])) ?>
                </div>
                <small class="text-muted">Answered on <?= date('M j, Y', strtotime($aq['answered_at'])) ?></small>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>
