<?php
/**
 * Registers the Child Protection curriculum: 7 modules, each with two
 * lesson tracks — the original facilitator-led session, and a "On My Own"
 * self-paced track for learners without a facilitator present.
 *
 * The solo track exists because the facilitator content assumes an adult
 * running a session (props, group activities, age-tiered discussion
 * prompts) — not something a lone learner on a phone can follow. Each solo
 * lesson: rewrites the teaching content in direct-to-learner voice, drops
 * the age-tiered group activities in favour of one self-paced flow, adds a
 * short self-check quiz (wired to the same save_quiz_score tracking as the
 * rest of ARISE, so it feeds real completion data instead of only page
 * views), and — calibrated to each lesson's sensitivity — a safety box with
 * Kenya helplines (Childline 116, GBV Hotline 1195, Kenya Red Cross 1199).
 * Lesson 3's solo track names self-harm and suicidal thoughts directly,
 * since its facilitator version covers that ground. All interactions are
 * tap/click only — no typing required, for learners who can't type.
 *
 * The facilitator-led lesson files already use /arise/-prefixed internal
 * links (built for this deployment context); the solo files are generated
 * per-deployment from the canonical root-relative version — see
 * tools/adapt_solo_lessons_for_arise.php if regenerating them.
 *
 * Idempotent: every insert is guarded by a slug lookup, safe to re-run.
 */

require_once __DIR__ . '/includes/config.php';

$db = db();
$log = function (string $msg): void { echo '[' . date('H:i:s') . "] $msg\n"; };

$modules = [
    ['id'=>23, 'title'=>"Child Protection: What Is God's View of Me?", 'slug'=>'gods-view-of-me',
     'description'=>"A child's worth comes from being made and loved by God — not from appearance, performance, or how anyone else treats them.", 'icon'=>'💗'],
    ['id'=>24, 'title'=>'Child Protection: What Is Child Abuse?', 'slug'=>'what-is-child-abuse',
     'description'=>'Naming the forms abuse takes clearly, so children can recognise it and never carry the blame for it.', 'icon'=>'🚨'],
    ['id'=>25, 'title'=>'Child Protection: Appropriate and Inappropriate Behaviors', 'slug'=>'appropriate-behaviors',
     'description'=>'Self-respect, treating others with dignity, and recognising bullying, peer pressure and self-harm.', 'icon'=>'🤝'],
    ['id'=>26, 'title'=>'Child Protection: Strategies That Can Help Prevent Abuse', 'slug'=>'preventing-abuse',
     'description'=>'Good touch and bad touch, private places, and the difference between safe and unsafe adult behaviour.', 'icon'=>'🛡️'],
    ['id'=>27, 'title'=>'Child Protection: Promoting My Own Safety', 'slug'=>'promoting-safety',
     'description'=>'Everyday hygiene and hazard awareness — water, fire, traffic and animals — for children and the adults around them.', 'icon'=>'🚸'],
    ['id'=>28, 'title'=>'Child Protection: Do I Have Any Rights?', 'slug'=>'my-rights',
     'description'=>"Rights drawn from the UN Convention on the Rights of the Child, framed through a child's worth in God's eyes.", 'icon'=>'⚖️'],
    ['id'=>29, 'title'=>'Child Protection: What Should I Do If I Am Abused?', 'slug'=>'what-to-do-if-abused',
     'description'=>'What to do in the moment, who to tell, and why telling — even though it is hard — is always the safest thing to do.', 'icon'=>'🆘'],
];

// [module_id, lesson_id, facilitator title, facilitator file, solo title, solo content blurb, solo file, sizeKB pair]
$lessons = [
    [23, 67, "Child Protection: What Is God's View of Me?", 'cp1-gods-view-of-me.html', 42.1,
         75, "What Is God's View of Me? — On My Own", 'Go through this by yourself, at your own pace', 'cp1-gods-view-of-me-solo.html', 28.9],
    [24, 68, 'Child Protection: What Is Child Abuse?', 'cp2-what-is-child-abuse.html', 46.4,
         76, 'What Is Child Abuse? — On My Own', 'Go through this by yourself, at your own pace — with helpline numbers and a short self-check quiz', 'cp2-what-is-child-abuse-solo.html', 32.4],
    [25, 69, 'Child Protection: Appropriate and Inappropriate Behaviors', 'cp3-appropriate-behaviors.html', 43.9,
         77, 'Appropriate and Inappropriate Behaviors — On My Own', 'Go through this by yourself, at your own pace — includes support if you are struggling', 'cp3-appropriate-behaviors-solo.html', 35.6],
    [26, 70, 'Child Protection: Strategies That Can Help Prevent Abuse', 'cp4-preventing-abuse.html', 47.0,
         78, 'Strategies That Can Help Prevent Abuse — On My Own', 'Go through this by yourself, at your own pace — with helpline numbers and a short self-check quiz', 'cp4-preventing-abuse-solo.html', 33.8],
    [27, 71, 'Child Protection: Promoting My Own Safety', 'cp5-promoting-safety.html', 41.9,
         79, 'Promoting My Own Safety — On My Own', 'Go through this by yourself, at your own pace', 'cp5-promoting-safety-solo.html', 28.6],
    [28, 72, 'Child Protection: Do I Have Any Rights?', 'cp6-my-rights.html', 43.0,
         80, 'Do I Have Any Rights? — On My Own', 'Go through this by yourself, at your own pace', 'cp6-my-rights-solo.html', 31.9],
    [29, 73, 'Child Protection: What Should I Do If I Am Abused?', 'cp7-what-to-do-if-abused.html', 45.2,
         74, 'What Should I Do If I Am Abused? — On My Own', 'Go through this by yourself, at your own pace — with helpline numbers and a short self-check quiz', 'cp7-what-to-do-if-abused-solo.html', 32.0],
];

$db->exec('BEGIN');
try {
    $log('Registering modules…');
    foreach ($modules as $m) {
        $exists = $db->querySingle("SELECT id FROM modules WHERE slug='" . SQLite3::escapeString($m['slug']) . "'");
        if ($exists) { $log("  skip module '{$m['slug']}' — already exists (id $exists)"); continue; }
        $stmt = $db->prepare('INSERT INTO modules (id, title, slug, description, icon, sort_order, is_active, require_pretest, require_posttest) VALUES (:id, :title, :slug, :desc, :icon, :id, 1, 0, 0)');
        $stmt->bindValue(':id', $m['id']);
        $stmt->bindValue(':title', $m['title']);
        $stmt->bindValue(':slug', $m['slug']);
        $stmt->bindValue(':desc', $m['description']);
        $stmt->bindValue(':icon', $m['icon']);
        $stmt->execute();
        $log("  created module '{$m['slug']}' (id {$m['id']})");
    }

    $log('Registering lessons…');
    foreach ($lessons as [$modId, $facId, $facTitle, $facFile, $facKb, $soloId, $soloTitle, $soloContent, $soloFile, $soloKb]) {
        // Derive the canonical lesson slug from the module slug (matches how these were originally registered).
        $modSlug = $db->querySingle("SELECT slug FROM modules WHERE id=$modId");
        $soloSlug = $modSlug . '-solo';

        $existsFac = $db->querySingle("SELECT id FROM lessons WHERE slug='" . SQLite3::escapeString($modSlug) . "'");
        if (!$existsFac) {
            $stmt = $db->prepare('INSERT INTO lessons (id,module_id,title,slug,content,lesson_type,file_path,file_name,file_size_kb,sort_order,is_active,is_published) VALUES (:id,:mod,:title,:slug,\'\',\'interactive\',:fpath,:fname,:kb,1,1,1)');
            $stmt->bindValue(':id', $facId);
            $stmt->bindValue(':mod', $modId);
            $stmt->bindValue(':title', $facTitle);
            $stmt->bindValue(':slug', $modSlug);
            $stmt->bindValue(':fpath', 'interactive/' . $facFile);
            $stmt->bindValue(':fname', $facFile);
            $stmt->bindValue(':kb', $facKb);
            $stmt->execute();
            $log("  created lesson '$modSlug' (id $facId, facilitator-led)");
        } else {
            $log("  skip lesson '$modSlug' — already exists");
        }

        $existsSolo = $db->querySingle("SELECT id FROM lessons WHERE slug='" . SQLite3::escapeString($soloSlug) . "'");
        if (!$existsSolo) {
            $stmt = $db->prepare('INSERT INTO lessons (id,module_id,title,slug,content,lesson_type,file_path,file_name,file_size_kb,sort_order,is_active,is_published) VALUES (:id,:mod,:title,:slug,:content,\'interactive\',:fpath,:fname,:kb,2,1,1)');
            $stmt->bindValue(':id', $soloId);
            $stmt->bindValue(':mod', $modId);
            $stmt->bindValue(':title', $soloTitle);
            $stmt->bindValue(':slug', $soloSlug);
            $stmt->bindValue(':content', $soloContent);
            $stmt->bindValue(':fpath', 'interactive/' . $soloFile);
            $stmt->bindValue(':fname', $soloFile);
            $stmt->bindValue(':kb', $soloKb);
            $stmt->execute();
            $log("  created lesson '$soloSlug' (id $soloId, solo track)");
        } else {
            $log("  skip lesson '$soloSlug' — already exists");
        }
    }

    $db->exec('COMMIT');
    $log('Done.');
} catch (\Throwable $e) {
    $db->exec('ROLLBACK');
    $log('FAILED — rolled back: ' . $e->getMessage());
    exit(1);
}

$modCount = $db->querySingle("SELECT COUNT(*) FROM modules WHERE id BETWEEN 23 AND 29");
$lessonCount = $db->querySingle("SELECT COUNT(*) FROM lessons WHERE module_id BETWEEN 23 AND 29");
$log("Final state: $modCount/7 modules, $lessonCount/14 lessons registered.");
