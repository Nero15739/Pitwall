<?php
/* Front controller: /api → JSON API, /admin → admin panel, everything else → the public site. */
declare(strict_types=1);

namespace PitWall;

final class App
{
    public static function run(): void
    {
        $path = Http::path();
        try {
            if ($path === '/api' || str_starts_with($path, '/api/')) {
                Api::handle(substr($path, 4) ?: '/', Http::method());
                return;
            }
            Http::securityHeaders();
            if ($path === '/admin' || str_starts_with($path, '/admin/')) {
                Admin::handle(substr($path, 6) ?: '/', Http::method());
                return;
            }
            if (!in_array(Http::method(), ['GET', 'HEAD'], true)) {
                header('Allow: GET, HEAD');
                self::error(405, 'Method not allowed', 'This page can only be read.');
            }
            Site::handle($path);
        } catch (UserError $e) {
            self::error($e->status, 'That didn’t work', $e->getMessage());
        } catch (\Throwable $e) {
            self::log($e);
            if (str_starts_with($path, '/api')) {
                Http::json(['error' => ['code' => 'server_error', 'message' => Config::get('debug') ? $e->getMessage() : 'Something went wrong on the server.']], 500);
            }
            self::error(500, 'Something broke', Config::get('debug')
                ? get_class($e) . ': ' . $e->getMessage() . ' in ' . basename($e->getFile()) . ':' . $e->getLine()
                : 'The error has been logged. Try again in a moment.');
        }
    }

    public static function error(int $status, string $title, string $message): never
    {
        $html = View::render('error', ['status' => $status, 'title' => $title, 'message' => $message, 'pageTitle' => $title]);
        View::send($html, $status);
    }

    public static function log(\Throwable $e): void
    {
        try {
            $dir = Paths::storage('logs');
            if (!is_dir($dir)) mkdir($dir, 0775, true);
            $line = sprintf("[%s] %s %s: %s in %s:%d\n%s\n\n", gmdate('c'), Http::method() . ' ' . ($_SERVER['REQUEST_URI'] ?? ''),
                get_class($e), $e->getMessage(), $e->getFile(), $e->getLine(), $e->getTraceAsString());
            file_put_contents($dir . '/error-' . gmdate('Y-m') . '.log', $line, FILE_APPEND | LOCK_EX);
        } catch (\Throwable) {
            error_log((string) $e);
        }
    }
}
