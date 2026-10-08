# Chariot Racing

A single-page React app loaded from CDN with a small PHP JSON API, served by
one Apache container.

## Run it

    docker compose up --build

Then open http://localhost:1227

## Layout

- `public/index.html` — React 18 (UMD via CDN) + Babel standalone, no build step
- `public/css/main.css` — medieval / D&D-inspired theme (shared palette via CSS custom properties)
- `api/index.php` — lite JSON API (`?action=hello|health|echo`)
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
