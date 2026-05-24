<?php
$source = '/home/cpmsfdav/public_html/arise/data/arise.db';
echo "<pre>\n";
echo "File exists: ".(file_exists($source)?'YES':'NO')."\n";
echo "File size: ".filesize($source)." bytes\n";
$db = new SQLite3($source);
echo "Clusters: ".$db->querySingle("SELECT COUNT(*) FROM clusters")."\n";
echo "Lessons active: ".$db->querySingle("SELECT COUNT(*) FROM lessons WHERE is_active=1")."\n";
echo "Quiz questions: ".$db->querySingle("SELECT COUNT(*) FROM quiz_questions WHERE is_published=1")."\n";
echo "Schools active: ".$db->querySingle("SELECT COUNT(*) FROM schools WHERE is_active=1")."\n";
echo "Modules: ".$db->querySingle("SELECT COUNT(*) FROM modules")."\n";
$db->close();
echo "</pre>";
unlink(__FILE__);
