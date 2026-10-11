# Chariot Racing

A single-page React app loaded from CDN with a small PHP JSON API, served by
one Apache container.

## Run it

    docker compose up --build

Then open http://localhost:1227

## Layout

- `public/index.html` — shell: React 18 (UMD via CDN) + Babel standalone + module script tags
- `public/css/main.css` — medieval / D&D-inspired theme (shared palette via CSS custom properties)
- `public/js/` — app code (JSX compiled in the browser), executed in the order listed in
  `index.html` and sharing one global scope:
  - `globals.js` — React hook aliases
  - `random.js` — seeded PRNG (race seeds stay inside the closures)
  - `theme.js` — palette shared by CSS custom properties and the canvas
  - `track.js` — track geometry (lanes, poses, stadium paths)
  - `racers.js` — racer shapes and colours
  - `simulation.js` — fixed-step race simulation
  - `render.js` — canvas painting (track, racers, standings)
  - `net.js` — JSON API helper, short poller, room-mark parsing
  - `names.js` — herald word-names
  - `routes.js` — query-string routing
  - `components/` — React views: `RaceView.js`, `Landing.js`, `JoinGate.js`,
    `Lobby.js`, `DustScroll.js`
  - `app.js` — router shell and mount
- `api/index.php` — JSON API front controller (dispatch on `?action=`)
- `api/actions/` — one handler per action: `hello`, `health`, `echo`, `seed`,
  the room actions (`create_room`, `join_room`, `room_state`, `leave_room`,
  `start_race`, `rematch`, `set_prediction`) and the practice actions
  (`create_practice`, `practice_state`)
- `api/lib/` — server code: `RaceEngine.php` (sim), `RoomStore.php` (JSON file
  store), `Http.php` (body parsing + error envelope), `Snapshots.php`
  (client views + race catch-up)
- `docker/vhost.conf` — Apache vhost (static frontend + PHP API)
- `Dockerfile` / `docker-compose.yml` — deployment

## API

| Request                   | Response                               |
| ------------------------- | -------------------------------------- |
| `GET /api/?action=hello`  | `{ "message": "Hello from the ..." }`  |
| `GET /api/?action=health` | status, timestamp, PHP version         |
| `POST /api/?action=echo`  | echoes method and JSON body            |
| `GET /api/?action=seed`   | XOR-masked base64 race seed            |

Note: `@babel/standalone` compiles JSX in the browser, which is convenient for
prototyping. For production, precompile the JSX and drop the Babel script tag.
