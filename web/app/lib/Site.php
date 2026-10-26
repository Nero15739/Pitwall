<?php
/* The public pages. Everything they show was computed by the compiler; rendering only formats it. */
declare(strict_types=1);

namespace PitWall;

final class Site
{
    public const SECTIONS = [
        '' => 'Overview',
        'calendar' => 'Calendar',
        'tracks' => 'Tracks',
        'laps' => 'Lap times',
        'incidents' => 'Incidents',
        'cars' => 'Cars',
        'drivers' => 'Head to head',
        'awards' => 'Awards',
    ];

    /**
     * The season's calendar, split for the pages: every round, the ones still to come in date order,
     * and the next one up (the first that isn't already behind us).
     */
    public static function calendar(array $S): array
    {
        $all = $S['calendar'] ?? [];
        $ahead = array_values(array_filter($all, fn($c) => $c['status'] === 'upcoming'));
        $next = null;
        foreach ($ahead as $c) if ($next === null && F::daysUntil($c['date']) >= 0) $next = $c;
        return ['all' => $all, 'ahead' => $ahead, 'next' => $next, 'done' => count($all) - count($ahead), 'total' => count($all)];
    }

    /**
     * The site's code is newer than the published data: recompile the stored races once, on the
     * first page view after an update, so new stats appear without anyone pressing "Rebuild".
     * If that fails the pages keep the data they had, and it waits five minutes before retrying.
     */
    private static function republish(): ?array
    {
        $mark = Paths::cache() . '/.republish-failed';
        if (is_file($mark) && time() - filemtime($mark) < 300) return null;
        try {
            Compiler::run(Store::open());
            Data::forget();
            @unlink($mark);
            return Data::index();
        } catch (\Throwable $e) {
            App::log($e);
            @touch($mark);
            return null;
        }
    }

    /** /live: the sessions the Pit Wall Logger uploaded; /live/<id>: one of them. */
    private static function live(string $id, ?array $index): never
    {
        $vars = ['index' => $index, 'live' => true];
        $slots = Live::slots();
        UI::useDrivers(array_map(fn($name, $color) => ['name' => $name, 'color' => $color], array_keys($slots), $slots));
        if ($id === '') View::send(View::render('live/index', $vars + ['sessions' => Live::all(), 'pageTitle' => 'Live sessions']));
        $d = Live::load($id);
        if (!$d) App::error(404, 'Session not found', 'There’s no live log at this address.');
        $d = Cutoffs::liveFrom(Store::open(), $d);
        View::send(View::render('live/session', $vars + [
            'd' => $d, 'id' => $id, 'P' => Live::page($d), 'map' => Live::map((int) ($d['track']['id'] ?? 0)), 'slots' => $slots,
            'pageTitle' => ($d['track']['short'] ?? '') ?: ($d['track']['name'] ?? 'Live session'),
        ]));
    }

    public static function handle(string $path): void
    {
        $index = Data::index();
        if ($index && ($index['build'] ?? null) !== PITWALL_VERSION) $index = self::republish() ?? $index;
        $seasons = $index['seasons'] ?? [];

        if ($path === '/') {
            if (!$seasons) View::send(View::render('empty', ['pageTitle' => Config::get('site_name'), 'index' => $index]));
            $want = (string) Http::query('season');  // the season picker without JavaScript
            $pick = array_values(array_filter($seasons, fn($s) => $s['slug'] === $want))[0] ?? $seasons[0];
            Http::redirect('/' . $pick['slug'], 302);
        }

        if ($path === '/live' || str_starts_with($path, '/live/')) self::live(substr($path, 6), $index);

        $parts = explode('/', trim($path, '/'));
        [$slug, $section, $id] = array_pad($parts, 3, '');
        if (count($parts) > 3 || !array_key_exists($section, self::SECTIONS) || ($id !== '' && $section !== 'tracks')) {
            App::error(404, 'Page not found', 'There’s nothing at this address.');
        }
        $season = Data::season($slug);
        if (!$season) App::error(404, 'Season not found', "There’s no season called “{$slug}”.");
        if ($id !== '' && !in_array($id, array_map(fn($p) => (string) $p['track_id'], $season['track_pages']), true)) {
            App::error(404, 'Track not found', 'This season didn’t race at that track.');
        }

        // with rounds still to come, "next up" and the countdowns change with the day
        $ahead = array_filter($season['calendar'] ?? [], fn($c) => $c['status'] === 'upcoming');
        Http::etag(Data::version() . '|' . ($ahead ? gmdate('Y-m-d') : '') . '|' . ($_SERVER['REQUEST_URI'] ?? ''));
        UI::useDrivers($season['drivers']);
        $vars = [
            'S' => $season,
            'index' => $index,
            'tracks' => Data::tracks(),
            'section' => $section,
            'trackId' => $id,
            'pageTitle' => ($section === '' ? $season['season'] : self::SECTIONS[$section] . ' · ' . $season['season']),
        ];
        View::send(View::render('season/' . ($section ?: 'overview'), $vars));
    }
}
