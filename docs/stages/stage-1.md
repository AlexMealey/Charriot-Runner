# Stage 1 — Backend race engine + practice slice

Status: steps 1.1–1.9 complete — acceptance pending a runtime pass
Goal: the race simulation lives in PHP; practice races run on polled backend state.

Progress (see steps below; checked = done and reviewed):

- [x] 1.1 PHP PRNG (port of `mulberry32`)
- [x] 1.2 `RaceEngine::create`
- [x] 1.3 `RaceEngine::step`
- [x] 1.4 `RaceEngine::advance` + `RaceEngine::names`
- [x] 1.5 `RoomStore` (practice only for now)
- [x] 1.6 API actions
- [x] 1.7 Frontend: practice polls the backend
- [x] 1.8 N racers everywhere
- [x] 1.9 Practice count picker

Files in play (touch nothing else):

- `api/lib/RaceEngine.php` — new
- `api/lib/RoomStore.php` — new
- `api/index.php`
- `public/index.html`
- `public/css/main.css` — only if the picker needs a small style

> **Working rules — read first, keep to them**
>
> - Do the steps below **in order, one at a time**. Stop a step when its
>   "Done when" line is true. Do not gold-plate.
> - Touch **only the files listed above**. No drive-by refactors, no new
>   libraries or services.
> - Stack is fixed: plain JS + JSX (React UMD + Babel standalone), PHP 8.3,
>   HTML/CSS. **Never Node.js / npm / npx.**
> - Anything not specified: pick the **simplest** option, note it in one line
>   in your reply, and keep moving.
> - UI copy keeps the medieval voice; code style matches the file you edit.
> - Behaviour reference: `docs/spec-rooms.md` §3–§4.
> - **No host `php`, no `docker`** (see `docs/failed-commands.md`): never
>   attempt to run, lint, or test the PHP here — verify by reading.

## Steps

### 1.1 PHP PRNG (port of `mulberry32`)

In `api/lib/RaceEngine.php`: `prngNew(): int` (fresh 32-bit state from
`random_int`) and `prngNext(int &$state): float`. Exact PHP port of the JS
`mulberry32` in `public/index.html`; the state must serialize as one int.
Done when: a fixed state reproduces one sequence; all values in `[0, 1)`.

### 1.2 `RaceEngine::create`

`public static function create(int $prngState, int $n): array` — port of JS
`createRace`. Returns a json-encodable state: `tick`, `t`, `prng`, `n`,
`finished`, `placements`, and `racers[]` each `{lane, rank, p: -0.012, done:
false, place: 0, surgeAmp, surgeW, surgePhase}`. A seeded shuffle of
`0..n-1` sets `rank` (0 = seeded winner). Keep `STEP = 1/60` and
`BASE_SPEED = 0.12`.
Done when: state round-trips through `json_encode` losslessly and reshuffles
per seed.

### 1.3 `RaceEngine::step`

`public static function step(array &$state): void` — exact port of JS
`stepRace`: surge, PRNG jitter, rubber-band, endgame `smoothstep`, and the
"hold the line" finish-order rule. Increments `tick`; `t += STEP`.
Done when: one race finishes all racers and fills `placements` 1..n.

### 1.4 `RaceEngine::advance` + `RaceEngine::names`

`public static function advance(array &$state, int $targetTick): void` — call
`step` until `state.tick == targetTick`, hard cap 900 steps per call.
`public static function names(int $n): array` — n word-names ("Swift Badger"),
copying the two word lists (`NAME_A`, `NAME_B`) already in
`public/index.html`.
Done when: advancing to tick N in one call equals N single steps.

### 1.5 `RoomStore` (practice only for now)

`api/lib/RoomStore.php`: `put(string $kind, string $id, array $doc)`,
`get(string $kind, string $id): ?array`, `appendTicks(string $kind, string
$id, int $raceNo, array $lines)`. Files under `data/<kind>/<id>.json` and
`data/<kind>/<id>/race-<n>.ticks.jsonl.gz` (`gzopen(..., "a9")`); create
directories as needed; writes use `flock` + temp-file-then-rename. `$kind` is
`practice` now, `rooms` arrives in Stage 3.
Done when: back-to-back writes never leave a partial or empty file.

### 1.6 API actions

Add two cases to the switch in `api/index.php` (leave existing cases alone):

- `create_practice` (POST `{racers}`, clamp 2..8): fresh PRNG → `create()`,
  `names()`, `id = bin2hex(random_bytes(8))`, `startedAt = microtime(true)`,
  `RoomStore::put`, reply `{practiceId, snapshot}`.
- `practice_state` (GET `race`, optional `tick`): load (404
  `practice_not_found` if missing), `targetTick = floor((microtime(true) -
  startedAt) * 60)`, `advance()`, append new tick lines via `appendTicks`,
  save, reply the snapshot `{tick, t, n, finished, placements, racers,
  names, startedAt}`.

Done when: both actions return sensible JSON via `curl` or the browser.

### 1.7 Frontend: practice polls the backend

In `public/index.html`, `RaceView` + `startRace`: drop the local
`createRace`/`stepRace` loop. On start: POST `create_practice` with the
chosen count (step 1.9), keep `{practiceId, snapshot}`. Then poll
`practice_state` every 250 ms and **interpolate** `racers[].p` between the
last two snapshots inside the existing rAF loop. `draw()` keeps consuming
`{racers, placements}` as today.
Done when: a practice race runs with only poll traffic driving positions.

### 1.8 N racers everywhere

Generalize the fixed four: `RACERS` → 8 entries (cycle the 4 shapes; extend
colors/names), `buildTrack` lane loop → `n`, `ORDINAL` → 8 entries,
`draw`/`IDLE` take `n` from state (default 4).
Done when: counts 2 through 8 all render, race, finish and rank correctly.

### 1.9 Practice count picker

Landing `Practice` button (Stage 2 code) opens a small form panel: 2–8 slider
and a **Ride!** button → POST `create_practice` → navigate
`/?practice=<practiceId>`. Keep `practiceId` in `localStorage` so a reload
keeps polling the same race.
Done when: practice works end-to-end from the landing page at any count 2–8.

## Acceptance

- [ ] Practice races run entirely on backend state (only `practice_state`
      polls drive positions).
- [ ] Deterministic: the same stored race advances identically every time.
- [ ] 2–8 racers render, race, finish, and place correctly.
- [ ] No Node/npm/npx introduced anywhere.

## Not in this stage

Rooms, lobby, join, predictions, results screen, export, TTL — later stages.
