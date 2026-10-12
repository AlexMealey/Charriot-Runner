# Stage 8 — Horses mode (two-lane courses + horses)

Status: steps 8.1–8.10 written and reviewed by reading (2026-10-12) —
acceptance pending a runtime pass; spec decided in [Stage 6 §6.4](stage-6.md)
Goal: a new room mode where riders pick horses with different stats and
race them on server-side SVG courses with two lanes; the horses' stats,
not the seed, decide the finish. Classic rooms stay exactly as they are.

Progress (see steps below; checked = done and reviewed):

- [x] 8.1 Courses: SVG files + backend plotter
- [x] 8.2 Horse roster
- [x] 8.3 Forge a horses room
- [x] 8.4 Steeds & readiness in the lobby
- [x] 8.5 The trumpet & the 30 s grace
- [x] 8.6 Horses race engine
- [x] 8.7 Horse sprites & the two-lane course
- [x] 8.8 Names, legend & results
- [x] 8.9 Practice horses
- [x] 8.10 Balance check tool

Files in play (touch nothing else):

- `api/tracks/*.svg` — new (the courses)
- `api/lib/Tracks.php` — new
- `api/lib/RaceEngine.php`, `api/lib/Snapshots.php`, `api/lib/RoomStore.php`
- `api/actions/` — `create_room`, `create_practice`, `start_race`,
  `rematch`, `leave_room`; new `tracks`, `track`, `horses`, `set_horse`,
  `set_ready`
- `api/index.php` (handler table only)
- `api/tools/balance.php` — new (8.10, CLI only)
- `docker/vhost.conf` (deny `api/tools` and `api/tracks` to the web)
- `public/js/racers.js`, `public/js/track.js`, `public/js/render.js`
- `public/js/components/` — `Landing.js`, `Lobby.js`, `RaceView.js`,
  `ResultsCard.js`
- `public/css/main.css`
- `docs/spec-rooms.md` (§4/§5 contract notes only), `docs/stages/stage-8.md`

> **Working rules — read first, keep to them**
>
> - Do the steps below **in order, one at a time**. Stop a step when its
>   "Done when" line is true. Do not gold-plate.
> - Touch **only the files listed above**. No drive-by refactors, no new
>   libraries or services.
> - Stack is fixed: plain JS + JSX (React UMD + Babel standalone), PHP 8.3,
>   HTML/CSS. **Never Node.js / npm / npx.**
> - **Classic is frozen.** `mode` defaults to `classic`; every classic code
>   path (`RaceEngine::create`/`step`, the n-lane oval, seeded `rank`,
>   rubber band, hold-the-line) stays byte-for-byte as it is, so old seeds
>   replay identically. Horses code branches on `mode === "custom"`.
> - The server stays authoritative: courses, horses, stamina, lanes and
>   the finish all live in the race state; clients only draw.
> - No host `php`, no `docker` (see `docs/failed-commands.md`): verify PHP
>   by reading; the user runs the app and the balance tool in Docker.
> - Anything not specified: pick the **simplest** option, note it in one
>   line in your reply, and keep moving. UI copy keeps the medieval voice;
>   code style matches the file you edit.
> - Can run before or after Stage 7 — the two share no steps.

## Ground facts (verified against the code, 2026-10-12)

**Server**

- `RaceEngine::create($prng, $n)` / `step(&$state)` /
  `advance(&$state, $targetTick, ?callable $onTick)`. `advance` stops at
  `finished >= n`; `catchUpRace()` in `Snapshots.php` drives rooms and
  practice alike and writes slim tick lines.
- `start_race` / `rematch` run inside `RoomStore::mutate` (errors via a
  `&$err` out-param), renumber rider lanes 0..n-1, and freeze
  `race.names` and `race.riders` (participant ids by lane). Predictions
  follow their rider: by lane map at the start, by rider id at a rematch.
- `RoomStore::put` / `saveRoom` return `bool`; `mutate` returns `null` when
  the write fails — callers answer 500 `storage_error`.
- Client snapshots are projections: `raceSnapshot` →
  `{tick, t, n, finished, placements, racers, startedAt, finishedAt}` with
  `slimRacers` `{lane, p, done, place}`; `roomSnapshot` adds `names`;
  `practiceSnapshot` mirrors it. Engine-only fields never leave the server.
- **`lane` means the rider's slot** everywhere — colour, name, prediction,
  placement. In horses mode it stays the slot index; the visual lane on
  the two-lane course is a new field, `side` (0 inner, 1 outer).

**Client**

- `net.js`: `api(action, body)`, `poll(url, ms, onData, onGone)`.
- `RaceView` interpolates `racers[i].p` between the two newest snapshots
  (`viewState`) and calls `draw(ctx, w, h, track, view)`; it rebuilds the
  track when `n` changes and raises `ResultsCard` 500 ms after the finish.
- `racers.js`: `RACERS` (8 entries: `name`, `color`, `path`), `SHAPES`
  (`Path2D`), `ORDINAL`. `track.js`: `buildTrack(w, h, n)`,
  `lanePose(tr, lane, u)`, `stadiumPath`.
- `Lobby` already polls the roster at 250 ms and shows the prediction
  picker and the host's trumpet.

## Steps

### 8.1 Courses: SVG files + backend plotter

`api/tracks/<slug>.svg`: one `<path id="centerline" d="…">` in a
`viewBox`, plus `data-name` (display name). A path ending in `Z` loops.
Ship three loops shaped to the §6.4 turn shares: `hippodrome` (≈ 35 %
turns, two bends), `long_straight` (≈ 20 %, two bends), `tight_circuit`
(≈ 50 %, four bends). Curves are cubic Béziers — the plotter reads
`M L H V C Q Z`, absolute and relative; anything else is refused.

`api/lib/Tracks.php`:

- `Tracks::all(): array` → `[{slug, name, loop}]`.
- `Tracks::load(string $slug): ?array` → `{slug, name, loop, viewBox, d,
  length, points, segments}`: the path flattened to points about every
  2 units with cumulative distance, `length` in path units, and
  `segments` — `[{kind: "straight" | "turn", from, to}]` over distance,
  from heading change per unit length (above a threshold = turn; runs
  shorter than a horse length merge into their neighbours). Memoize per
  request.
- Actions: `tracks` (GET → the list), `track` (GET `slug` → the full plot
  with `points` thinned to ≤ 400). Unknown slug → 404 `track_not_found`.
- `docker/vhost.conf`: deny direct web access to `api/tracks`.

Done when: all three load with `loop: true`, a positive `length`, and a
segment table whose turn share is within ±5 points of its target.

### 8.2 Horse roster

`RaceEngine::HORSES`: the §6.4 *Rebalanced roster* keyed by slug
(`zebra`, `fiery_tail`, `thundercloud`, `blazing`, `brunellus`,
`purebred_bay`, `night_charger`, `sirocco`, `purebred_bay_mare`,
`shadow`, `phantom`) → `{name, canter, turning, gallop, stamina}`, with
`canter`/`turning`/`gallop` as `slow | normal | fast`.
`RaceEngine::SPEED`: the §6.4 multiplier table. Action `horses` (GET) →
the roster in that order. Clients read it from there — no JS copy.

Done when: `?action=horses` lists all eleven with the §6.4 numbers.

### 8.3 Forge a horses room

`create_room {maxRacers, maxJoiners, mode?, track?, laps?}`:

- `mode`: `classic` (default) | `custom`.
- `custom` only: `track` must be a slug from `Tracks::all()` (default
  `hippodrome`); `laps` 1–3 on a loop (default 1), forced to 1 on an open
  course.
- Stored as `config.mode` / `config.track` / `config.laps`; anything
  off-list → 400 `bad_request`. Classic rooms store only `mode`.

Landing → *Forge a Room* gains *Rules of the race* — "Classic (the gods
decide)" / "Horses (the riders decide)"; with Horses: *The course*
(select, from `tracks`) and *Laps* (1–3, hidden for open courses).

Done when: a horses room stores mode, course and laps; a classic room
forges exactly as before.

### 8.4 Steeds & readiness in the lobby

- Participants gain `horse` (slug, or `null` = Random) and `ready`
  (`false`) — carried in the roster snapshot.
- `set_horse {roomId, token, horse}`: lobby only (409 `race_started`),
  riders only (403 `not_a_rider`), refused while that rider is ready
  (409 `horse_locked`); `horse` is a roster slug or `null`, else 400.
- `set_ready {roomId, token, ready}`: lobby only, riders only.
- `leave_room` clears `ready`; a re-attach keeps the horse.
- Lobby (horses rooms only): on thine own row a *Thy steed* select —
  *Random* first (the default), then the eleven with their four stat
  words — and a **Ready** toggle that locks the select. Every rider's row
  shows the horse (or *Random*) and a ✓ once ready.

Done when: two browsers see each other's picks and ✓ within one poll,
and a ready rider cannot change horse until un-readied.

### 8.5 The trumpet & the 30 s grace

`start_race` in a horses room:

- Every non-left rider ready → the race starts (8.6).
- Otherwise the first call stamps `trumpetAt` (server time) and answers
  409 `riders_not_ready`. From `trumpetAt + 30 s` the host may call again
  with `{force: true}`: the race starts, unready riders on their current
  pick (Random if none). A force before then → 409 `riders_not_ready`.
- Snapshot exposes `trumpetAt`. Every lobby shows *"The trumpet hath
  sounded — 27 s to make ready"*; when the grace ends the host's button
  reads **Start regardless**.

(The 30 s counts from the host's first call — the decision says "after
30 seconds" without a start point; this one is visible to everyone.)

Done when: unready → 409 with a countdown in every lobby; force works
only after 30 s; all ready starts at once.

### 8.6 Horses race engine

`RaceEngine::create($prng, $n, $opts = [])` — with
`$opts['mode'] === 'custom'` a separate path (the classic path is
untouched):

- Riders take slots 0..n-1 in **join order** (participant order), not by
  lane: slots 0 and 1 form the front row, 2 and 3 the next, and so on.
- Per slot: `horse` (Random resolved here from the PRNG), `stamina` (full),
  `blown: false`, `side` (slot parity), seeded `surgeAmp`/`surgeW`/
  `surgePhase` as today; no `rank`. Race gains `mode`, `track` (slug),
  `laps`, `horses` (slugs by slot — public, like `names`).

`step` in custom mode, per racer per tick:

- Distance along the course `s = p · laps · length`, wrapped to the lap;
  straight or turn from the shared segment table — the same for every
  horse.
- Turn: `TURNING`, stamina +½. Straight: gallop while not blown and
  stamina ≥ 1 (stamina −1), else canter and blown until the next turn
  (stamina +½). Stamina never above the horse's figure.
- `v = BASE_SPEED · speed · (1 + ½·surge + jitter)` with today's surge and
  jitter; `p += max(0.03, v) · STEP / laps`. No rank, no rubber band, no
  hold-the-line.
- Finish: same-tick finishers ordered by how far into the tick each
  crossed, `(1 − p_before) / (p_after − p_before)`, then by slot.
- `side` (presentation only, never speed): a horse closing on the horse
  ahead in its lane within 1.5 horse lengths moves to the other lane if
  that lane is clear for the same distance; otherwise it stays and passes
  through.

`start_race` / `rematch` (custom): build the race from `config.track` /
`config.laps` and the riders' horses; freeze `race.names`,
`race.riders`, `race.horses` as today. Tick lines and `slimRacers` gain
`side` in custom races only.

Done when: one seed + horses + course + laps replays identically; a
classic room's race is unchanged.

### 8.7 Horse sprites & the two-lane course

- `racers.js`: `HORSE_BODY` (`Path2D` of the sprite's first path),
  `HORSE_LINES` (the white detail path) and the eye from the §6.4 sprite;
  drawn about 28 px tall, **upright**, mirrored to face the way it
  travels, filled with the rider's colour (`RACERS[slot].color`).
- `track.js`: `buildCourse(w, h, plot)` scales the server plot
  **uniformly** into the window (letterboxed — never stretched);
  `coursePose(course, s, side)` → point on the polyline at distance `s`,
  offset half a lane inward or outward.
- `render.js`: for custom races, the bed stroked two lanes wide along
  the centre line, a dashed divider, the start/finish line across the
  course at `s = 0`, and the horses. The **start grid** is visual only:
  row `r` starts `r × 1.2` horse lengths back, and the gap closes linearly
  over the opening straight. Lane changes ease over ~300 ms. *"Lap 2 of
  3"* shows when `laps > 1`.
- `RaceView`: picks the renderer by `race.mode`; fetches the course plot
  once per race (`track`).

Done when: a horses race draws the server's course, horses face their
direction, overtakes visibly change lanes, and the start grid closes up.

### 8.8 Names, legend & results

Legend and standings read *"Swift Badger · Fiery Tail"* with the horse
sprite as the swatch; `ResultsCard` shows the horse under each placing.
Classic rooms render exactly as today.

Done when: a finished horses race reads e.g. *"1st Swift Badger · Fiery
Tail"* on the card.

### 8.9 Practice horses

The practice picker gains *Rules of the race*, *The course* and *Laps*;
`create_practice {racers, mode?, track?, laps?}` validated like 8.3. In
horses mode every racer's horse is drawn from the seed and the practice
snapshot carries `mode`, `track`, `laps`, `horses`.

Done when: a horses practice race runs end to end with 2–8 racers.

### 8.10 Balance check tool

`api/tools/balance.php`, CLI only (`PHP_SAPI !== 'cli'` → exit; the vhost
denies `api/tools`). Run by the user:
`docker compose exec web php api/tools/balance.php [races]`. For every
course × laps (1, 3): N seeded races of all eleven horses (8 per race,
rotating) through the real engine; prints win share and mean finish time
per horse, and win share by grid row.

Paste the output under *Balance log* below and retune `HORSES` /
`SPEED` from it.

Done when: no horse wins more than twice its fair share on any shipped
course, and win share by grid row is flat within noise.

## Balance log

(filled in from 8.10's output)

**Open — found while writing the engine (2026-10-12), awaiting a
decision.** A line-for-line Python port of the horses engine (scratch
check, not in the repo) ran 330 races per course × laps:

- The decided luck (surges at half strength plus per-tick jitter) averages
  out over a race, so the best horse for each course wins 4–8× its fair
  share — over 8.10's 2× target. Candidate fix: a seeded "form on the
  day" factor of about ±3 % per horse per race. Needs the user's OK —
  it changes the luck §6.4 agreed.
- About one lane change per race: eight horses bunch within the
  1.5-length look-ahead, so the other lane is rarely clear and most
  overtakes pass through. Candidate fix: a narrower clearance window.
- Grid rows win evenly (row wins within noise) and a seed replays the
  same race.

## Acceptance

- [ ] A horses room runs end to end: forge (mode, course, laps) → lobby
      steeds and ready → start (or force after 30 s) → a two-lane race
      with overtakes → results naming the horses.
- [ ] One seed + horses + course + laps replays identically.
- [ ] Classic rooms and classic practice are unchanged.
- [ ] The starting slot does not decide the race (8.10, by grid row).
- [ ] 8.10 run and logged; no horse above twice its fair share.
- [x] No Node/npm/npx introduced anywhere.

## Not in this stage

Abilities (reserved, Stage 6 §6.2), surface mechanics, an in-app course
editor, classic races on SVG courses, open (point-to-point) courses beyond
plotter support, export, TTL (Stage 7).
