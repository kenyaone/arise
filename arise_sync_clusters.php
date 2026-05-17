<?php
// One-time cluster sync script — delete after running
// Access: https://ariseci.org/arise/arise_sync_clusters.php

$dbPath = __DIR__ . '/data/arise.db';
if (!file_exists($dbPath)) {
    $m = glob('/home/*/public_html/arise/data/arise.db');
    if (!empty($m)) $dbPath = $m[0];
}
if (!file_exists($dbPath)) { die("DB not found at $dbPath"); }

$db = new SQLite3($dbPath, SQLITE3_OPEN_READWRITE);
$db->busyTimeout(5000);

// Ensure clusters table
$db->exec("CREATE TABLE IF NOT EXISTS clusters (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    name          TEXT NOT NULL UNIQUE,
    password_hash TEXT NOT NULL,
    created_at    DATETIME DEFAULT CURRENT_TIMESTAMP
)");

// Ensure schools has cluster_id
try { $db->exec("ALTER TABLE schools ADD COLUMN cluster_id INTEGER REFERENCES clusters(id)"); } catch(Exception $e){}

$errors = [];
$done = 0;

// Insert clusters
$clusters = [
    [1, 'Kakamega',            'c31495210d0db5e472f4a951f47e62a6fe8da04c006fb9e3925634b5025d6a1b'],
    [2, 'Busia',               '07ceb34a1c56e8cc90e7a7898bd8f6b8023ed412059cb13e50684acd564b8559'],
    [3, 'Homa Bay',            '07ceb34a1c56e8cc90e7a7898bd8f6b8023ed412059cb13e50684acd564b8559'],
    [4, 'Kajiado',             '07ceb34a1c56e8cc90e7a7898bd8f6b8023ed412059cb13e50684acd564b8559'],
    [6, 'Kitui',               '07ceb34a1c56e8cc90e7a7898bd8f6b8023ed412059cb13e50684acd564b8559'],
    [7, 'Kitui-Machakos',      '07ceb34a1c56e8cc90e7a7898bd8f6b8023ed412059cb13e50684acd564b8559'],
    [8, 'Meru',                '07ceb34a1c56e8cc90e7a7898bd8f6b8023ed412059cb13e50684acd564b8559'],
    [9, 'Migori-Narok',        '07ceb34a1c56e8cc90e7a7898bd8f6b8023ed412059cb13e50684acd564b8559'],
    [10,'Nakuru',              '07ceb34a1c56e8cc90e7a7898bd8f6b8023ed412059cb13e50684acd564b8559'],
    [11,'Narok-Kajiado',       '07ceb34a1c56e8cc90e7a7898bd8f6b8023ed412059cb13e50684acd564b8559'],
    [12,'Siaya-Kisumu',        '07ceb34a1c56e8cc90e7a7898bd8f6b8023ed412059cb13e50684acd564b8559'],
    [13,'Tharaka Nithi-Embu',  '07ceb34a1c56e8cc90e7a7898bd8f6b8023ed412059cb13e50684acd564b8559'],
    [14,'Vihiga-Nandi-Kisumu', '07ceb34a1c56e8cc90e7a7898bd8f6b8023ed412059cb13e50684acd564b8559'],
];

foreach ($clusters as [$id, $name, $hash]) {
    $stmt = $db->prepare("INSERT OR IGNORE INTO clusters (id, name, password_hash) VALUES (:id, :name, :hash)");
    $stmt->bindValue(':id',   $id,   SQLITE3_INTEGER);
    $stmt->bindValue(':name', $name, SQLITE3_TEXT);
    $stmt->bindValue(':hash', $hash, SQLITE3_TEXT);
    $stmt->execute();
    $done++;
}

// Assign schools to clusters (by name match)
$assignments = [
    ["A.C.K. St. Luke's Church Manyatta", 12],
    ["A.C.K. St. Peters Church Kwandonga", 7],
    ["A.I.C. Kiini", 7],
    ["ACK Maseno Parish", 14],
    ["ACK Samuel Achola Memorial - Bande", 9],
    ["ACK St. Paul's Yala Church", 12],
    ["ACK St. Peter's Maraba", 1],
    ["ACK St.Paul's Erupata", 11],
    ["AIC Kanzinwa", 6],
    ["AIC Karou", 13],
    ["AIC Mbangwani", 6],
    ["AIC Nthangathini", 7],
    ["AIC Nzewani", 6],
    ["Africa Inland Church Nguuku", 6],
    ["Africa for Christ Evangelistic Association (AFCEA) Lubao", 1],
    ["Agape Sanctuary Ministries Nakuru", 10],
    ["Anglican Church of Kenya- Budokomi", 2],
    ["Anglican Church of Kenya- Namaindi", 2],
    ["Anglican Church of Kenya- St. Paul's Buduma", 2],
    ["Anglican Church of Kenya- St. Thomas Cathedral Nambale", 2],
    ["Baptist Church Olorropil", 11],
    ["Christian Teaching Ministries- Murumba", 2],
    ["Deliverance Church - Kisumu", 12],
    ["Deliverance Church Kendubay", 3],
    ["E.A.P.C Kiamauti church", 13],
    ["E.A.P.C. Gatuku", 13],
    ["Episcopal St Peters Cathedral", 12],
    ["FGCK Kamuwongo", 6],
    ["FGCK Kasyalani", 6],
    ["FGCK Lwanda Church", 3],
    ["FGCK Masogo Church", 3],
    ["FGCK Meru Town LCA", 8],
    ["FGCK Oriang Church", 3],
    ["FGCK Rangombe Church", 3],
    ["G.N.C.A. Enzou", 7],
    ["G.N.C.A. Kyatune", 7],
    ["Good News Church of Africa-Yuumbu", 7],
    ["Gospel Revival Center Kimangao", 6],
    ["Ibinzo Church of God", 1],
    ["Ikerege PEFA Church", 9],
    ["Jesus Celebration Centre Mutomo", 7],
    ["Kaaga Methodist Church", 8],
    ["Kakamega Pentecostal Church P.A.G.", 1],
    ["Lutheran Church Kisumu", 12],
    ["MCK Kibuurine", 8],
    ["MCK Runogone Church", 8],
    ["Mahiakalo Church of God", 1],
    ["Methodist Church Kambereu", 8],
    ["Methodist Church in Kenya- Miomponi", 13],
    ["Methodist Church of Kenya-Thiiti", 13],
    ["Migori PEFA Church", 9],
    ["Ministry for the Restoration of Church of Christ- Kotur", 2],
    ["Ministry for the Restoration of Church of Christ- Simbachai", 2],
    ["Nkondi Gospel Furthering Bible Church", 13],
    ["Olepolos Menonite Church", 11],
    ["Ololulunga Full Gospel Church", 11],
    ["Oloshaiki Methodist Church", 4],
    ["Olturoto Pentecostal Evangelistic Fellowship of Africa", 4],
    ["P.C.E.A. Kibini", 4],
    ["PEFA Arroi", 4],
    ["PEFA Church Godkwer", 9],
    ["PEFA Church Gwitembe", 9],
    ["PEFA Community Church - Kegonga", 9],
    ["PEFA Kurutiyange Church", 9],
    ["PEFA Mashuuru", 4],
    ["PEFA Sultan Hamud", 4],
    ["POMC Najile Church", 11],
    ["St Paul's ACK Ambira", 12],
    ["St. Mark's ACK Mahaya", 12],
    ["St. Marks ACK Ngatu", 4],
];

$updated = 0;
foreach ($assignments as [$name, $cid]) {
    $stmt = $db->prepare("UPDATE schools SET cluster_id=:cid WHERE name=:name AND is_active=1");
    $stmt->bindValue(':cid',  $cid,  SQLITE3_INTEGER);
    $stmt->bindValue(':name', $name, SQLITE3_TEXT);
    $stmt->execute();
    if ($db->changes() > 0) $updated++;
}

// Verify
$clusterCount = (int)$db->querySingle("SELECT COUNT(*) FROM clusters");
$assignedCount = (int)$db->querySingle("SELECT COUNT(*) FROM schools WHERE cluster_id IS NOT NULL AND is_active=1");

echo "<h2>✅ Cluster sync complete</h2>";
echo "<p>Clusters in DB: <strong>$clusterCount</strong></p>";
echo "<p>Schools assigned to clusters: <strong>$assignedCount</strong></p>";
echo "<p style='color:red;font-weight:bold;margin-top:20px;'>⚠️ DELETE this file now: arise_sync_clusters.php</p>";
echo "<p><a href='/arise/locations.php'>→ Go to locations.php</a></p>";
