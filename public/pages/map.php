<?php
if (!function_exists('db')) {
    require_once __DIR__ . '/../../includes/config.php';
}

// ── DB migrations (safe, idempotent) ─────────────────────────────────────
foreach ([
    "ALTER TABLE schools ADD COLUMN lat REAL DEFAULT NULL",
    "ALTER TABLE schools ADD COLUMN lng REAL DEFAULT NULL",
] as $sql) { try { db()->exec($sql); } catch(Exception $e) {} }

// ── Seed coordinates for Kenyan schools (run once per school) ──────────────
$coords = [
    'Arise School' => [0.5204, 35.2698],
    'Moi Girls High School Eldoret' => [0.5174, 35.2740],
    'Kapsabet Boys High School' => [0.2028, 35.1045],
    'Nairobi School' => [-1.2980, 36.8240],
    'Alliance Girls High School' => [-1.1713, 36.8351],
    'St. Mary\'s School Nairobi' => [-1.2860, 36.8200],
    'Matioli' => [0.1028, 34.3437],
];

foreach ($coords as $name => $coord) {
    $check = db()->querySingle("SELECT lat FROM schools WHERE name=? AND lat IS NULL", true, [$name]);
    if ($check === null) { // name exists but no coords yet
        $stmt = db()->prepare("UPDATE schools SET lat=?, lng=? WHERE name=?");
        $stmt->bindValue(1, $coord[0]);
        $stmt->bindValue(2, $coord[1]);
        $stmt->bindValue(3, $name);
        try { $stmt->execute(); } catch(Exception $e) {}
    }
}

// ── Collect per-school analytics ─────────────────────────────────────────
$stmt = db()->prepare("
    SELECT
        sc.id, sc.name, sc.county, sc.lat, sc.lng,
        COUNT(DISTINCT s.id) AS learners,
        COUNT(DISTINCT c.id) AS certs,
        ROUND(AVG(CAST(qa.percentage AS REAL)), 1) AS avg_score,
        COUNT(DISTINCT qa.id) AS quiz_attempts
    FROM schools sc
    LEFT JOIN students s ON s.school_name = sc.name AND s.deleted_at IS NULL
    LEFT JOIN certificates c ON c.student_id = s.id
    LEFT JOIN quiz_attempts qa ON qa.student_id = s.id
    WHERE sc.is_active = 1
    GROUP BY sc.id
    ORDER BY learners DESC
");
$schools = $stmt->execute()->fetchAll(SQLITE3_ASSOC);

// ── Compute global totals ────────────────────────────────────────────────
$total_projects = count($schools);
$total_learners = 0;
$total_certs = 0;
$total_attempts = 0;

foreach ($schools as $s) {
    $total_learners += (int)$s['learners'];
    $total_certs += (int)$s['certs'];
    $total_attempts += (int)$s['quiz_attempts'];
}

$global_avg_score = $total_attempts > 0
    ? round(db()->querySingle("SELECT AVG(CAST(percentage AS REAL)) FROM quiz_attempts"), 1)
    : 0;

$schoolsJson = json_encode($schools);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ARISE Projects Map</title>
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <link rel="stylesheet" href="/arise/css/style.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Segoe UI', Arial, sans-serif;
            background: linear-gradient(135deg, #0D5E3D 0%, #1E8055 35%, #FF9700 100%);
            background-attachment: fixed;
            min-height: 100vh;
            color: #1a1a1a;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
        }

        .header {
            background: rgba(255, 255, 255, 0.95);
            border-radius: 12px;
            padding: 25px;
            margin-bottom: 20px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }

        .header-top {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            flex-wrap: wrap;
            gap: 15px;
        }

        .header-top h1 {
            font-size: 2rem;
            color: #0a5e2a;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .print-btn {
            background: #0ea271;
            color: white;
            border: none;
            padding: 12px 24px;
            border-radius: 8px;
            font-weight: 700;
            cursor: pointer;
            font-size: 0.95rem;
        }

        .print-btn:hover { background: #059669; }

        .stats-bar {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
        }

        .stat-item {
            background: linear-gradient(135deg, #0a5e2a, #0ea271);
            color: white;
            padding: 15px;
            border-radius: 8px;
            text-align: center;
        }

        .stat-item .number {
            font-size: 1.8rem;
            font-weight: 900;
        }

        .stat-item .label {
            font-size: 0.9rem;
            opacity: 0.9;
            margin-top: 5px;
        }

        .map-section {
            background: white;
            border-radius: 12px;
            overflow: hidden;
            margin-bottom: 20px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }

        #map {
            height: 500px;
            width: 100%;
        }

        .projects-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 20px;
            margin-top: 20px;
        }

        .project-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
            border-left: 4px solid #0ea271;
            transition: transform 0.2s, box-shadow 0.2s;
        }

        .project-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.15);
        }

        .project-card h3 {
            color: #0a5e2a;
            font-size: 1.1rem;
            margin-bottom: 8px;
        }

        .project-card p {
            color: #666;
            font-size: 0.9rem;
            margin: 8px 0;
            display: flex;
            justify-content: space-between;
        }

        .project-card .county {
            color: #0ea271;
            font-weight: 600;
            margin-bottom: 12px;
        }

        .metric {
            display: flex;
            justify-content: space-between;
            padding: 6px 0;
            border-top: 1px solid #f0f0f0;
        }

        .metric:first-of-type {
            border-top: none;
        }

        .metric-label {
            color: #666;
        }

        .metric-value {
            font-weight: 700;
            color: #0a5e2a;
        }

        .footer {
            background: rgba(255, 255, 255, 0.95);
            border-radius: 12px;
            padding: 20px;
            text-align: center;
            color: #666;
            margin-top: 20px;
        }

        .footer p {
            margin: 8px 0;
        }

        .footer a {
            color: #0ea271;
            text-decoration: none;
            font-weight: 600;
        }

        .footer a:hover { text-decoration: underline; }

        @media print {
            body { background: white; }
            .print-btn { display: none; }
            .container { max-width: 100%; }
        }

        @media(max-width: 768px) {
            .header-top {
                flex-direction: column;
                align-items: flex-start;
            }

            .header-top h1 {
                font-size: 1.5rem;
            }

            .stats-bar {
                grid-template-columns: repeat(2, 1fr);
            }

            #map { height: 350px; }

            .projects-grid {
                grid-template-columns: 1fr;
            }
        }

        .leaflet-popup-content {
            font-family: 'Segoe UI', Arial, sans-serif;
        }

        .leaflet-popup-content h4 {
            color: #0a5e2a;
            margin-bottom: 8px;
            font-size: 1rem;
        }

        .leaflet-popup-content p {
            margin: 4px 0;
            color: #666;
            font-size: 0.9rem;
        }
    </style>
</head>
<body>

<div class="container">
    <!-- Header -->
    <div class="header">
        <div class="header-top">
            <h1>🗺 ARISE Projects Map</h1>
            <button class="print-btn" onclick="window.print()">🖨️ Print / Save as PDF</button>
        </div>

        <div class="stats-bar">
            <div class="stat-item">
                <div class="number"><?= $total_projects ?></div>
                <div class="label">Active Projects</div>
            </div>
            <div class="stat-item">
                <div class="number"><?= number_format($total_learners) ?></div>
                <div class="label">Total Learners</div>
            </div>
            <div class="stat-item">
                <div class="number"><?= number_format($total_certs) ?></div>
                <div class="label">Certificates Earned</div>
            </div>
            <div class="stat-item">
                <div class="number"><?= $global_avg_score ?>%</div>
                <div class="label">Avg Quiz Score</div>
            </div>
        </div>
    </div>

    <!-- Map -->
    <div class="map-section">
        <div id="map"></div>
    </div>

    <!-- Project Cards -->
    <div>
        <h2 style="color: white; margin-bottom: 15px;">📍 Projects Across Kenya</h2>
        <div class="projects-grid">
            <?php foreach ($schools as $school): ?>
            <div class="project-card">
                <h3><?= htmlspecialchars($school['name']) ?></h3>
                <p class="county">📌 <?= htmlspecialchars($school['county'] ?? 'Unknown') ?></p>

                <div class="metric">
                    <span class="metric-label">👩‍🎓 Learners</span>
                    <span class="metric-value"><?= $school['learners'] ?? 0 ?></span>
                </div>
                <div class="metric">
                    <span class="metric-label">📝 Quiz Avg</span>
                    <span class="metric-value"><?= ($school['avg_score'] ?? 0) > 0 ? $school['avg_score'] . '%' : 'N/A' ?></span>
                </div>
                <div class="metric">
                    <span class="metric-label">🎓 Certificates</span>
                    <span class="metric-value"><?= $school['certs'] ?? 0 ?></span>
                </div>
                <div class="metric">
                    <span class="metric-label">📊 Quizzes</span>
                    <span class="metric-value"><?= $school['quiz_attempts'] ?? 0 ?></span>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Footer -->
    <div class="footer">
        <p><strong>ARISE Platform</strong> — Adolescent Reproductive Health Information Support & Empowerment</p>
        <p>Real-time map of ARISE project deployments across Kenya</p>
        <p style="margin-top: 15px;"><a href="/arise/">← Back to ARISE Home</a></p>
    </div>
</div>

<!-- Leaflet JS -->
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script>
    const schoolsData = <?= $schoolsJson ?>;

    // ── Initialize map ────────────────────────────────────────────────────
    const map = L.map('map').setView([-0.023559, 37.906193], 6);

    // ── OpenStreetMap tile layer ──────────────────────────────────────────
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19,
        attribution: '© OpenStreetMap contributors',
        className: 'map-tiles'
    }).addTo(map);

    // ── Add markers for each school ────────────────────────────────────────
    schoolsData.forEach(school => {
        if (school.lat && school.lng) {
            const popupHtml = `
                <div style="font-size: 0.9rem; width: 200px;">
                    <h4 style="margin-bottom: 8px; color: #0a5e2a;">${school.name}</h4>
                    <p style="margin: 4px 0;">📌 ${school.county || 'Unknown'}</p>
                    <p style="margin: 4px 0;">👩‍🎓 <strong>${school.learners || 0}</strong> learners</p>
                    <p style="margin: 4px 0;">📝 Avg: <strong>${school.avg_score > 0 ? school.avg_score + '%' : 'N/A'}</strong></p>
                    <p style="margin: 4px 0;">🎓 <strong>${school.certs || 0}</strong> certificates</p>
                </div>
            `;

            const marker = L.circleMarker([school.lat, school.lng], {
                radius: 12,
                fillColor: '#0ea271',
                color: '#059669',
                weight: 2,
                opacity: 0.8,
                fillOpacity: 0.7
            })
            .bindPopup(popupHtml)
            .addTo(map);
        }
    });
</script>

</body>
</html>
