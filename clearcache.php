<?php
if (function_exists('opcache_reset')) {
    opcache_reset();
    echo "OPcache cleared ✓";
} else {
    echo "OPcache not available";
}
// Also invalidate specific file
if (function_exists('opcache_invalidate')) {
    $f = '/home/cpmsfdav/public_html/arise/public/pages/register.php';
    opcache_invalidate($f, true);
    echo "\nInvalidated register.php ✓";
}
unlink(__FILE__);
