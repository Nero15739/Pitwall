<?php
/* Shared page components. Each returns HTML; every value passing through is escaped.
   Driver identity is a colour slot plus a marker shape, never colour alone. */
declare(strict_types=1);

namespace PitWall;

final class UI
{
    private static array $slots = [];
    private static ?array $icons = null;
    public const SHAPES = ['circle', 'square', 'triangle', 'diamond', 'star', 'x', 'rounded', 'plus'];

    /** Remember the season's drivers so tags pick up their colour and shape. */
    public static function useDrivers(array $drivers): void
    {
        self::$slots = array_column($drivers, 'color', 'name');
    }

    public static function slot(string $name): ?int
    {
        return self::$slots[$name] ?? null;
    }

    public static function color(?int $slot): string
    {
        return $slot === null ? 'var(--ai)' : 'var(--s' . ($slot % 8) . ')';
    }

    /** SVG path for a shape centred on (0,0) with radius r. Strokes for x/plus, fills for the rest. */
    public static function shapePath(string $shape, float $r): array
    {
        $k = $r * 0.86;
        $f = fn(float $v) => rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.');
        switch ($shape) {
            case 'square': return ['d' => "M{$f(-$k)},{$f(-$k)}h{$f(2 * $k)}v{$f(2 * $k)}h{$f(-2 * $k)}Z", 'stroke' => false];
            case 'rounded':
                $c = $k * 0.45; $s = 2 * ($k - $c);
                return ['d' => "M{$f(-$k + $c)},{$f(-$k)}h{$f($s)}q{$f($c)},0 {$f($c)},{$f($c)}v{$f($s)}q0,{$f($c)} {$f(-$c)},{$f($c)}h{$f(-$s)}q{$f(-$c)},0 {$f(-$c)},{$f(-$c)}v{$f(-$s)}q0,{$f(-$c)} {$f($c)},{$f(-$c)}Z", 'stroke' => false];
            case 'triangle': return ['d' => "M0,{$f(-$r * 1.1)}L{$f($r * 1.05)},{$f($r * 0.75)}H{$f(-$r * 1.05)}Z", 'stroke' => false];
            case 'diamond': return ['d' => "M0,{$f(-$r * 1.15)}L{$f($r * 1.15)},0L0,{$f($r * 1.15)}L{$f(-$r * 1.15)},0Z", 'stroke' => false];
            case 'star':
                $pts = [];
                for ($i = 0; $i < 10; $i++) {
                    $a = -M_PI / 2 + $i * M_PI / 5;
                    $rr = $i % 2 ? $r * 0.5 : $r * 1.15;
                    $pts[] = $f(cos($a) * $rr) . ',' . $f(sin($a) * $rr);
                }
                return ['d' => 'M' . implode('L', $pts) . 'Z', 'stroke' => false];
            case 'x': return ['d' => "M{$f(-$k)},{$f(-$k)}L{$f($k)},{$f($k)}M{$f($k)},{$f(-$k)}L{$f(-$k)},{$f($k)}", 'stroke' => true];
            case 'plus': return ['d' => "M0,{$f(-$r)}V{$f($r)}M{$f(-$r)},0H{$f($r)}", 'stroke' => true];
            default: return ['d' => "M{$f(-$r)},0a{$f($r)},{$f($r)} 0 1,0 {$f(2 * $r)},0a{$f($r)},{$f($r)} 0 1,0 {$f(-2 * $r)},0", 'stroke' => false];
        }
    }

    /** A driver's marker: colour slot and shape. null = AI / unknown (grey dot). */
    public static function marker(?int $slot, int $size = 12): string
    {
        $c = self::color($slot);
        $s = self::shapePath(self::SHAPES[($slot ?? 0) % 8], 4.4);
        $inner = $s['stroke']
            ? "<path d=\"{$s['d']}\" stroke=\"var(--ink)\" stroke-width=\"4.2\" stroke-linecap=\"square\" fill=\"none\"/><path d=\"{$s['d']}\" stroke=\"{$c}\" stroke-width=\"2.2\" stroke-linecap=\"square\" fill=\"none\"/>"
            : "<path d=\"{$s['d']}\" fill=\"{$c}\" stroke=\"var(--ink)\" stroke-width=\"1.3\" stroke-linejoin=\"miter\"/>";
        return "<svg class=\"mk\" width=\"{$size}\" height=\"{$size}\" viewBox=\"-6.5 -6.5 13 13\" aria-hidden=\"true\">{$inner}</svg>";
    }

    public static function driver(string $name, bool $ai = false, ?string $label = null, bool $short = false): string
    {
        $slot = $ai ? null : self::slot($name);
        $text = $label ?? ($short ? F::firstName($name) : $name);
        return '<span class="dtag">' . self::marker($slot) . '<span class="dtag-n">' . e($text) . '</span>'
            . ($ai ? '<span class="ai-badge">AI</span>' : '') . '</span>';
    }

    /** data-tip attribute for the shared tooltip: ['title' => .., 'lines' => [['value' => .., 'label' => ..]]] */
    public static function tip(?array $c): string
    {
        return $c ? ' data-tip="' . e(json_encode($c, JSON_UNESCAPED_UNICODE)) . '" tabindex="0"' : '';
    }

    /** A table cell shaded on the 7-step ramp, with the highest-contrast ink. */
    public static function heat(float|int|null $v, float|int $min, float|int $max, string $text, ?array $tip = null): string
    {
        $step = $v === null || !is_finite((float) $v) ? 0 : 1 + (int) round(($max > $min ? ($v - $min) / ($max - $min) : 0) * 6);
        $cls = $step ? "heat h{$step}" : 'heat h0';
        return "<td class=\"{$cls}\"" . self::tip($tip) . '>' . e($text) . '</td>';
    }

    /**
     * Horizontal bars, each value labelled so the bars double as their own table.
     * $bars: [['label' => .., 'value' => .., 'text' => .., 'driver' => bool, 'ai' => bool, 'slot' => ?int|false, 'tip' => ?array]]
     */
    public static function bars(array $bars, string $label, float|int|null $max = null, string $color = 'var(--accent)'): string
    {
        $top = $max ?? max(0, ...array_map(fn($b) => $b['value'], $bars ?: [['value' => 0]]));
        $h = '<ul class="bars" aria-label="' . e($label) . '">';
        foreach ($bars as $b) {
            $frac = $top > 0 ? max(0, $b['value']) / $top : 0;
            $fill = !empty($b['ai']) ? 'var(--ai)' : (array_key_exists('slot', $b) ? self::color($b['slot']) : $color);
            $name = !empty($b['driver']) ? self::driver($b['label'], !empty($b['ai'])) : '<span class="bars-l">' . e($b['label']) . '</span>';
            $h .= '<li' . self::tip($b['tip'] ?? null) . '><span class="bars-n">' . $name . '</span><span class="bars-t">'
                . '<span class="bars-b" style="--w:' . Num::fixed($frac, 4) . ';--c:' . $fill . '"></span>'
                . '<span class="bars-v">' . e($b['text']) . '</span></span></li>';
        }
        return $h . '</ul>';
    }

    public static function tile(string $label, string $valueHtml, string $unit = '', string $meta = '', string $class = ''): string
    {
        return '<div class="tile ' . e($class) . '"><span class="tile-l">' . e($label) . '</span>'
            . '<span class="tile-v">' . $valueHtml . ($unit !== '' ? '<span class="tile-u">' . e($unit) . '</span>' : '') . '</span>'
            . ($meta !== '' ? '<span class="tile-m">' . e($meta) . '</span>' : '') . '</div>';
    }

    /** Segmented control. Panes marked data-pane="group" data-value=".." are switched by pitwall.js. */
    public static function seg(string $group, array $options, string $active, string $label): string
    {
        $h = '<div class="seg" role="group" aria-label="' . e($label) . '" data-seg="' . e($group) . '">';
        foreach ($options as $value => $text) {
            $on = (string) $value === $active ? 'true' : 'false';
            $h .= '<button type="button" data-value="' . e($value) . '" aria-pressed="' . $on . '">' . e($text) . '</button>';
        }
        return $h . '</div>';
    }

    public static function pane(string $group, string|int $value, string $active): string
    {
        return ' data-pane="' . e($group) . '" data-value="' . e($value) . '"' . ((string) $value === $active ? '' : ' hidden');
    }

    public static function panel(string $title, string $sub = '', string $actions = '', string $class = '', string $id = ''): string
    {
        return '<section class="panel ' . e($class) . '"' . ($id ? ' id="' . e($id) . '"' : '') . '><header class="panel-h"><div class="panel-t"><h2>' . e($title) . '</h2>'
            . ($sub !== '' ? '<p>' . e($sub) . '</p>' : '') . '</div>'
            . ($actions !== '' ? '<div class="panel-a">' . $actions . '</div>' : '') . '</header><div class="panel-b">';
    }

    public static function end(): string
    {
        return '</div></section>';
    }

    /** A small silhouette of the circuit. */
    public static function outline(?array $map, string $class = ''): string
    {
        if (!$map) return '<span class="outline-none ' . e($class) . '"></span>';
        return '<svg class="outline ' . e($class) . '" viewBox="' . e($map['viewBox']) . '" preserveAspectRatio="xMidYMid meet" aria-hidden="true">'
            . '<path d="' . e($map['path']) . '" fill="none" stroke="currentColor" stroke-width="3.5" vector-effect="non-scaling-stroke" stroke-linejoin="miter"/></svg>';
    }

    /**
     * Line chart, drawn by pitwall.js, with a table view underneath.
     * $c: labels, titles?, series [{name, slot, values}], yTitle?, fmt (int|d1|d2|pace), sortDesc?, zero?, height?, aria
     */
    public static function chart(array $c): string
    {
        $c += ['titles' => $c['labels'], 'yTitle' => '', 'fmt' => 'int', 'sortDesc' => true, 'zero' => true, 'height' => 280];
        $fmt = fn($v) => $v === null ? F::DASH : self::fmtValue((float) $v, $c['fmt']);
        $t = '<details class="chart-table"><summary>View as table</summary><div class="scroll"><table class="tbl"><thead><tr><th>Driver</th>';
        foreach ($c['labels'] as $l) $t .= '<th class="num">' . e($l) . '</th>';
        $t .= '</tr></thead><tbody>';
        foreach ($c['series'] as $s) {
            $t .= '<tr><td>' . e($s['name']) . '</td>';
            foreach ($s['values'] as $v) $t .= '<td class="num">' . e($fmt($v)) . '</td>';
            $t .= '</tr>';
        }
        $t .= '</tbody></table></div></details>';
        return '<div class="chart" data-chart="' . e(json_encode($c, JSON_UNESCAPED_UNICODE)) . '" style="min-height:' . ((int) $c['height'] + 40) . 'px">'
            . '<noscript><p class="muted">Charts need JavaScript; the table below has the same numbers.</p></noscript></div>' . $t;
    }

    public static function fmtValue(float $v, string $fmt): string
    {
        return match ($fmt) {
            'd1' => Num::fixed($v, 1),
            'd2' => Num::fixed($v, 2),
            'pace' => Num::fixed($v, $v < 10 ? 2 : 1) . '%',
            default => (string) Num::jsRound($v),
        };
    }

    public static function icon(string $name, int $size = 20, string $class = ''): string
    {
        self::$icons ??= require Paths::app('icons.php');
        $inner = self::$icons[$name] ?? self::$icons['medal'];
        return '<svg class="ic ' . e($class) . '" width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.25" stroke-linecap="square" stroke-linejoin="miter" aria-hidden="true">' . $inner . '</svg>';
    }

    /** JSON safe to embed in <script type="application/json">. */
    public static function json(mixed $v): string
    {
        return json_encode($v, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES);
    }
}
