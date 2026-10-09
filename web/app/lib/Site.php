<?php
/* The public pages. Everything they show was computed by the compiler; rendering only formats it. */
declare(strict_types=1);

namespace PitWall;

final class Site
{
    public const SECTIONS = [
        '' => 'Overview',
        'tracks' => 'Tracks',
        'laps' => 'Lap times',
        'incidents' => 'Incidents',
        'cars' => 'Cars',
        'drivers' => 'Head to head',
        'awards' => 'Awards',
    ];

    public static function handle(string $path): void
    {
        $index = Data::index();
        $seasons = $index['seasons'] ?? [];

        if ($path === '/') {
            if (!$seasons) View::send(View::render('empty', ['pageTitle' => Config::get('site_name'), 'index' => $index]));
            $want = (string) Http::query('season');  // the season picker without JavaScript
            $pick = array_values(array_filter($seasons, fn($s) => $s['slug'] === $want))[0] ?? $seasons[0];
            Http::redirect('/' . $pick['slug'], 302);
        }

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

        Http::etag(Data::version() . '|' . ($_SERVER['REQUEST_URI'] ?? ''));
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
