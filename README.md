# Chariot Racing

A medieval racing game in the browser: shareable rooms, bets on the winner,
rematches, and two kinds of race:

- **Classic**: chariots on an oval. The gods (the race seed) decide the order.
- **Horses**: riders pick horses with different stats and race them on a
  server-side course with two lanes — a drawn course, or a fresh loop
  through the roads of a map every race. The horses decide the order.

It's a single-page React app loaded from a CDN plus a small PHP JSON API,
served by one Apache container. **Docker is the only thing you install.**
There's no Node, npm or PHP on the host, and no build step.

## Install

1. Install [Docker](https://docs.docker.com/get-docker/) with Compose v2
   (`docker compose version` should print a version).
2. Get the code:

       git clone git@github.com:AlexMealey/Charriot-Runner.git
       cd Charriot-Runner

3. Build and start:

       docker compose up --build -d

4. Open http://localhost:1227

Other devices on your network can join at `http://<your-machine-ip>:1227`.
Share a room link from the lobby's **Copy link** button.

## Everyday commands

All commands run from the project folder. The service is called `web`.

| What | Command |
| --- | --- |
| Start (rebuilding if code changed) | `docker compose up --build -d` |
| Start in the foreground, logs inline | `docker compose up --build` |
| Stop, keeping race data | `docker compose down` |
| Stop and **erase all rooms and races** | `docker compose down -v` |
| Follow the logs | `docker compose logs -f web` |
| Restart without rebuilding | `docker compose restart web` |
| Shell inside the container | `docker compose exec web bash` |
| Is the API up? | `curl 'http://localhost:1227/api/?action=health'` |
| Remove everything: container, data and the built image | `docker compose down -v --rmi local` |

**Code changes need a rebuild.** The image *copies* `public/` and `api/`
into the container; there's no live mount. After editing anything, run
`docker compose up --build -d`. Race data survives the rebuild because it
lives in the `race-data` volume.

**Rooms expire.** A room or practice race nobody has touched for 6 hours
is deleted, along with its tick histories, the next time anyone creates
or joins a room. Its link then shows the dust card. An open tab keeps its
room alive, because every poll counts as a touch.

**Polling.** Clients poll the API: the lobby every 250 ms and the race view
every 500 ms (it eases the picture between polls). Hidden tabs slow to
once a second and catch up as soon as they're shown again.

## Tools

### Balance check (horses mode)

Runs hundreds of seeded horses races through the real engine on every course,
at 1 and 3 laps. For each horse it prints:

- its win share against a fair share (1.00 means it wins one race in eight);
- its mean finish time;
- wins by grid row.

```sh
docker compose exec web php api/tools/balance.php        # 220 races per course × laps
docker compose exec web php api/tools/balance.php 1000   # more races, steadier numbers
```

Target: no horse above 2× on any course, and grid rows level within noise.
Tune `HORSES` / `SPEED` in `api/lib/RaceEngine.php` from the output and log
the result under *Balance log* in `docs/stages/stage-8.md`. The tool only
runs from the command line; the web server refuses `api/tools/`.

### Map road extractor

Turns a map image's roads into the graph that race loops are picked from
(see *Adding a map*). It prints to stdout, so redirect it into the repo;
`-T` keeps the output clean:

```sh
docker compose exec -T web php api/tools/extract_roads.php <slug> > api/maps/<slug>.json
docker compose exec -T web php api/tools/extract_roads.php <slug> --preview > roads.png
```

### Lint the PHP

There's no PHP on the host, so lint inside the container:

```sh
docker compose exec web sh -c 'for f in api/*.php api/*/*.php; do php -l "$f"; done'
```

### Look at race data

Rooms and practice races are JSON files in the `race-data` volume, mounted
at `/var/www/html/data`. Each race's tick-by-tick history is a gzipped JSON
Lines file:

```sh
docker compose exec web ls data/rooms data/practice
docker compose exec web sh -c 'zcat data/rooms/<roomId>/race-0.ticks.jsonl.gz | head -3'
docker compose cp web:/var/www/html/data ./data-backup     # copy everything out
```

## Playing

- **Create a Room**: choose the chariots (2–8), the seats, and the rules:
  - *Classic*;
  - *Horses*, with a course and 1–20 laps.

  Share the link. Everyone picks a name (the dice rolls one) and lands in
  the lobby. Riders beyond the chariot count become onlookers. Everyone can
  back a winner.
- **Horses lobby**: each rider picks *Thy steed* (or leaves it on Random)
  and clicks **Make ready**. When the host sounds the trumpet before
  everyone is ready, the riders get 30 s to make ready; after that the host
  may **Start regardless**.
- **Practice**: a quick race with generated names, with no room needed and
  either set of rules.
- **Results**: placings, plus "true seer" for everyone who backed the
  winner. The host's **Race Again** starts a rematch with the same roster
  (and the same horses).

### Horses and courses

| Horse | Canter | Turning | Gallop | Stamina |
| --- | --- | --- | --- | --- |
| Zebra | Slow | Normal | Fast | 90 |
| Fiery Tail | Fast | Fast | Fast | 40 |
| Thundercloud | Fast | Fast | Normal | 50 |
| Blazing | Normal | Slow | Fast | 140 |
| Brunellus | Normal | Normal | Fast | 80 |
| Purebred Bay | Normal | Normal | Normal | 150 |
| Night Charger | Fast | Normal | Fast | 60 |
| Sirocco | Slow | Slow | Fast | 160 |
| Purebred Bay Mare | Slow | Normal | Normal | 180 |
| Shadow | Slow | Fast | Normal | 100 |
| Phantom | Fast | Normal | Normal | 80 |

- **Canter**: cruising speed.
- **Turning**: speed through the bends.
- **Gallop**: speed on the straights.
- **Stamina**: how many ticks of gallop a horse holds before it is blown.
  A blown horse canters until the next bend, and stamina refills at half
  rate whenever the horse isn't galloping.

| Course | Shape | Turns | Favours |
| --- | --- | --- | --- |
| Hippodrome | the classic oval | ~35 % | all-rounders |
| Long Straight | stretched oval, quick bends | ~20 % | gallopers with stamina |
| Tight Circuit | rounded box with a notch, eight bends | ~50 % | fast turners |
| Map courses: Emerald River, Grassland, Khamsin, La Grande Gloire, Thermopylae | a fresh loop through the map's roads each race | ~15–45 % | depends on the loop |

## Adding a course

Courses are SVG files in `api/tracks/`. Add `<slug>.svg` containing one
centre line:

```svg
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1000 400" data-name="My Course">
  <path id="centerline" d="M150 80 L850 80 C … Z"/>
</svg>
```

- The race starts and finishes at the first point and runs the way the
  path runs.
- Ending the path with `Z` makes it a loop, which allows laps.
- Only `M L H V C Q Z` are read; use cubic curves for bends.
- The server works out the straights and turns from the curve (a bend
  tighter than radius 400 counts as a turn). Check the turn share with
  `curl 'http://localhost:1227/api/?action=track&slug=<slug>'`.

Rebuild, and the course appears in the pickers.

## Adding a map

A map is a top-down image whose **light lines are the roads**. Each race on
it rides a fresh loop through those roads, and horses keep a steady pace,
so a longer loop — or a bigger map — makes a longer race.

1. Put the image in `public/maps/<slug>.webp` (or `.png`).
2. Add `public/maps/<slug>.json`:

   ```json
   { "name": "Emerald River", "size": "small" }
   ```

   `size` sets the race length: `small` ≈ 40 s to the winner over a typical
   loop, `medium` ≈ 80 s, `large` ≈ 2 min (per lap). For exact control,
   `"secondsAcross"` is the seconds a canter takes to cross the map's
   width. If a map's roads come out wrong, tune `greyMin` / `greyMax`
   (the roads' raw grey, ignoring transparency; default 98–140) and
   `opacityMin` (how opaque road ink must be, 0–255; default 80). Maps
   differ: Thermopylae's roads sit at 100–150, Khamsin's at 88–118 — the
   `--preview` image shows what was found.
3. Rebuild (`docker compose up --build -d`) so the container has it, then
   extract and check the roads (see *Map road extractor*):

   ```sh
   docker compose exec -T web php api/tools/extract_roads.php <slug> > api/maps/<slug>.json
   docker compose exec -T web php api/tools/extract_roads.php <slug> --preview > roads.png
   ```

4. Rebuild once more. The map appears in the pickers as
   "<Name> — a new road each race"; with two or more maps, "Any map" joins
   them.

## Configuration

There are no settings files; the knobs live in the code. Rebuild after
changing any of them.

| What | Where | Default |
| --- | --- | --- |
| Port on the host | `docker-compose.yml`, `ports: "1227:80"` (left side) | 1227 |
| Idle time before a room is deleted | `TTL` in `api/lib/RoomStore.php` | 6 h |
| Riders' grace once the trumpet sounds | `TRUMPET_GRACE` in `api/lib/Snapshots.php` | 30 s |
| Lobby poll rate | `poll(q, 250, …)` in `public/js/components/Lobby.js` | 250 ms |
| Race view poll rate | `POLL_MS` in `public/js/components/RaceView.js` | 500 ms |
| Slowest poll in a hidden tab | `pollDelay` in `public/js/net.js` | 1 s |
| Horse stats and speed table | `HORSES` / `SPEED` in `api/lib/RaceEngine.php` | see above |
| A map's race length | `size` / `secondsAcross` in `public/maps/<slug>.json` (re-extract after) | per map |
| Map loop lengths | `MIN_LOOP` / `MAX_LOOP` in `api/lib/Maps.php` | 1.2–3 map widths |
| Chariots and seats per room | checks in `api/actions/create_room.php` | 2–8 and 2–16 |

## Troubleshooting

- **Port 1227 already in use.** Change the left side of `ports` in
  `docker-compose.yml`, e.g. `"8080:80"`, then rebuild.
- **My change doesn't show.** Rebuild with `docker compose up --build -d`.
  If the browser still shows old code, hard-refresh (Ctrl+Shift+R), since
  the JS files can come from the browser cache.
- **"The sages stumbled — try once more."** The server hit an error. The
  detail is in `docker compose logs web`.
- **"The scribes could not set it down."** The server couldn't write race
  data. Check the data folder belongs to Apache:
  `docker compose exec web chown -R www-data:www-data data`.
- **A room link shows the dust card.** The room was untouched for 6 hours
  and has been deleted (or the id is wrong). Forge a new one.
- **Copy link does nothing on a LAN address.** Browsers only grant the
  clipboard API on `localhost` or HTTPS. The button falls back to an older
  copy method; if that fails too, copy the URL shown beside it.

## Layout

- `public/index.html`: the page. React 18 and Babel standalone come from
  unpkg; the app modules load as classic scripts in order.
- `public/css/main.css`: the medieval theme (a shared palette in CSS custom
  properties, also read by the canvas).
- `public/js/`: app code. JSX is compiled in the browser, and the files
  share one global scope:
  - `globals.js`: React hook aliases
  - `theme.js`: the palette shared by the CSS and the canvas
  - `track.js`: the classic oval (lanes, poses) and the course layout for
    horses races
  - `racers.js`: chariot shapes and colours, plus the horse sprite
  - `render.js`: canvas painting (oval or course, racers, standings, legend)
  - `net.js`: the JSON API helper (with medieval wording for error codes),
    the short poller (slower in hidden tabs), and room-mark parsing
  - `names.js`: herald word-names
  - `routes.js`: query-string routing (`/`, `/?room=`, `/?practice=`)
  - `components/`: React views: `Landing.js`, `JoinGate.js`, `Lobby.js`
    (with `SteedPicker.js`, the horse drop-down), `RaceView.js`,
    `ResultsCard.js` and `DustScroll.js`
  - `app.js`: the router shell and mount
- `api/index.php`: the JSON API front controller (dispatches on `?action=`)
- `api/actions/`: one handler per action (see the API table below)
- `api/lib/`:
  - `RaceEngine.php`: the deterministic race sim (classic and horses) and
    the horse roster
  - `Tracks.php`: the course plotter, and the course options every horses
    race starts from
  - `Maps.php`: map road graphs and the seeded loop each race rides
  - `RoomStore.php`: the JSON file store, with locking, atomic writes and
    6-hour garbage collection
  - `Http.php`: body parsing and the error envelope
  - `Snapshots.php`: what clients see, and race catch-up
- `api/tracks/`: the course SVGs
- `public/maps/`: map images (drawn behind the race) and their sidecars
- `api/maps/`: each map's road graph, written by the extractor
- `api/tools/`: `balance.php` (the balance check) and `extract_roads.php`
  (the map road extractor), both command line only
- `data/` (in the container, on the `race-data` volume): rooms, practice
  races and tick histories, written at runtime
- `docker/vhost.conf`: the Apache vhost. It serves the static frontend and
  the PHP API, and refuses `data/`, `api/tracks/`, `api/maps/` and
  `api/tools/`.
- `Dockerfile` / `docker-compose.yml`: the single `web` service on port 1227,
  with the `race-data` volume
- `docs/`: `spec-rooms.md` (the spec and API contract), `stages/` (the
  build plan, one file per stage) and `tool-errors.md` (notes for
  agent-run sessions)

## API

Every call goes to `/api/?action=<name>` and returns JSON. Errors are
`{"error": {"code", "message"}}` with an HTTP status; PHP's own errors go
to the container log (`docker compose logs web`), never into a reply.
Full contract: `docs/spec-rooms.md` §5; build history: `docs/stages/`.

| Action | Method | What it does |
| --- | --- | --- |
| `hello`, `health`, `echo` | GET / GET / POST | Liveness, status and an echo |
| `create_room` | POST | `{maxRacers, maxJoiners, mode?, track?, laps?}` → `{roomId, hostToken, joinUrl}` |
| `join_room` | POST | `{roomId, nickname, participantId?, token?}` → `{participantId, token, role, snapshot}` |
| `room_state` | GET | `room` → the room snapshot; also catches the race up to now |
| `leave_room` | POST | `{roomId, token}` frees the seat |
| `set_prediction` | POST | `{roomId, token, pick}` backs a winner (lobby only) |
| `set_horse` | POST | `{roomId, token, horse}` picks a steed (horses lobby) |
| `set_ready` | POST | `{roomId, token, ready}` makes ready or stands down |
| `start_race` | POST | `{roomId, hostToken, force?}` starts the race (host only) |
| `rematch` | POST | `{roomId, hostToken}` archives the finished race and runs another |
| `create_practice` | POST | `{racers, mode?, track?, laps?}` → `{practiceId, snapshot}` |
| `practice_state` | GET | `race` → the practice snapshot |
| `tracks` | GET | Lists every course |
| `track` | GET | `slug` → one course's plot (points, straights and turns) |
| `course` | GET | `room` or `practice` → the map loop the current race rides |
| `horses` | GET | The horse roster and its stats |

## Project status

Built in stages; each stage's plan and status is in `docs/stages/`.

- **Stages 1–9 are written:** the race engine, rooms, lobby, shared race,
  results and rematch, expiry and hardening, horses mode, and map courses.
- **Each stage's acceptance is still pending a run in Docker** — tick the
  boxes in its stage file as you confirm them.
- **Open for horses mode** (see *Balance log* in
  `docs/stages/stage-8.md`):
  - luck may be too small for the stats, so the best horse for a course
    could win most races — run the balance check to confirm;
  - horses change lanes rarely.
- **Deferred:** race export to MP4 (`docs/spec-rooms.md` §7).

Note: `@babel/standalone` compiles the JSX in the browser, which is handy
for prototyping. For production, precompile the JSX and drop the Babel
script tag.
