<?php
/* Plain PHP templates in app/views. A page renders into its layout. */
declare(strict_types=1);

namespace PitWall;

final class View
{
    private static array $slots = [];

    /** Let a page add markup to its layout (e.g. a page-only script in <head>). */
    public static function push(string $slot, string $html): void
    {
        self::$slots[$slot] = (self::$slots[$slot] ?? '') . $html;
    }

    public static function slot(string $slot): string
    {
        return self::$slots[$slot] ?? '';
    }

    public static function render(string $template, array $vars = [], ?string $layout = 'layout'): string
    {
        $content = self::partial($template, $vars);
        return $layout ? self::partial($layout, $vars + ['content' => $content]) : $content;
    }

    public static function partial(string $template, array $vars = []): string
    {
        $file = Paths::app("views/{$template}.php");
        if (!is_file($file)) throw new \RuntimeException("missing view {$template}");
        extract($vars, EXTR_SKIP);
        ob_start();
        try {
            include $file;
            return (string) ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }
    }

    public static function send(string $html, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: text/html; charset=utf-8');
        echo $html;
        exit;
    }
}
