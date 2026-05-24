<?php
$db = new SQLite3('/home/cpmsfdav/public_html/arise/data/arise.db');
$db->enableExceptions(true);
try {
    $r = $db->query("SELECT id, name FROM clusters ORDER BY name");
    $clusters = []; while($c=$r->fetchArray(SQLITE3_ASSOC)) $clusters[]=$c;
    echo "clusters: ".count($clusters)."\n";

    $r2 = $db->query("SELECT s.id, s.name, s.cluster_id, COALESCE(c.name,'') AS cluster_name FROM schools s LEFT JOIN clusters c ON c.id=s.cluster_id WHERE s.is_active=1 ORDER BY s.name");
    $schools = []; while($s=$r2->fetchArray(SQLITE3_ASSOC)) $schools[]=$s;
    echo "schools: ".count($schools)."\n";
    echo "hasClusters: ".(count($clusters)>0?'TRUE':'FALSE')."\n";
    echo "hasSchools: ".(count($schools)>0?'TRUE':'FALSE')."\n";
    echo "condition (hasClusters && hasSchools): ".((count($clusters)>0 && count($schools)>0)?'TRUE → shows dropdown':'FALSE → shows text input')."\n";
} catch(Exception $e) {
    echo "ERROR: ".$e->getMessage()."\n";
}
$db->close();
unlink(__FILE__);
