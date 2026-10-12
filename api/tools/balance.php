<?php

declare(strict_types=1);

/**
 * Balance check for horses races (Stage 8.10) — CLI only:
 *
 *   docker compose exec web php api/tools/balance.php [races]
 *
 * For every course × laps (1 and 3 on a loop): `races` seeded races
 * (default 220) of eight horses through the real engine, the field rotating
 * so each horse starts as often as any other and in every grid slot. Prints
 * each horse's win share against a fair share (1.00 = wins one race in
 * eight), its mean finish time, and wins by grid row. Target: no horse above
 * 2× on any course, and grid rows level within noise.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../lib/RaceEngine.php';
require __DIR__ . '/../lib/Tracks.php';
require __DIR__ . '/../lib/Maps.php';

$races = max(11, (int) ($argv[1] ?? 220));
$slugs = array_keys(RaceEngine::HORSES);
$stable = count($slugs);

foreach (Tracks::all() as $listed) {
    if ($listed['slug'] === 'map:*') {
        continue; // each map is run on its own
    }
    foreach ($listed['loop'] ? [1, 3] : [1] as $laps) {
        $wins = array_fill_keys($slugs, 0);
        $starts = array_fill_keys($slugs, 0);
        $seconds = array_fill_keys($slugs, 0.0);
        $rowWins = [0, 0, 0, 0];
        for ($k = 0; $k < $races; $k++) {
            $field = [];
            for ($j = 0; $j < 8; $j++) {
                $field[] = $slugs[($k + $j) % $stable];
            }
            // a map course rides a fresh loop every race, as in play
            $seed = RaceEngine::prngNew();
            $race = RaceEngine::create($seed, 8, Tracks::raceOptions($listed['slug'], $laps, $field, $seed));
            $finishTick = [];
            while ($race['finished'] < $race['n']) {
                RaceEngine::step($race);
                foreach ($race['racers'] as $i => $rc) {
                    if ($rc['done'] && !isset($finishTick[$i])) {
                        $finishTick[$i] = $race['tick'];
                    }
                }
            }
            foreach ($race['racers'] as $i => $rc) {
                $starts[$rc['horse']]++;
                $seconds[$rc['horse']] += $finishTick[$i] / 60;
                if ($rc['place'] === 1) {
                    $wins[$rc['horse']]++;
                    $rowWins[intdiv($rc['lane'], 2)]++;
                }
            }
        }
        printf("\n%s × %d lap%s — %d races\n", $listed['name'], $laps, $laps > 1 ? 's' : '', $races);
        printf("  %-18s %9s %9s\n", 'horse', 'win/fair', 'mean s');
        foreach ($slugs as $slug) {
            $share = $starts[$slug] > 0 ? $wins[$slug] / $starts[$slug] * 8 : 0.0;
            printf(
                "  %-18s %9.2f %9.2f%s\n",
                RaceEngine::HORSES[$slug]['name'],
                $share,
                $starts[$slug] > 0 ? $seconds[$slug] / $starts[$slug] : 0.0,
                $share > 2 ? '   ← above 2×' : ''
            );
        }
        printf("  wins by grid row, front to back: %s\n", implode(' / ', $rowWins));
    }
}
