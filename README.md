# Chariot Racing

A medieval racing game in the browser: shareable rooms, bets on the winner,
rematches, and two kinds of race:

- **Classic**: chariots on an oval. The gods (the race seed) decide the order.
- **Horses**: riders pick horses with different stats and race them on a
  server-side course with two lanes. The horses decide the order.

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

**Code changes need a rebuild.** The image *copies* `public/` and `api/`
into the container; there's no live mount. After editing anything, run
`docker compose up --build -d`. Race data survives the rebuild because it
lives in the `race-data` volume.

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
  - *Horses*, with a course and 1–3 laps.

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

## Layout

- `public/index.html`: the page. React 18 and Babel standalone come from
  unpkg; the app modules load as classic scripts in order.
- `public/css/main.css`: the medieval theme (a shared palette in CSS custom
  properties, also read by the canvas).
- `public/js/`: app code. JSX is compiled in the browser, and the files
  share one global scope:
  - `globals.js`: React hook aliases
  - `random.js`, `simulation.js`: the old browser-side race code, now
    unused (removal is planned in Stage 7.5)
  - `theme.js`: the palette shared by the CSS and the canvas
  - `track.js`: the classic oval (lanes, poses) and the course layout for
    horses races
  - `racers.js`: chariot shapes and colours, plus the horse sprite
  - `render.js`: canvas painting (oval or course, racers, standings, legend)
  - `net.js`: the JSON API helper, the short poller, and room-mark parsing
  - `names.js`: herald word-names
  - `routes.js`: query-string routing (`/`, `/?room=`, `/?practice=`)
  - `components/`: React views: `Landing.js`, `JoinGate.js`, `Lobby.js`,
    `RaceView.js`, `ResultsCard.js` and `DustScroll.js`
  - `app.js`: the router shell and mount
- `api/index.php`: the JSON API front controller (dispatches on `?action=`)
- `api/actions/`: one handler per action (see the API table below)
- `api/lib/`:
  - `RaceEngine.php`: the deterministic race sim (classic and horses) and
    the horse roster
  - `Tracks.php`: the course plotter
  - `RoomStore.php`: the JSON file store, with locking and atomic writes
  - `Http.php`: body parsing and the error envelope
  - `Snapshots.php`: what clients see, and race catch-up
- `api/tracks/`: the course SVGs
- `api/tools/balance.php`: the balance check (command line only)
- `docker/vhost.conf`: the Apache vhost. It serves the static frontend and
  the PHP API, and refuses `data/`, `api/tracks/` and `api/tools/`.
- `Dockerfile` / `docker-compose.yml`: the single `web` service on port 1227,
  with the `race-data` volume
- `docs/`: `spec-rooms.md` (the spec and API contract), and `stages/` (the
  build plan, one file per stage)

## API

Every call goes to `/api/?action=<name>` and returns JSON. Errors are
`{"error": {"code", "message"}}` with an HTTP status. Full contract:
`docs/spec-rooms.md` §5.

| Action | Method | What it does |
| --- | --- | --- |
| `hello`, `health`, `echo` | GET / GET / POST | Liveness, status and an echo |
| `seed` | GET | Legacy browser-side race seed (removal planned in Stage 7) |
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
| `horses` | GET | The horse roster and its stats |

Note: `@babel/standalone` compiles the JSX in the browser, which is handy
for prototyping. For production, precompile the JSX and drop the Babel
script tag.
