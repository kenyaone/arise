<?php
$src  = '/home/cpmsfdav/public_html/data/arise.db';
$dest = '/home/cpmsfdav/public_html/data/backups/arise_live_' . date('Ymd_His') . '.db';
if (!is_dir(dirname($dest))) mkdir(dirname($dest), 0755, true);
copy($src, $dest);
$size = round(filesize($dest)/1024) . ' KB';
echo "<pre>Live DB backed up → $dest ($size)\n";
// Keep only last 5 backups
$files = glob(dirname($dest).'/arise_live_*.db');
if (count($files) > 5) {
    sort($files);
    foreach (array_slice($files, 0, count($files)-5) as $old) {
        unlink($old);
        echo "Removed old backup: ".basename($old)."\n";
    }
}
echo "Done ✓\n</pre>";
unlink(__FILE__);
