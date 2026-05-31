<?php
require_once __DIR__ . '/../../includes/config.php';
$ver = defined('ARISE_VERSION') ? ARISE_VERSION : '1.0';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>ARISE Impact Assessment Manual</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Segoe UI',Arial,sans-serif;font-size:13pt;color:#111;background:#fff;line-height:1.7}
.cover{background:linear-gradient(135deg,#7c2d12,#c2410c);color:#fff;padding:80px 60px;min-height:260px;display:flex;flex-direction:column;justify-content:center}
.cover h1{font-size:2.6rem;font-weight:900;letter-spacing:-.5px;margin-bottom:8px}
.cover .sub{font-size:1.1rem;color:rgba(255,255,255,.75);margin-bottom:30px}
.cover .meta{font-size:.9rem;color:rgba(255,255,255,.5)}
.content{max-width:820px;margin:0 auto;padding:40px 40px 80px}
h2{font-size:1.4rem;font-weight:800;color:#7c2d12;border-bottom:3px solid #f97316;padding-bottom:6px;margin:36px 0 14px}
h3{font-size:1.05rem;font-weight:700;color:#9a3412;margin:22px 0 8px}
p{margin-bottom:10px}
ul,ol{margin:8px 0 12px 24px}
li{margin-bottom:5px}
.tip{background:#fff7ed;border-left:4px solid #f97316;border-radius:0 8px 8px 0;padding:12px 16px;margin:14px 0;font-size:.95rem}
.note{background:#f0fdf4;border-left:4px solid #0ea271;border-radius:0 8px 8px 0;padding:12px 16px;margin:14px 0;font-size:.95rem}
.formula{background:#1e293b;color:#7dd3fc;border-radius:8px;padding:14px 20px;font-family:'Courier New',monospace;font-size:1rem;margin:14px 0;text-align:center;letter-spacing:.5px}
table{width:100%;border-collapse:collapse;margin:12px 0}
th{background:#7c2d12;color:#fff;padding:8px 12px;font-size:.85rem;text-align:left}
td{padding:8px 12px;border-bottom:1px solid #e5e7eb;font-size:.9rem;vertical-align:top}
tr:nth-child(even) td{background:#fff7ed}
.kpi{display:inline-block;background:#fff7ed;border:2px solid #fed7aa;border-radius:10px;padding:12px 18px;margin:8px 8px 8px 0;min-width:160px;text-align:center}
.kpi .val{font-size:1.6rem;font-weight:900;color:#c2410c}
.kpi .lbl{font-size:.72rem;font-weight:700;color:#9a3412;text-transform:uppercase;letter-spacing:.4px}
.no-print{margin-bottom:0}
@media print{
  .no-print{display:none}
  .cover{-webkit-print-color-adjust:exact;print-color-adjust:exact}
  .formula{background:#f3f4f6;color:#1e293b;border:1px solid #e5e7eb}
  body{font-size:11pt}
}
@page{margin:15mm 20mm}
</style>
</head>
<body>

<div class="no-print" style="background:#7c2d12;padding:12px 40px;display:flex;align-items:center;justify-content:space-between;">
  <div style="display:flex;gap:20px;align-items:center;">
    <span style="color:#fed7aa;font-weight:700;">📊 Impact Guide</span>
    <a href="/arise/?p=manual_user" style="color:#fed7aa;text-decoration:none;font-size:.9rem;padding:6px 12px;border-radius:6px;border:1px solid #78350f;transition:.2s">📖 User Manual</a>
    <a href="/arise/?p=datapost" style="color:#fed7aa;text-decoration:none;font-size:.9rem;padding:6px 12px;border-radius:6px;border:1px solid #78350f;transition:.2s">💾 DataPost</a>
  </div>
  <button onclick="window.print()" style="background:#f97316;color:#fff;border:none;border-radius:8px;padding:8px 20px;font-weight:700;cursor:pointer;font-size:.9rem;">
    &#128424; Print / Save as PDF
  </button>
</div>

<div class="cover">
  <div style="font-size:.8rem;letter-spacing:2px;text-transform:uppercase;color:rgba(255,255,255,.4);margin-bottom:12px;">ARISE Platform</div>
  <h1>Impact Assessment Manual</h1>
  <div class="sub">For M&amp;E Officers, Programme Managers &amp; Researchers</div>
  <div class="meta">Version <?= e($ver) ?> &nbsp;&middot;&nbsp; <?= date('F Y') ?> &nbsp;&middot;&nbsp; Adolescent Reproductive Health Information Support &amp; Empowerment</div>
</div>

<div class="content">

<h2>Table of Contents</h2>
<ol>
  <li>The ARISE Impact Framework</li>
  <li>Theory of Change</li>
  <li>Data Collected by the Platform</li>
  <li>Pre-Test &amp; Post-Test Methodology</li>
  <li>Knowledge Gain Index</li>
  <li>Normalised Gain Index (NGI)</li>
  <li>Behavioral Survey</li>
  <li>Completion Funnel Analysis</li>
  <li>Cohort Comparison</li>
  <li>Difficulty Tracking</li>
  <li>Retention Test (30-Day Follow-Up)</li>
  <li>The Analytics Dashboard</li>
  <li>Exporting Data</li>
  <li>Reporting Templates &amp; Interpretation Guide</li>
</ol>

<h2>1. The ARISE Impact Framework</h2>
<p>ARISE measures impact at four levels aligned with the Kirkpatrick Learning Evaluation Model:</p>
<table>
  <tr><th>Level</th><th>Question</th><th>ARISE Instrument</th></tr>
  <tr><td><strong>1 — Reaction</strong></td><td>Did learners engage with the content?</td><td>Completion rate, lesson progress, time on platform</td></tr>
  <tr><td><strong>2 — Learning</strong></td><td>Did knowledge increase?</td><td>Pre/Post test scores, Normalised Gain Index</td></tr>
  <tr><td><strong>3 — Behaviour</strong></td><td>Did learners change behaviour?</td><td>Behavioral survey (post-module)</td></tr>
  <tr><td><strong>4 — Results</strong></td><td>Did the programme achieve its goals?</td><td>Cohort analysis, certificate completion rates</td></tr>
</table>

<h2>2. Theory of Change</h2>
<p>The ARISE theory of change assumes that:</p>
<ol>
  <li>Structured, interactive digital content increases health knowledge.</li>
  <li>Increased knowledge, measured by pre/post tests, predicts attitude change.</li>
  <li>Attitude change, reported in behavioral surveys, predicts real-world behaviour change.</li>
  <li>Behaviour change contributes to improved adolescent reproductive health outcomes.</li>
</ol>
<div class="tip">The platform directly measures levels 1 and 2. Level 3 is self-reported. Level 4 requires external longitudinal follow-up beyond the platform.</div>

<h2>3. Data Collected by the Platform</h2>
<table>
  <tr><th>Data Type</th><th>Collection Point</th><th>Table</th></tr>
  <tr><td>Learner registration</td><td>Registration form</td><td>students</td></tr>
  <tr><td>Module views &amp; sessions</td><td>Page load</td><td>page_views</td></tr>
  <tr><td>Lesson completion</td><td>Lesson finish event</td><td>lesson_progress</td></tr>
  <tr><td>Pre-test answers &amp; scores</td><td>Test submission</td><td>pretest_attempts, quiz_answers</td></tr>
  <tr><td>Post-test answers &amp; scores</td><td>Test submission</td><td>pretest_attempts, quiz_answers</td></tr>
  <tr><td>Quiz attempts</td><td>Quiz submission</td><td>quiz_attempts, quiz_answers</td></tr>
  <tr><td>Certificates earned</td><td>Post-test pass</td><td>certificates</td></tr>
  <tr><td>Impact survey (3 yes/no + text responses)</td><td>After lesson quiz &amp; after post-test</td><td>behavioral_surveys</td></tr>
  <tr><td>Retention test</td><td>30 days after cert</td><td>retention_tests</td></tr>
  <tr><td>Forum posts</td><td>Forum submission</td><td>forum_posts</td></tr>
  <tr><td>Anonymous questions</td><td>Ask Us form</td><td>anon_questions</td></tr>
</table>

<h2>4. Pre-Test &amp; Post-Test Methodology</h2>
<p>Each learning module has a pool of MCQ questions in the database. The pre-test and post-test each randomly select 5 questions from this pool. Learners must complete the pre-test before they can take the post-test.</p>
<h3>Design principles</h3>
<ul>
  <li>Questions are randomised per attempt to reduce order effects.</li>
  <li>The same question pool is used for both pre and post to enable direct comparison.</li>
  <li>Questions are tagged with <strong>competencies</strong> to allow domain-level analysis.</li>
  <li>Each answer is saved individually to enable item-level analysis.</li>
</ul>
<div class="note">For statistical validity, a minimum of <strong>30 matched pre/post pairs</strong> per module is recommended before drawing conclusions about knowledge gain.</div>

<h2>5. Knowledge Gain Index</h2>
<p>The basic knowledge gain is the difference between average post-test and pre-test scores:</p>
<div class="formula">Knowledge Gain = Post-Test Average − Pre-Test Average</div>
<p>Example: if the cohort averaged 42% on the pre-test and 67% on the post-test, the knowledge gain is <strong>+25 percentage points</strong>.</p>
<div class="tip">A positive gain confirms that learning occurred. A gain of 0 or negative may indicate content alignment issues or prior knowledge already being high.</div>

<h2>6. Normalised Gain Index (NGI)</h2>
<p>The raw knowledge gain is misleading when pre-test scores differ across cohorts — a group starting at 80% cannot gain as much as a group starting at 20%. The Normalised Gain Index (NGI) corrects for this by expressing gain as a fraction of the maximum possible gain:</p>
<div class="formula">NGI = (Post% − Pre%) ÷ (100 − Pre%) × 100</div>
<table>
  <tr><th>NGI Range</th><th>Interpretation</th><th>Action</th></tr>
  <tr><td>&lt;30%</td><td>Low gain — limited learning occurred</td><td>Review content quality, learner engagement, time on task</td></tr>
  <tr><td>30–70%</td><td>Moderate gain — reasonable learning</td><td>Identify which modules are below 30% for targeted improvement</td></tr>
  <tr><td>&gt;70%</td><td>High gain — strong learning outcomes</td><td>Document as evidence of programme effectiveness</td></tr>
</table>
<h3>Worked Example</h3>
<p>Pre-test average = 35%, Post-test average = 62%</p>
<div class="formula">NGI = (62 − 35) ÷ (100 − 35) × 100 = 27 ÷ 65 × 100 ≈ 41.5%</div>
<p>Interpretation: <strong>Moderate gain</strong> — the cohort achieved 42% of the maximum possible improvement.</p>

<h2>7. Impact Survey — "Did This Change You?"</h2>
<p>The Impact Survey is a mandatory 3-question survey that appears prominently at the end of every lesson quiz and immediately after the post-test. It is displayed as a bold pulsing red button labelled <strong>"Did This Change You? Tell Us Now"</strong> so learners cannot miss it.</p>
<h3>Survey questions</h3>
<ol>
  <li><strong>Behaviour change (q1_changed):</strong> "Since starting this module, have you made any changes to your behaviour or habits?"</li>
  <li><strong>Knowledge sharing (q2_shared):</strong> "Have you shared what you learned with a friend, family member, or peer?"</li>
  <li><strong>Self-efficacy (q3_confident):</strong> "Do you feel more confident handling situations related to this topic?"</li>
</ol>
<p>Each Yes/No response automatically opens a contextual text field prompting the learner to describe their answer in their own words. For example, a "Yes" on question 1 prompts: <em>"What changes did you make? Describe what you did differently."</em></p>
<h3>Calculated indicators</h3>
<table>
  <tr><th>Indicator</th><th>Formula</th><th>Where displayed</th></tr>
  <tr><td>% Behavior Change</td><td>q1_changed=1 ÷ total responses × 100</td><td>Analytics dashboard, Project Map</td></tr>
  <tr><td>% Knowledge Shared</td><td>q2_shared=1 ÷ total responses × 100</td><td>Analytics dashboard, Project Map</td></tr>
  <tr><td>% Feel Confident</td><td>q3_confident=1 ÷ total responses × 100</td><td>Analytics dashboard</td></tr>
</table>
<h3>Text responses</h3>
<p>The free-text fields (q1_detail, q2_detail, q3_detail) are stored in the <code>behavioral_surveys</code> table and exported via DataPost. They provide qualitative evidence for donor reports and programme reviews — quote these in reporting to illustrate impact beyond the numbers.</p>
<div class="tip">Self-reported behaviour change (Level 3) is inherently subject to social desirability bias. Use these results directionally, not as absolute measures. The text responses are more valuable for qualitative reporting than the binary percentages alone.</div>
<div class="note">The survey is submitted once per learner per module. Duplicate submissions are silently ignored. After submission, the pulsing red button is replaced with a green <em>"Impact Survey Complete"</em> badge.</div>

<h2>8. Completion Funnel Analysis</h2>
<p>The completion funnel tracks the proportion of learners who progress through each stage:</p>
<ol>
  <li>Registered</li>
  <li>Opened a Module</li>
  <li>Pre-Test Done</li>
  <li>Lessons Completed</li>
  <li>Post-Test Done</li>
  <li>Certified (≥60%)</li>
  <li><strong>Impact Survey Submitted</strong></li>
</ol>
<p>Drop-off percentages between steps identify where learners disengage. Common patterns:</p>
<table>
  <tr><th>Drop-off Point</th><th>Possible Causes</th></tr>
  <tr><td>Registered → Opened Module</td><td>Device/connectivity issues; lack of facilitator guidance at session start</td></tr>
  <tr><td>Pre-Test → Lessons</td><td>Discouraging pre-test score; confusing module navigation</td></tr>
  <tr><td>Lessons → Post-Test</td><td>Session ended before completion; content too long</td></tr>
  <tr><td>Post-Test → Certified</td><td>Score below 60% threshold — needs remediation or re-attempt</td></tr>
  <tr><td>Post-Test → Survey</td><td>Should be near-zero — survey is auto-triggered after post-test and displayed as a pulsing red button after every lesson quiz; low survey rates indicate learners are closing the page before completing</td></tr>
</table>

<h2>9. Cohort Comparison</h2>
<p>The cohort comparison table groups learners by <strong>Project</strong> and <strong>Cluster</strong> and compares:</p>
<ul>
  <li>Number of learners</li>
  <li>Average quiz score</li>
  <li>Quiz pass rate (≥60%)</li>
  <li>Certificates earned</li>
  <li>Knowledge gain (post avg − pre avg)</li>
</ul>
<div class="note">For meaningful cohort comparison, each cohort should have at least 10 learners with matched pre/post test data.</div>

<h2>10. Difficulty Tracking</h2>
<p>Every answer to every quiz question is saved individually. The <em>Most Difficult Questions</em> report calculates the wrong-answer rate per question:</p>
<div class="formula">Wrong Rate = Wrong Answers ÷ Total Attempts × 100</div>
<table>
  <tr><th>Wrong Rate</th><th>Classification</th><th>Recommended Action</th></tr>
  <tr><td>&gt;70%</td><td>Hard</td><td>Review question wording; check if content adequately covers this concept</td></tr>
  <tr><td>50–70%</td><td>Moderate</td><td>Consider adding more content or examples for this topic</td></tr>
  <tr><td>&lt;50%</td><td>Mild</td><td>No immediate action needed</td></tr>
</table>
<p>Requires a minimum of 3 attempts per question to appear in the report.</p>

<h2>11. Retention Test (30-Day Follow-Up)</h2>
<p>Learners who earned a certificate can take a 5-question retention test 30 days after certification. This measures long-term knowledge retention and is a stronger indicator of programme effectiveness than immediate post-test scores.</p>
<p>Retention test scores are stored in the <code>retention_tests</code> table and are included in DataPost exports. They are not yet visualised in the analytics dashboard — use DataPost export for analysis.</p>
<div class="tip">A retention score close to or above the post-test score indicates durable learning. A significant drop suggests the content was learned for the test but not retained — recommend spaced repetition interventions.</div>

<h2>12. The Analytics Dashboard</h2>
<p>Access via: <strong>Admin → Insights → Analytics</strong></p>
<p>The dashboard has 8 sections:</p>
<table>
  <tr><th>Section</th><th>What to look at</th></tr>
  <tr><td>Impact KPIs</td><td>Overview: pre/post averages, NGI, pass rate, completion rate, certs</td></tr>
  <tr><td>Completion Funnel</td><td>Where learners drop off in the learning journey</td></tr>
  <tr><td>Knowledge Gain by Module</td><td>Which modules produce the most learning; which need improvement</td></tr>
  <tr><td>Cohort Comparison</td><td>Performance differences between projects and clusters</td></tr>
  <tr><td>Per-Module Funnels</td><td>Completion stages for the 8 most-viewed modules</td></tr>
  <tr><td>Daily Activity Chart</td><td>Session activity over time — identify active vs inactive days</td></tr>
  <tr><td>Behavioral Survey</td><td>Self-reported behaviour change percentages</td></tr>
  <tr><td>Difficult Questions</td><td>Top 10 questions with highest wrong-answer rates</td></tr>
</table>
<h3>Date range filter</h3>
<p>Use the <strong>Last 7 / 30 / 90 Days / All Time</strong> buttons at the top to filter activity data. Note: pre/post test KPIs are not date-filtered — they always show cumulative averages.</p>

<h2>13. Exporting Data</h2>
<h3>DataPost (full export)</h3>
<div class="code">http://192.168.0.10/arise/?p=datapost<br>Short: http://192.168.0.10/data</div>
<p>Returns JSON with all platform data. Learner names are replaced with anonymous LRN-XXXX codes. Copy the JSON output into a spreadsheet tool or statistical software for analysis.</p>
<h3>Recommended analysis workflow</h3>
<ol>
  <li>After each session, open DataPost on a connected device.</li>
  <li>Copy or download the JSON output.</li>
  <li>Import into Excel / Google Sheets / R / SPSS.</li>
  <li>Use the <code>pretests</code> array for matched pre/post analysis.</li>
  <li>Use the <code>summary</code> object for quick programme-level KPIs.</li>
</ol>

<h2>14. Reporting Templates &amp; Interpretation Guide</h2>
<h3>Session Report (after each training day)</h3>
<table>
  <tr><th>Indicator</th><th>Where to find it</th><th>Target</th></tr>
  <tr><td>Learners registered</td><td>Analytics KPIs → Registered</td><td>100% of planned participants</td></tr>
  <tr><td>Completion rate</td><td>Analytics KPIs → Completion Rate</td><td>&gt;70%</td></tr>
  <tr><td>Average knowledge gain</td><td>Knowledge Gain by Module</td><td>&gt;+15 percentage points</td></tr>
  <tr><td>Normalised Gain Index</td><td>Analytics KPIs → NGI</td><td>&gt;30%</td></tr>
  <tr><td>Quiz pass rate</td><td>Analytics KPIs → Quiz Pass Rate</td><td>&gt;60%</td></tr>
  <tr><td>Certificates issued</td><td>Analytics KPIs → Certificates Issued</td><td>&gt;50% of completers</td></tr>
</table>

<h3>Programme Report (quarterly / annual)</h3>
<ul>
  <li>Cohort comparison table — identify highest and lowest performing clusters.</li>
  <li>Module knowledge gain ranking — flag modules with NGI &lt;30% for content review.</li>
  <li>Behavioral survey trends — % changed behaviour, % shared knowledge.</li>
  <li>Difficult questions list — inform content revision priorities.</li>
  <li>Retention test scores (where available) — measure durable learning.</li>
</ul>

<div class="note">
  <strong>Data Quality Note:</strong> For any statistical comparison, ensure cohort sizes are adequate (n≥30), pre/post matching is clean (same session hash per learner), and missing data is documented. The ARISE analytics dashboard shows aggregate trends; for rigorous statistical testing, export via DataPost and analyse in dedicated statistical software.
</div>

<div style="margin-top:60px;padding-top:20px;border-top:2px solid #e5e7eb;font-size:.82rem;color:#6b7280;text-align:center;">
  ARISE Impact Assessment Manual &mdash; v<?= e($ver) ?> &mdash; <?= date('Y') ?>
</div>
</div>
</body>
</html>
