<?php
/* Loaded first by index.php and the CLI scripts. */
declare(strict_types=1);

if (PHP_VERSION_ID < 80100) {
    http_response_code(500);
    exit('Pit Wall needs PHP 8.1 or newer. In Hostinger: hPanel → Advanced → PHP Configuration.');
}

defined('PITWALL_ROOT') || define('PITWALL_ROOT', dirname(__DIR__));
const PITWALL_VERSION = '3.0.0';

date_default_timezone_set('UTC');
mb_internal_encoding('UTF-8');

spl_autoload_register(function (string $class): void {
    if (!str_starts_with($class, 'PitWall\\')) return;
    $file = __DIR__ . '/lib/' . str_replace('\\', '/', substr($class, 8)) . '.php';
    if (is_file($file)) require $file;
});

// a warning is a bug: fail loudly instead of publishing half-right numbers
set_error_handler(function (int $no, string $msg, string $file, int $line): bool {
    if (!(error_reporting() & $no)) return false;
    throw new ErrorException($msg, 0, $no, $file, $line);
});

/** Escape for HTML. */
function e(mixed $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
