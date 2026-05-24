<?php
$db = new SQLite3('/home/cpmsfdav/public_html/arise/data/arise.db');
$db->exec('PRAGMA journal_mode=WAL;');

// Ensure clusters table exists
$db->exec("CREATE TABLE IF NOT EXISTS clusters (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL UNIQUE)");
// Ensure schools table has cluster_id column
try { $db->exec("ALTER TABLE schools ADD COLUMN cluster_id INTEGER REFERENCES clusters(id)"); } catch(Exception $e){}

// Insert clusters
$clusters = [
[1,'Kakamega'],[2,'Busia'],[3,'Homa Bay'],[4,'Kajiado'],[6,'Kitui'],
[7,'Kitui-Machakos'],[8,'Meru'],[9,'Migori-Narok'],[10,'Nakuru'],
[11,'Narok-Kajiado'],[12,'Siaya-Kisumu'],[13,'Tharaka Nithi-Embu'],[14,'Vihiga-Nandi-Kisumu']
];
foreach($clusters as $c){
    $db->exec("INSERT OR IGNORE INTO clusters (id,name) VALUES ({$c[0]},".SQLite3::escapeString($c[1]).")");
}
$cCount = $db->querySingle("SELECT COUNT(*) FROM clusters");

// Insert all schools
$schools = [
[1,'Arise School',null,1],[2,'Moi Girls High School Eldoret',null,1],[4,'Nairobi School',null,1],
[5,'Alliance Girls High School',null,1],[6,"St. Mary's School Nairobi",null,1],
[14,'Anglican Church of Kenya- St. Thomas Cathedral Nambale',2,1],
[15,"ACK St.Paul's Erupata",11,1],[16,'Ikerege PEFA Church',9,1],
[17,'Migori PEFA Church',9,1],[18,'Deliverance Church Kendubay',3,1],
[19,'FGCK Masogo Church',3,1],[20,'FGCK Oriang Church',3,1],
[21,'FGCK Lwanda Church',3,1],[22,'FGCK Rangombe Church',3,1],
[23,'FGCK Meru Town LCA',8,1],[24,'Episcopal St Peters Cathedral',12,1],
[25,'E.A.P.C. Gatuku',13,1],[26,'E.A.P.C Kiamauti church',13,1],
[27,'Gospel Revival Center Kimangao',6,1],[28,'Deliverance Church - Kisumu',12,1],
[29,'Kakamega Pentecostal Church P.A.G.',1,1],[30,'PEFA Mashuuru',4,1],
[31,'PEFA Arroi',4,1],[32,'PEFA Sultan Hamud',4,1],
[33,'Africa for Christ Evangelistic Association (AFCEA) Lubao',1,1],
[34,'Lutheran Church Kisumu',12,1],[35,'Mahiakalo Church of God',1,1],
[36,'Ibinzo Church of God',1,1],[37,'Anglican Church of Kenya- Namaindi',2,1],
[38,'Christian Teaching Ministries- Murumba',2,1],
[39,'Anglican Church of Kenya- Budokomi',2,1],
[40,'Ministry for the Restoration of Church of Christ- Simbachai',2,1],
[41,'Ministry for the Restoration of Church of Christ- Kotur',2,1],
[42,"Anglican Church of Kenya- St. Paul's Buduma",2,1],
[43,'Good News Church of Africa-Yuumbu',7,1],
[44,'PEFA Community Church - Kegonga',9,1],[45,'PEFA Church Godkwer',9,1],
[46,'PEFA Kurutiyange Church',9,1],[47,'PEFA Church Gwitembe',9,1],
[48,'Olturoto Pentecostal Evangelistic Fellowship of Africa',4,1],
[49,'Oloshaiki Methodist Church',4,1],[50,'Ololulunga Full Gospel Church',11,1],
[51,'Olepolos Menonite Church',11,1],
[52,'A.C.K. St. Peters Church Kwandonga',7,1],
[53,'St. Marks ACK Ngatu',4,1],[54,'Kaaga Methodist Church',8,1],
[55,'MCK Runogone Church',8,1],[56,'Methodist Church Kambereu',8,1],
[57,'MCK Kibuurine',8,1],[58,'Methodist Church in Kenya- Miomponi',13,1],
[59,'Methodist Church of Kenya-Thiiti',13,1],[60,'AIC Karou',13,1],
[61,'Nkondi Gospel Furthering Bible Church',13,1],[62,'A.I.C. Kiini',7,1],
[63,'G.N.C.A. Enzou',7,1],[64,'Jesus Celebration Centre Mutomo',7,1],
[65,'G.N.C.A. Kyatune',7,1],[66,'AIC Nthangathini',7,1],
[67,'AIC Kanzinwa',6,1],[68,'FGCK Kamuwongo',6,1],[69,'FGCK Kasyalani',6,1],
[70,'AIC Nzewani',6,1],[71,'AIC Mbangwani',6,1],
[72,'Africa Inland Church Nguuku',6,1],[73,'ACK Maseno Parish',14,1],
[74,"St. Mark's ACK Mahaya",12,1],
[75,"A.C.K. St. Luke's Church Manyatta",12,1],
[76,"ACK St. Paul's Yala Church",12,1],
[77,"ACK St. Peter's Maraba",1,1],
[78,"St Paul's ACK Ambira",12,1],
[79,'Baptist Church Olorropil',11,1],[80,'POMC Najile Church',11,1],
[81,'P.C.E.A. Kibini',4,1],[82,'Agape Sanctuary Ministries Nakuru',10,1],
[83,'ACK Samuel Achola Memorial - Bande',9,1],
];

foreach($schools as $s){
    $cid = $s[2] === null ? 'NULL' : intval($s[2]);
    $name = SQLite3::escapeString($s[1]);
    $db->exec("INSERT OR IGNORE INTO schools (id,name,cluster_id,is_active) VALUES ({$s[0]},'$name',$cid,{$s[3]})");
}
$sCount = $db->querySingle("SELECT COUNT(*) FROM schools WHERE is_active=1");

$db->close();
echo "<pre>Clusters inserted: $cCount\nActive schools inserted: $sCount\nALL DONE ✓</pre>";
unlink(__FILE__);
