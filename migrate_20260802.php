<?php
/**
 * Migration 2026-08-02: Online Sexual Exploitation of Children (OSEC) module.
 *
 * Adds a new module covering:
 *   - What OSEC is (grooming, sextortion, live-stream abuse, CSAM)
 *   - Kenyan law (Computer Misuse & Cybercrimes Act 2018, Sexual Offences Act,
 *     Children Act 2022)
 *   - Warning signs, safe response, and reporting pathways
 *     (Childline 116, Child Abuse line 1190, DCI cybercrime, CI CPFP)
 *
 * Ships with:
 *   - 1 module row
 *   - 2 lessons  (video placeholder + full interactive slide lesson)
 *   - 21 quiz questions  (5 pre + 10 lesson + 6 post) — matches cyber-crimes
 *
 * Idempotent: uses INSERT OR IGNORE keyed on slug so re-running is safe.
 */

require_once __DIR__ . '/includes/config.php';

$db = db();

// ── 1. Module ───────────────────────────────────────────────────────────────
$MODULE_SLUG = 'online-sexual-exploitation';

$db->exec("INSERT OR IGNORE INTO modules
    (title, slug, description, icon, sort_order, is_active,
     content_warning, require_pretest, require_posttest)
    VALUES (
        'Online Sexual Exploitation',
        '$MODULE_SLUG',
        'Recognise grooming, sextortion and image-based abuse online. Learn Kenya''s child-protection laws and how to report safely.',
        '🔒',
        23,
        1,
        'This module discusses sexual exploitation online. Some slides describe grooming and coercion in age-appropriate terms so learners can recognise the warning signs and act early.',
        1,
        1
    )");

$moduleId = (int) $db->querySingle("SELECT id FROM modules WHERE slug='$MODULE_SLUG'");
if ($moduleId <= 0) { die("failed to create module\n"); }

echo "module_id = $moduleId\n";

// ── 2. Lessons ──────────────────────────────────────────────────────────────
$lessons = [
    // [title, slug, lesson_type, file_path, sort_order]
    [
        'Online Sexual Exploitation — Awareness Video',
        'video-online-sexual-exploitation',
        'video',
        'videos/arise_online_sexual_exploitation.mp4',
        1,
    ],
    [
        'Online Sexual Exploitation — Recognise, Refuse, Report',
        'osec-interactive',
        'interactive',
        'interactive/lesson-online-sexual-exploitation.html',
        2,
    ],
];

$stmt = $db->prepare("INSERT OR IGNORE INTO lessons
    (module_id, title, slug, lesson_type, file_path, sort_order, is_active, is_published)
    VALUES (:mid, :title, :slug, :type, :path, :order, 1, 1)");

foreach ($lessons as [$title, $slug, $type, $path, $order]) {
    $stmt->bindValue(':mid',   $moduleId, SQLITE3_INTEGER);
    $stmt->bindValue(':title', $title,    SQLITE3_TEXT);
    $stmt->bindValue(':slug',  $slug,     SQLITE3_TEXT);
    $stmt->bindValue(':type',  $type,     SQLITE3_TEXT);
    $stmt->bindValue(':path',  $path,     SQLITE3_TEXT);
    $stmt->bindValue(':order', $order,    SQLITE3_INTEGER);
    $stmt->execute();
    $stmt->reset();
}
echo "lessons inserted\n";

// ── 3. Quiz questions ───────────────────────────────────────────────────────
// section: pre | lesson | post   competency: osec   difficulty: MEDIUM
$existingQ = (int) $db->querySingle(
    "SELECT COUNT(*) FROM quiz_questions WHERE module_id=$moduleId AND competency='osec'");
if ($existingQ > 0) {
    echo "quiz questions already exist ($existingQ) — skipping\n";
    return;
}

$questions = [
    // ---- PRE-TEST (5) ----
    ['mcq','What does "online sexual exploitation of children" (OSEC) refer to?',
     'Any use of the internet by a child',
     'Any sexual activity involving a child that is facilitated by digital technology (photos, video calls, chat)',
     'Only sharing selfies on social media',
     'Only meeting strangers in person',
     '','B',
     'OSEC covers any sexual abuse or exploitation of a child that is enabled by digital technology — including grooming through chat, sextortion, live-streamed abuse, and the making, sharing or selling of child sexual abuse material (CSAM).',
     1,'pre'],

    ['mcq','What is "grooming" in the context of online safety?',
     'A grown-up teaching a child how to dress smartly',
     'A slow process where an adult builds trust with a child, isolates them from other adults, and gradually pushes past personal boundaries — usually to abuse them',
     'A hairdresser''s technique',
     'A grown-up giving a child career advice',
     '','B',
     'Grooming is deliberate, patient behaviour by an offender who builds a fake friendship, gives gifts or attention, then slowly normalises sexual talk and requests. It is a crime under Kenya''s Sexual Offences Act.',
     2,'pre'],

    ['mcq','Which Kenyan law makes it a crime to create, share or possess child sexual abuse material?',
     'The Traffic Act',
     'The Computer Misuse and Cybercrimes Act 2018 (with the Sexual Offences Act 2006 and Children Act 2022)',
     'The Data Protection Act only',
     'There is no such law in Kenya',
     '','B',
     'The Computer Misuse and Cybercrimes Act 2018 criminalises production, distribution and possession of child sexual abuse material. The Sexual Offences Act 2006 and Children Act 2022 provide additional protections and penalties.',
     3,'pre'],

    ['mcq','Twelve-year-old Amina meets someone online who says he is 14, is very kind, sends her airtime, and after a few weeks starts asking for photos in her school uniform without a skirt. This is:',
     'A normal online friendship',
     'A red flag for grooming — she should stop chatting, keep the messages, and tell a trusted adult',
     'A modelling opportunity she should consider',
     'Her problem alone — she should not tell anyone',
     '','B',
     'Gifts, secrecy, an unverified age, and escalating requests for intimate images are classic grooming red flags. Amina is not at fault; she should keep the evidence and tell a trusted adult or call Childline 116.',
     4,'pre'],

    ['msq','Which of the following are safe first steps if someone online is threatening to share your intimate images unless you send more (sextortion)? Select all that apply.',
     'Send more images to make them stop',
     'Take screenshots of the threat as evidence',
     'Stop responding to the person',
     'Tell a trusted adult (parent, teacher, CDC focal point)',
     'Call Childline Kenya on 116 or the Child Abuse line 1190',
     'B,C,D,E',
     'Sending more never stops sextortion — it escalates. The safe path is: stop responding, capture evidence, and tell someone. It is a crime under Kenyan law; the child is a victim, not an offender.',
     5,'pre'],

    // ---- LESSON (10) ----
    ['mcq','A "warning sign" that an online contact may be grooming a child is:',
     'They send occasional friendly messages in a public group',
     'They ask the child to move the chat to a private app, keep secrets from parents, and quickly turn conversations sexual',
     'They talk about school subjects',
     'They wish the child a happy birthday',
     '','B',
     'Grooming tactics: isolate the child (private chats), demand secrecy ("don''t tell your mum"), give gifts or attention, then normalise sexual talk step by step.',
     1,'lesson'],

    ['mcq','Live-streamed abuse means:',
     'Watching a live football match online',
     'A child being coerced to perform sexual acts on a live video call for someone (often for money paid to a third party)',
     'Any video call with a family member',
     'Streaming music on the internet',
     '','B',
     'Live-streamed child sexual abuse is one of the fastest-growing forms of OSEC. It is a serious crime under Kenyan and international law regardless of who is watching, paying or streaming.',
     2,'lesson'],

    ['mcq','Which of the following is NOT true about non-consensual image sharing (sometimes called "revenge porn")?',
     'It is a crime under the Computer Misuse and Cybercrimes Act 2018',
     'Even if the image was taken willingly, sharing it without consent is illegal',
     'The victim is at fault for having taken the image in the first place',
     'Kenyan law provides for penalties including imprisonment and fines',
     '','C',
     'The person who shares an intimate image without consent is the offender — not the person in the image. Kenyan law is clear on this: consent to take an image is NOT consent to share it.',
     3,'lesson'],

    ['mcq','If you find that someone has already shared your intimate images online, the best first step is to:',
     'Delete your own social media and hide',
     'Tell a trusted adult, save the evidence (URLs, screenshots), and report to Childline 116 or the DCI cybercrime unit',
     'Fight the person online publicly',
     'Do nothing and hope it disappears',
     '','B',
     'Getting help early gives the best chance of taking the content down and preventing further harm. Reporting is protected under Kenyan law — the child in the image is a victim, not a criminal.',
     4,'lesson'],

    ['mcq','Which of these is a legitimate Kenyan channel for reporting online child abuse?',
     'A random WhatsApp group',
     'Childline Kenya (116), the toll-free Child Abuse Reporting Line (1190), or the DCI Cybercrime desk (cybercrime@dci.go.ke)',
     'Only the school head teacher',
     'Only the church pastor',
     '','B',
     'Kenya has multiple confidential reporting pathways. Childline 116 and the Child Abuse line 1190 are toll-free 24/7. Compassion-supported children can also raise it with their CDC Child Protection Focal Point.',
     5,'lesson'],

    ['msq','Which of the following are ways to reduce your risk of online exploitation? Select all that apply.',
     'Keep social media accounts private and only accept people you know in real life',
     'Never send intimate photos, even to someone you think you can trust',
     'Turn on two-factor authentication on important accounts',
     'Meet online strangers alone in secret places',
     'Tell a trusted adult if anything online makes you uncomfortable',
     'A,B,C,E',
     'Private accounts, no intimate images ever, strong account security, and open conversations with trusted adults are the core protections. Meeting online contacts alone and in secret is the opposite — a well-known predator tactic.',
     6,'lesson'],

    ['mcq','Sexual grooming of a child is a crime in Kenya under:',
     'No Kenyan law',
     'The Sexual Offences Act 2006 (as amended)',
     'The Traffic Act',
     'The Consumer Protection Act',
     '','B',
     'The Sexual Offences Act 2006 (and its amendments) criminalises the grooming of a child for sexual purposes. The Children Act 2022 also imposes duties on adults to protect children from online harm.',
     7,'lesson'],

    ['mcq','A friend tells you that an older adult on Instagram has been asking her for photos in her underwear and threatening her if she stops. As a bystander you should:',
     'Ignore it — it''s her business',
     'Believe her, stay calm, help her keep the evidence, and go with her to a trusted adult or call Childline 116 / Child Abuse line 1190',
     'Post about it publicly on social media',
     'Tell her to just send one photo to make it stop',
     '','B',
     'Bystanders play a huge role in child protection. Believing the child, keeping evidence, and going with them for help changes outcomes. Never advise sending more content — this is what the offender wants.',
     8,'lesson'],

    ['mcq','What is CSAM?',
     'A brand of school shoes',
     'Child Sexual Abuse Material — any photo, video or livestream that depicts sexual abuse of a person under 18',
     'A study group name',
     'A youth choir competition',
     '','B',
     'CSAM is the correct term (replacing older phrases like "child pornography"). It is illegal to create, share, view or possess CSAM under Kenyan and international law.',
     9,'lesson'],

    ['mcq','Under Kenya''s Computer Misuse and Cybercrimes Act 2018, publishing false or intimate content about someone can be punished with:',
     'A written warning only',
     'A fine and/or imprisonment',
     'A public apology only',
     'Community service only',
     '','B',
     'The Act provides for fines up to KSh 20 million and/or imprisonment (up to 10 years for the most serious offences), depending on the specific offence.',
     10,'lesson'],

    // ---- POST-TEST (6) ----
    ['mcq','What should you do FIRST if a stranger online asks you to send an intimate picture?',
     'Send it once and block them',
     'Stop replying, tell a trusted adult or call Childline 116, and keep the messages as evidence',
     'Send them a picture of someone else',
     'Ignore them and never mention it to anyone',
     '','B',
     'Stopping, telling, and preserving evidence are the three actions that make you safer and give authorities what they need to act. You have done nothing wrong by receiving the request.',
     1,'post'],

    ['mcq','A trusted friend confides that she has been chatting with an adult who says he loves her and wants her to meet him at a hotel. The safest response is:',
     'Encourage her to go, since he says he loves her',
     'Believe her, tell her this is grooming, and help her tell a parent, teacher or CDC Child Protection Focal Point',
     'Post his name on social media',
     'Do nothing — it''s not your problem',
     '','B',
     'An adult persuading a minor to meet in secret for a romantic or sexual encounter is grooming, and it is illegal. Speaking to a trusted adult and, if in danger, calling 116 or 1190 is the safe move.',
     2,'post'],

    ['msq','Which of the following are TRUE about Kenyan law on OSEC? Select all that apply.',
     'Producing, sharing or possessing CSAM is a crime',
     'A child cannot legally consent to sexual activity, including online',
     'Only physical contact counts as abuse',
     'Grooming a child online is a specific criminal offence',
     'Threatening to share intimate images is illegal',
     'A,B,D,E',
     'Kenyan law is clear: children cannot consent; digital abuse counts as abuse; and grooming, CSAM, and sextortion are all specific crimes with real penalties.',
     3,'post'],

    ['mcq','Which is a TOLL-FREE Kenyan number for reporting child abuse (including online exploitation)?',
     '0700 111 000',
     '116 (Childline Kenya) or 1190 (Child Abuse Reporting Line)',
     '0800 000 000',
     'There is no toll-free line',
     '','B',
     'Both 116 and 1190 are toll-free across all Kenyan mobile networks and answer 24/7. The GBV Hotline 1195 also serves people in danger, including children.',
     4,'post'],

    ['mcq','A learner has been shown a threatening screenshot: "Send me a photo of yourself in the shower or I''ll send your other photos to your church." The learner must:',
     'Send more photos to satisfy the person',
     'Stop replying, take screenshots, and tell an adult or call Childline 116 or 1190 immediately',
     'Delete the account so no one ever finds out',
     'Just hope it goes away',
     '','B',
     'This is textbook sextortion, and the offender always demands more. Silence and secrecy are what the offender wants — talking to a trusted adult and reporting stops the pattern.',
     5,'post'],

    ['mcq','What is the best long-term way for young people to reduce OSEC risk?',
     'Never use the internet',
     'Keep accounts private, avoid intimate images entirely, verify who is really behind an online profile, and keep an open line with a trusted adult',
     'Post everything publicly so no one can blackmail you',
     'Share your passwords with new friends',
     '','B',
     'You do not have to leave the internet to be safe. Private accounts, zero intimate images, healthy scepticism about who is on the other side of a screen, and a trusted adult you can talk to — these together make the biggest difference.',
     6,'post'],
];

$stmtQ = $db->prepare(
    "INSERT INTO quiz_questions
     (module_id, question_type, question,
      option_a, option_b, option_c, option_d, option_e,
      correct_option, explanation, sort_order,
      is_published, section, competency, difficulty)
     VALUES
     (:mid, :qt, :q, :a, :b, :c, :d, :e, :correct, :expl, :ord,
      1, :section, 'osec', 'MEDIUM')");

foreach ($questions as $row) {
    [$qt, $q, $a, $b, $c, $d, $e, $correct, $expl, $ord, $section] = $row;
    $stmtQ->bindValue(':mid', $moduleId, SQLITE3_INTEGER);
    $stmtQ->bindValue(':qt',       $qt,      SQLITE3_TEXT);
    $stmtQ->bindValue(':q',        $q,       SQLITE3_TEXT);
    $stmtQ->bindValue(':a',        $a,       SQLITE3_TEXT);
    $stmtQ->bindValue(':b',        $b,       SQLITE3_TEXT);
    $stmtQ->bindValue(':c',        $c,       SQLITE3_TEXT);
    $stmtQ->bindValue(':d',        $d,       SQLITE3_TEXT);
    $stmtQ->bindValue(':e',        $e,       SQLITE3_TEXT);
    $stmtQ->bindValue(':correct',  $correct, SQLITE3_TEXT);
    $stmtQ->bindValue(':expl',     $expl,    SQLITE3_TEXT);
    $stmtQ->bindValue(':ord',      $ord,     SQLITE3_INTEGER);
    $stmtQ->bindValue(':section',  $section, SQLITE3_TEXT);
    $stmtQ->execute();
    $stmtQ->reset();
}
echo "quiz questions inserted: " . count($questions) . "\n";
echo "done.\n";
