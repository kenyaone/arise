<?php
/**
 * Student Registration — with password field and updated terminology
 * "School" → "Project" in labels (input name stays school_name)
 * "Class" → "Cluster" in labels (input name stays class_name)
 * Password is required (min 6 chars, must match confirm)
 */
trackPageView('register');

// Load ARISE clusters first, then projects grouped by cluster
$clustersResult = db()->query("SELECT id, name FROM clusters ORDER BY name");
$clusters = [];
while ($c = $clustersResult->fetchArray(SQLITE3_ASSOC)) $clusters[] = $c;

$schoolsResult = db()->query("SELECT s.id, s.name, s.cluster_id, COALESCE(c.name,'') AS cluster_name FROM schools s LEFT JOIN clusters c ON c.id=s.cluster_id WHERE s.is_active=1 ORDER BY s.name");
$schools = [];
$schoolsByCluster = []; // cluster_id => [schools]
while ($s = $schoolsResult->fetchArray(SQLITE3_ASSOC)) {
    $schools[] = $s;
    $cid = $s['cluster_id'] ?: 'none';
    $schoolsByCluster[$cid][] = $s;
}
$hasSchools = count($schools) > 0;
$hasClusters = count($clusters) > 0;
?>

<div class="container">
    <div class="breadcrumb">
        <a href="/arise/">Home</a> <span class="sep">›</span>
        <span>Register</span>
    </div>

    <div style="max-width:520px; margin:0 auto;">
        <div class="dp-card" style="border-top:4px solid var(--pri); padding:32px;">

            <div style="text-align:center; margin-bottom:20px;">
                <div style="width:72px; height:72px; background:linear-gradient(135deg,var(--pri),var(--rose)); border-radius:20px; display:inline-flex; align-items:center; justify-content:center; font-size:2rem; margin-bottom:14px; box-shadow:0 8px 24px rgba(124,58,237,.3);">💜</div>
                <h1 class="page-title" style="margin-bottom:6px;">Join ARISE</h1>
                <p style="color:#6b7280; margin:0; font-size:0.9rem;">Create your free learner account — takes 30 seconds</p>
            </div>

            <?php if (isset($_GET['error'])): ?>
                <div class="alert alert-error" style="background:#fef2f2; color:#991b1b; border-left:4px solid #dc2626; padding:12px 16px; border-radius:6px; margin-bottom:16px;">
                    ⚠️ Please fill in all required fields correctly.
                </div>
            <?php endif; ?>
            <?php if (isset($_GET['password_error'])): ?>
                <div class="alert alert-error" style="background:#fef2f2; color:#991b1b; border-left:4px solid #dc2626; padding:12px 16px; border-radius:6px; margin-bottom:16px;">
                    🔑 <?= e(urldecode($_GET['password_error'])) ?>
                </div>
            <?php endif; ?>
            <?php if (isset($_GET['exists'])): ?>
                <div class="alert alert-success" style="background:#f0fdf4; color:#166534; border-left:4px solid #16a34a; padding:12px 16px; border-radius:6px; margin-bottom:16px;">
                    👋 Welcome back! You're already registered.
                </div>
            <?php endif; ?>

            <form method="POST" action="/arise/?p=register_submit">

                <!-- Full Name -->
                <div class="form-group" style="margin-bottom:16px;">
                    <label class="form-label" style="display:block; font-size:.78rem; font-weight:700; color:var(--mid); margin-bottom:6px; text-transform:uppercase; letter-spacing:.4px;">
                        Full Name *
                    </label>
                    <input type="text" name="full_name" class="form-control"
                           placeholder="e.g. Jane Wanjiku" required autofocus
                           style="width:100%; padding:10px 14px; border:2px solid var(--border); border-radius:8px; font-size:1rem; box-sizing:border-box;">
                </div>

                <?php if ($hasClusters && $hasSchools): ?>

                <!-- Step 1: ARISE Cluster -->
                <div class="form-group" style="margin-bottom:16px;">
                    <label class="form-label" style="display:block;font-size:.78rem;font-weight:700;color:var(--mid);margin-bottom:6px;text-transform:uppercase;letter-spacing:.4px;">
                        Cluster *
                    </label>
                    <select id="clusterSelect" required
                            onchange="loadProjects(this.value)"
                            class="form-control"
                            style="width:100%;padding:10px 14px;border:2px solid var(--border);border-radius:8px;font-size:1rem;box-sizing:border-box;">
                        <option value="">— Select your cluster —</option>
                        <?php foreach($clusters as $cl): ?>
                            <option value="<?= $cl['id'] ?>"><?= e($cl['name']) ?></option>
                        <?php endforeach; ?>
                        <?php if (!empty($schoolsByCluster['none'])): ?>
                            <option value="none">Other / Not listed</option>
                        <?php endif; ?>
                    </select>
                </div>

                <!-- Step 2: Project (filtered by cluster) -->
                <div class="form-group" style="margin-bottom:20px;" id="projectWrap">
                    <label class="form-label" style="display:block;font-size:.78rem;font-weight:700;color:var(--mid);margin-bottom:6px;text-transform:uppercase;letter-spacing:.4px;">
                        Project *
                    </label>
                    <select name="school_name" id="schoolSelect" required
                            class="form-control"
                            style="width:100%;padding:10px 14px;border:2px solid var(--border);border-radius:8px;font-size:1rem;box-sizing:border-box;">
                        <option value="">— Select cluster first —</option>
                    </select>
                </div>

                <script>
                var schoolsByCluster = <?= json_encode(array_map(function($list) {
                    return array_map(fn($s) => ['id'=>$s['id'],'name'=>$s['name']], $list);
                }, $schoolsByCluster)) ?>;

                function loadProjects(clusterId) {
                    var projSel = document.getElementById('schoolSelect');
                    projSel.innerHTML = '<option value="">— Select your project —</option>';
                    var list = schoolsByCluster[clusterId] || [];
                    list.forEach(function(s) {
                        var o = document.createElement('option');
                        o.value = s.name; o.textContent = s.name;
                        projSel.appendChild(o);
                    });
                }
                </script>

                <?php else: ?>
                <!-- Fallback: free text if no projects configured yet -->
                <div class="form-group" style="margin-bottom:20px;">
                    <label class="form-label" style="display:block; font-size:.78rem; font-weight:700; color:var(--mid); margin-bottom:6px; text-transform:uppercase; letter-spacing:.4px;">
                        Project Name *
                    </label>
                    <input type="text" name="school_name" class="form-control"
                           placeholder="e.g. Moi Girls Eldoret" required
                           style="width:100%; padding:10px 14px; border:2px solid var(--border); border-radius:8px; font-size:1rem; box-sizing:border-box;">
                </div>
                <div class="alert alert-success" style="background:#f0fdf4; color:#166534; border-left:4px solid #16a34a; padding:12px 16px; border-radius:6px; margin-bottom:16px; font-size:0.88rem;">
                    💡 <strong>Note:</strong> Ask your teacher to add your project and clusters in the admin panel for easier registration next time.
                </div>
                <?php endif; ?>

                <!-- Password -->
                <div class="form-group" style="margin-bottom:16px;">
                    <label class="form-label" style="display:block; font-size:.78rem; font-weight:700; color:var(--mid); margin-bottom:6px; text-transform:uppercase; letter-spacing:.4px;">
                        Password * <span style="font-weight:400; font-style:italic; text-transform:none; font-size:0.72rem;">(min 6 characters)</span>
                    </label>
                    <input type="password" name="password" class="form-control"
                           required minlength="6"
                           placeholder="Choose a password"
                           style="width:100%; padding:10px 14px; border:2px solid var(--border); border-radius:8px; font-size:1rem; box-sizing:border-box;">
                </div>

                <!-- Confirm Password -->
                <div class="form-group" style="margin-bottom:24px;">
                    <label class="form-label" style="display:block; font-size:.78rem; font-weight:700; color:var(--mid); margin-bottom:6px; text-transform:uppercase; letter-spacing:.4px;">
                        Confirm Password *
                    </label>
                    <input type="password" name="password_confirm" class="form-control"
                           required minlength="6"
                           placeholder="Repeat your password"
                           style="width:100%; padding:10px 14px; border:2px solid var(--border); border-radius:8px; font-size:1rem; box-sizing:border-box;">
                </div>

                <button type="submit" class="btn btn-primary"
                        style="width:100%; border-radius:12px; padding:16px; font-size:1rem;">
                    💜 Register &amp; Start Learning &rarr;
                </button>
            </form>

            <p style="text-align:center; color:#6b7280; font-size:.78rem; margin-top:16px; margin-bottom:0;">
                🔒 Your information is stored locally and kept private.
            </p>
        </div>

        <!-- Already registered? -->
        <div style="text-align:center; margin-top:14px;">
            <p style="color:#6b7280; font-size:0.85rem;">
                Already registered? <a href="/arise/?p=login" style="font-weight:700; color:var(--pri);">Sign in &rarr;</a>
            </p>
        </div>
    </div>
</div>
