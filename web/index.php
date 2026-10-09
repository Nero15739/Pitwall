<?php
/* Pit Wall: every request that isn't a real file comes through here (see .htaccess). */
declare(strict_types=1);

define('PITWALL_ROOT', __DIR__);

// PHP's built-in dev server (php -S): serve real asset files directly, never the private folders
if (PHP_SAPI === 'cli-server') {
    $p = rawurldecode(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
    if (preg_match('#^/(app|storage|bin)(/|$)|^/config|/\.#i', $p)) {
        http_response_code(403);
        exit('Forbidden');
    }
    if ($p !== '/' && is_file(__DIR__ . $p)) return false;
}

require __DIR__ . '/app/bootstrap.php';
PitWall\App::run();
