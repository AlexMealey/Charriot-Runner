# Stage 9 — Map courses (random loops through road maps)

Status: **built (2026-10-12)** — decisions in §9.4, steps 9.5–9.11 written
and reviewed by reading; acceptance pending a runtime pass.
Goal: horses races run on loops picked at random through the road network
of real map images, so every race (and every rematch) gets a fresh course.
The maps are top-down images whose light lines are the roads; a series of
them will be added to the project.

Progress (checked = done):

- [x] 9.1 Feasibility: roads extracted from the example map
- [x] 9.2 Spec draft (below)
- [x] 9.3 Open questions answered
- [x] 9.4 Decision log + implementation steps
- [x] 9.5–9.11 Built (see §9.4)

Files in play: `Dockerfile`, `docker/vhost.conf`, `public/maps/`,
`api/maps/`, `api/tools/extract_roads.php`, `api/tools/balance.php`,
`api/lib/{Maps,Tracks,RaceEngine,Snapshots}.php`, `api/actions/{course,
start_race,rematch,create_practice}.php`, `api/index.php`,
`public/js/{track,render}.js`, `public/js/components/RaceView.js`, docs.

> **Working rules** — as Stage 8: the server stays authoritative and
> deterministic (a race's course is part of its frozen state), the stack is
> PHP 8.3 + plain JS/JSX + HTML/CSS, **never Node/npm/npx**, and classic
> races stay untouched.

## 9.1 Feasibility (2026-10-12)

Tried on the example `image.webp` (512 × 512, RGBA) with ImageMagick and
plain Python in a scratch folder; nothing was added to the app.

- **The map is semi-transparent ink.** On a white page the ground reads
  ~142 and the roads ~131 — roads look *darker*, and the faded border sits
  at the same brightness as roads, so brightness after flattening fails.
- **The raw colour carries the roads cleanly.** Ignoring alpha, roads are
  light (RGB grey ~107–117) against ground ~75–82, and they are more
  opaque (alpha ~215–226 against ~155–165). Forest blobs are near-black.
- **Rule that works:** road = raw grey 98–140 **and** alpha ≥ 195. It marks
  6.6 % of the pixels as one connected network across the whole opaque
  interior — every road there, the three bridges included, and none of the
  forest halos that a local-contrast rule picked up instead.
- **What it misses:** roads in the faded margin (alpha drops there), which
  conveniently keeps routes inside the playable interior.
- Preview — roads in red over the dimmed map:
  [stage-9-roads-preview.png](stage-9-roads-preview.png).

## 9.2 Spec draft

### Pipeline

1. **Map image** → `public/maps/<slug>.webp` (served to browsers as the
   race backdrop), with a small sidecar `public/maps/<slug>.json`: display
   name and, when a map needs them, its own thresholds.
2. **Road graph** (once per image, offline) → `api/maps/<slug>.json`,
   produced by a CLI tool and committed.
3. **Route** (per race, from the race seed) → a loop through the graph →
   smoothed polyline → the existing course plot (straight/turn table).
4. **Race view** → the map drawn under the route, the two lanes and the
   horses on top.

### Extraction tool (CLI, PHP + GD)

`docker compose exec web php api/tools/extract_roads.php <slug>`:

1. **Mask:** raw grey in the band and alpha above the cutoff (9.1 rule;
   per-map overrides from the sidecar).
2. **Clean:** close small gaps (dilate then erode), drop blobs under a few
   dozen pixels, keep the largest connected network.
3. **Thin** to one-pixel centre lines (Zhang–Suen).
4. **Graph:** nodes at junctions and dead ends, edges as the pixel chains
   between them; prune short spurs (thinning whiskers), merge nodes closer
   than a road width, drop dead-end branches (no loop can use them).
5. **Simplify and save:** each edge as a smoothed polyline with its length;
   write `api/maps/<slug>.json` plus a preview PNG of the graph over the
   map for checking by eye.

Needs **GD with WebP** in the image — today's `php:8.3-apache` has no GD.
The Dockerfile gains the libwebp/libpng packages and
`docker-php-ext-configure gd --with-webp && docker-php-ext-install gd`.

### Route picking (server, seeded)

At `start_race` / `rematch` / `create_practice`, from its own PRNG stream
derived from the race seed (the horses' draws stay as they are):

1. Pick a random edge `a → b`.
2. Find the shortest path from `b` back to `a` without that edge, on edge
   weights jittered by the PRNG — edge + path is a **simple loop** by
   construction.
3. Keep it if its length is in range and it has no hairpin (no junction
   turning back on itself); otherwise try another edge (bounded tries, then
   the best candidate).
4. Smooth the loop (Chaikin), start it at the beginning of its longest
   straight (room for the start grid), and plot it like an SVG course:
   evenly spaced points + straight/turn table.

The loop is **frozen into the race** (`race.course` gains `map` and the
points), so a replay never re-picks it, and later map edits cannot change a
finished race.

### Things the draft has to settle

- **Lap time vs route length.** Speed today is laps per second, so a short
  loop and a long one take the same time. Map loops vary a lot in length;
  either keep lap time fixed (simple, balance unchanged) or scale it by the
  loop's length (a longer loop is a longer race).
- **Turn detection scale.** The classifier treats radius < 400 path units
  as a turn, tuned on 1000-unit courses. A 512-px map's bends are 30–150 px,
  so the threshold must become relative to the map's size, and the horse
  balance (8.10) re-checked on map loops.
- **Lane width vs road width.** Map roads are ~6 px wide on a 512-px image;
  two horse lanes are wider than the road at any useful scale. The route
  can be drawn as a highlighted band over the road (lanes inside it), or
  the map scaled up so the road carries the lanes.
- **Watermark and border.** The example carries a site watermark in its
  corner and a faded border; both show if the image is the backdrop.

### Data & API (for the implementation stage)

- Room config: `track` takes a course slug as today, or `map:<slug>` (or
  `map:*` — any map) for a fresh random loop each race.
- New `course` GET (`room` or `practice`) → the current race's frozen plot
  plus `map` (image URL, size) — fetched once per race, like `track`
  today; the snapshot carries only a `course` key so clients know when to
  refetch.
- `tracks` lists maps alongside the SVG courses.

### Screens

- Forge / practice pickers: *The course* lists the SVG courses, each map
  as "<Map name> — a new road each race", and "Any map".
- Lobby: the chosen map named; optionally a thumbnail.
- Race view: the map as backdrop (letterboxed like courses), the loop
  highlighted, horses on it; results name the map.

## 9.3 Open questions (block until answered)

1. **Rights to the images.** The example carries a third-party watermark
   (`conqsite.bard-dev.com`). Are you cleared to commit and serve these
   maps? Should the watermark corner be masked or cropped?
2. **Lap time:** keep every lap the same length in time (today), or let a
   longer loop make a longer race?
3. **Loop length:** what range — e.g. 30–60 % of the map's width ×4 per
   lap — and should loops avoid the fort interiors and bridges, or are
   bridges a feature?
4. **Look:** draw the two lanes as a band over the map road (road gets
   wider), or scale the map up so the road itself carries the lanes?
5. **Variation scope:** a fresh loop every race (rematches too), or one
   loop per room that the host can re-roll in the lobby with a preview?
6. **The image series:** all the same style as the example (RGBA, light
   roads, faded border, 512 px)? Different styles need per-map thresholds
   in the sidecar.

## 9.4 Decision log & implementation steps

Answers from the user on 2026-10-12; *(draft default)* marks a question
left open, where the §9.3 draft stands.

| # | Question | Decision |
| --- | --- | --- |
| 1 | Rights | Cleared to use the maps. The image is kept as it is, watermark included. |
| 2 | Lap time | **A steady pace across the map:** race time follows the loop's length (and laps). Each map declares its size in its sidecar — `small` ≈ 40 s to the winner over a typical loop, `large` ≈ 2 min (`medium` between; `secondsAcross` sets it exactly). Drawn SVG courses keep their fixed lap. |
| 3 | Loop shape | Clean loops — never crossing themselves, never doubling back (no turn over 150° at a junction) — lightly smoothed (one Chaikin pass) so the roads keep some jags and sudden turns. 1.2–3 map widths long, the target length drawn per race. Bridges and fort roads allowed *(draft default)*. |
| 4 | Look | The map drawn behind the course, the loop highlighted as a gold band (two narrow lanes) over its roads, the horses on it. |
| 5 | Variation | A fresh loop every race, rematches included *(draft default)*. |
| 6 | Image series | The example's style (RGBA, light roads, faded border); per-map thresholds in the sidecar when one differs *(draft default)*. |
| 7 | Laps | Up to **20** laps per horses race (was 1–3; user, 2026-10-12): `Tracks::MAX_LAPS`, and the forge/practice slider. |

**Findings that shaped the build** (Python prototype on the example, in a
scratch folder):

- Opacity ≥ 80 (not 195) joins the outer roads, bridges and forts into one
  network: 23 812 of 24 780 road pixels in one piece.
- Splitting roads at junctions needs the junctions grown by a pixel (the
  first pixels of two roads touch diagonally otherwise), and each road is
  ordered by a shortest walk between its junctions, not a greedy trace.
- Result: 29 junctions, 45 roads. 40 seeds gave 28 different loops of
  1.4–3.0 map widths; on the "small" River Crossing one lap takes 31–50 s.
- Turns: bends tighter than a tenth of the map's width (heading read over
  ±5 px) give map loops ~15–45 % turns, like the drawn courses.

### Steps (built)

- [x] **9.5 GD in the image.** `Dockerfile` installs GD with WebP/PNG/JPEG
  for the extractor; the site itself does not use it.
- [x] **9.6 Extraction tool.** `api/tools/extract_roads.php <slug>` (CLI;
  `--preview` for a PNG) prints the road graph JSON — see the README.
- [x] **9.7 Loops.** `api/lib/Maps.php`: map listing, seeded loop picking,
  plotting, the per-map pace (`baseSpeed`, laps per second at a canter).
- [x] **9.8 Races.** `Tracks::raceOptions()` builds every horses race's
  course (drawn or map) for `start_race`, `rematch` and `create_practice`;
  a map loop is frozen into `race.course`; the engine reads its pace; the
  `course` action serves it; snapshots carry `courseKey`.
- [x] **9.9 Race view.** The map behind the course, the loop as a band,
  narrow lanes and horses; each race's loop fetched once.
- [x] **9.10 The maps.** Six AVIF maps (1920 px) arrived mid-build and
  went to `public/maps/<slug>.avif` with sidecars (all `medium` for now —
  set `small`/`large` per map). Per-map grey bands came from each map's
  brightness histogram (roads are its lighter peak). Graphs in `api/maps/`,
  written by the prototype (same steps and format; the PHP tool
  regenerates them):

  | Map | Junctions | Loops in 30 races | Lap (medium) | Note |
  | --- | --- | --- | --- | --- |
  | Emerald River | 29 | 20 | 48–111 s | the example map, full size |
  | Grassland | 14 | 20 | 47–89 s | |
  | Khamsin | 42 | 25 | 51–106 s | |
  | La Grande Gloire | 45 | 30 | 51–80 s | |
  | Thermopylae | 16 | 7 | 18–30 s | small network, short loops |
  | Haojing | — | — | — | **not listed:** a city map; the band finds no road network yet |

  The first example (`public/maps/river_crossing.webp`) is Emerald River
  at 512 px, so it has no graph — no duplicate in the pickers.
  Extraction also gained: block-averaged downscaling to ~640 px (memory,
  and road widths like the example's), AVIF via GD (`--with-avif`), and
  keeping every road network at least a fifth the largest's size.
- [x] **9.11 Balance.** `api/tools/balance.php` runs each map too, a fresh
  loop every race.

### Acceptance

- [ ] A map course appears in the forge and practice pickers and races a
      fresh loop each race, the map behind it.
- [ ] A race replays identically (its loop is frozen in the race).
- [ ] Race times follow map size and loop length (~40 s small, ~2 min
      large, one lap).
- [ ] `extract_roads.php` regenerates a graph like the committed one.
- [ ] Balance run includes the map; no horse above 2× its fair share.
- [x] No Node/npm/npx introduced anywhere.
