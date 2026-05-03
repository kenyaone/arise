<?php
require_once __DIR__ . '/../../includes/config.php';
$ver = defined('ARISE_VERSION') ? ARISE_VERSION : '1.0';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>ARISE User Manual</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Segoe UI',Arial,sans-serif;font-size:13pt;color:#111;background:#fff;line-height:1.6}
.cover{background:linear-gradient(135deg,#052e16,#0a5e2a);color:#fff;padding:80px 60px;min-height:260px;display:flex;flex-direction:column;justify-content:center}
.cover h1{font-size:2.8rem;font-weight:900;letter-spacing:-.5px;margin-bottom:8px}
.cover .sub{font-size:1.1rem;color:rgba(255,255,255,.7);margin-bottom:30px}
.cover .meta{font-size:.9rem;color:rgba(255,255,255,.5)}
.content{max-width:820px;margin:0 auto;padding:40px 40px 80px}
h2{font-size:1.4rem;font-weight:800;color:#052e16;border-bottom:3px solid #0ea271;padding-bottom:6px;margin:36px 0 14px}
h3{font-size:1.05rem;font-weight:700;color:#065f46;margin:22px 0 8px}
p{margin-bottom:10px}
ul,ol{margin:8px 0 12px 24px}
li{margin-bottom:5px}
.tip{background:#f0fdf4;border-left:4px solid #0ea271;border-radius:0 8px 8px 0;padding:12px 16px;margin:14px 0;font-size:.95rem}
.warn{background:#fffbeb;border-left:4px solid #f59e0b;border-radius:0 8px 8px 0;padding:12px 16px;margin:14px 0;font-size:.95rem}
.code{background:#f3f4f6;border-radius:6px;padding:10px 14px;font-family:monospace;font-size:.9rem;margin:10px 0;word-break:break-all}
table{width:100%;border-collapse:collapse;margin:12px 0}
th{background:#052e16;color:#fff;padding:8px 12px;font-size:.85rem;text-align:left}
td{padding:8px 12px;border-bottom:1px solid #e5e7eb;font-size:.9rem}
tr:nth-child(even) td{background:#f9fafb}
.badge{display:inline-block;background:#dcfce7;color:#065f46;border-radius:20px;padding:2px 10px;font-size:.78rem;font-weight:700}
.no-print{margin-bottom:0}
@media print{
  .no-print{display:none}
  .cover{-webkit-print-color-adjust:exact;print-color-adjust:exact}
  body{font-size:11pt}
}
@page{margin:15mm 20mm}
</style>
</head>
<body>

<div class="no-print" style="background:#052e16;padding:12px 40px;display:flex;align-items:center;justify-content:space-between;">
  <div style="display:flex;gap:20px;align-items:center;">
    <span style="color:#6ee7b7;font-weight:700;">📖 User Manual</span>
    <a href="/arise/?p=manual_tech" style="color:#9ca3af;text-decoration:none;font-size:.9rem;padding:6px 12px;border-radius:6px;border:1px solid #374151;transition:.2s">⚙ Technical Docs</a>
    <a href="/arise/?p=manual_impact" style="color:#9ca3af;text-decoration:none;font-size:.9rem;padding:6px 12px;border-radius:6px;border:1px solid #374151;transition:.2s">📊 Impact Guide</a>
    <a href="/arise/?p=datapost" style="color:#9ca3af;text-decoration:none;font-size:.9rem;padding:6px 12px;border-radius:6px;border:1px solid #374151;transition:.2s">💾 DataPost</a>
  </div>
  <button onclick="window.print()" style="background:#0ea271;color:#fff;border:none;border-radius:8px;padding:8px 20px;font-weight:700;cursor:pointer;font-size:.9rem;">
    &#128424; Print / Save as PDF
  </button>
</div>

<div class="cover">
  <div style="font-size:.8rem;letter-spacing:2px;text-transform:uppercase;color:rgba(255,255,255,.4);margin-bottom:12px;">ARISE Platform</div>
  <h1>User Manual</h1>
  <div class="sub">For Learners &amp; Facilitators</div>
  <div class="meta">Version <?= e($ver) ?> &nbsp;&middot;&nbsp; <?= date('F Y') ?> &nbsp;&middot;&nbsp; Adolescent Reproductive Health Information Support &amp; Empowerment</div>
</div>

<div class="content">

<h2>Table of Contents</h2>
<ol>
  <li>Introduction to ARISE</li>
  <li>Getting Started — Learner Registration</li>
  <li>Navigating the Platform</li>
  <li>Learning Modules</li>
  <li>Pre-Test &amp; Post-Test</li>
  <li>Quizzes</li>
  <li>Challenges &amp; Activities</li>
  <li>Forum &amp; Ask Us</li>
  <li>Certificates</li>
  <li>Facilitator Mode</li>
  <li>Tips for a Good Session</li>
</ol>

<h2>1. Introduction to ARISE</h2>
<p>ARISE is an offline digital learning platform designed for adolescent reproductive and sexual health education. It runs entirely on a local WiFi network — no internet connection is needed. A facilitator brings a laptop to the training venue, connects all devices to the same WiFi, and learners access the platform through their phone or tablet browsers.</p>
<div class="tip"><strong>What ARISE does:</strong> delivers structured health education modules, tracks each learner's progress, measures knowledge before and after training, and generates completion certificates.</div>

<h2>2. Getting Started — Learner Registration</h2>
<h3>Access the Platform</h3>
<ol>
  <li>Connect your device to the facilitator's WiFi hotspot (LN).</li>
  <li>Open any browser (Chrome, Firefox, Safari).</li>
  <li>Type this address in the address bar:</li>
</ol>
<div class="code">http://192.168.0.10/arise/</div>
<div class="tip"><strong>Bookmark this!</strong> You can save this URL for quick access next time.</div>

<h3>Register Your Account</h3>
<ol>
  <li>Click <strong>Register</strong> in the top navigation bar.</li>
  <li>Enter your <strong>Full Name</strong>, <strong>Age</strong>, <strong>Gender</strong>, <strong>School / Project</strong>, and <strong>Class / Cluster</strong>.</li>
  <li>Create a <strong>PIN</strong> (4–6 digits) — you will use this to sign in again.</li>
  <li>Click <strong>Create Account</strong>.</li>
</ol>
<div class="warn"><strong>Remember your PIN!</strong> There is no password reset. If you forget your PIN, ask your facilitator to help you via the admin panel.</div>

<h2>3. Navigating the Platform</h2>
<table>
  <tr><th>Menu Item</th><th>What it does</th></tr>
  <tr><td>Home</td><td>Welcome page with featured modules and quick links</td></tr>
  <tr><td>Modules</td><td>Browse and access all learning modules</td></tr>
  <tr><td>Forum</td><td>Community discussion space</td></tr>
  <tr><td>Ask Us</td><td>Send a private anonymous question to educators</td></tr>
  <tr><td>Help</td><td>Emergency contacts, SOS helplines, platform help</td></tr>
  <tr><td>Your Name (top right)</td><td>Personal dashboard — progress, XP, history</td></tr>
  <tr><td>Certs</td><td>Download your completion certificates</td></tr>
</table>

<h2>4. Learning Modules</h2>
<p>Each module covers a specific health topic. When you open a module you will see:</p>
<ol>
  <li><strong>Knowledge Assessment</strong> section at the top — start here with the Pre-Test.</li>
  <li><strong>Lesson List</strong> — interactive lessons, videos, and reading materials.</li>
  <li><strong>Post-Test</strong> — appears after the Pre-Test is completed.</li>
  <li><strong>Certificate</strong> button — unlocks after passing the Post-Test.</li>
</ol>
<div class="tip">Complete lessons in order. Each lesson tracks your progress and awards XP points for completion.</div>

<h2>5. Pre-Test &amp; Post-Test</h2>
<p>The <span class="badge">Pre-Test</span> is a 5-question quiz taken <em>before</em> studying the module — it measures what you already know. The <span class="badge">Post-Test</span> is taken <em>after</em> completing the lessons. Together they show your knowledge gain.</p>
<h3>How to take a test</h3>
<ol>
  <li>Click <strong>Take Pre-Test</strong> at the top of the module page.</li>
  <li>Read each question carefully and select your answer.</li>
  <li>Click <strong>Submit</strong> when all questions are answered.</li>
  <li>Your score and answer explanations appear immediately.</li>
</ol>
<div class="warn">You cannot change your answers after submitting. Take your time before clicking Submit.</div>

<h2>6. Quizzes</h2>
<p>Each module has a full quiz (10+ questions) separate from the pre/post test. It can be retaken as many times as you like — it is for practice and reinforcement.</p>
<ul>
  <li>A score of <strong>60% or above</strong> is a pass.</li>
  <li>Wrong answers are highlighted after submission with explanations.</li>
  <li>Your highest score is saved to your dashboard.</li>
</ul>

<h2>7. Challenges &amp; Activities</h2>
<p>Some modules include short reflection activities or challenges. These contribute to your XP score and are saved privately to your profile.</p>

<h2>8. Forum &amp; Ask Us</h2>
<h3>Forum</h3>
<p>A shared space where all learners can post questions and comments. Posts are visible to everyone and are moderated.</p>
<h3>Ask Us</h3>
<p>Use <strong>Ask Us</strong> to send a private, anonymous question to educators. Responses appear in the admin panel.</p>

<h2>9. Certificates</h2>
<p>You earn a certificate when you:</p>
<ol>
  <li>Complete the Pre-Test for a module.</li>
  <li>Complete all lessons in the module.</li>
  <li>Score 60% or above on the Post-Test.</li>
</ol>
<p>Certificates show your name, module title, score, and date. They can be printed or saved as PDF from the <strong>Certs</strong> menu.</p>

<h2>10. Facilitator Mode</h2>
<h3>Starting a session</h3>
<ol>
  <li>Log in at <code>http://arise.local/arise/admin/</code></li>
  <li>Go to <strong>System &rarr; Facilitator</strong>.</li>
  <li>Select the project and cluster, then click <strong>Generate Code</strong>.</li>
  <li>A unique 6-character session code is created (e.g. <code>A3FX92</code>).</li>
  <li>Display the code on screen so learners can confirm they are in the right session.</li>
</ol>
<h3>Live monitoring</h3>
<p>Click <strong>View Live</strong> next to an active session to see each learner's progress in real time. The dashboard auto-refreshes every 20 seconds.</p>
<div class="code">http://arise.local/arise/?p=facilitator</div>
<h3>Ending a session</h3>
<p>Click <strong>End</strong> in the Facilitator panel when training is complete. Ended sessions remain in the history for reporting.</p>

<h2>11. Tips for a Good Session</h2>
<ul>
  <li>Ask all learners to connect to WiFi <em>before</em> the session starts.</li>
  <li>Display the URL on a projector using the <strong>Project on Screen</strong> button in the admin dashboard.</li>
  <li>Encourage learners to take the Pre-Test honestly — without it, knowledge gain cannot be measured.</li>
  <li>Allow at least 30 minutes per module for meaningful learning.</li>
  <li>After the session, check <strong>Insights &rarr; Analytics</strong> to review knowledge gain and completion rates.</li>
</ul>

<div style="margin-top:60px;padding-top:20px;border-top:2px solid #e5e7eb;font-size:.82rem;color:#6b7280;text-align:center;">
  ARISE &mdash; Adolescent Reproductive Health Information Support &amp; Empowerment &nbsp;&middot;&nbsp; v<?= e($ver) ?> &nbsp;&middot;&nbsp; <?= date('Y') ?>
</div>
</div>
</body>
</html>
