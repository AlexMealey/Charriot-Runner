# Stage 3 — Rooms: create, join, lobby

Status: in progress — steps 3.1–3.10 done, acceptance pending
Goal: a shareable room exists on the server; joiners gather in a live lobby
with roles and a copyable link.

Progress (see steps below; checked = done and reviewed):

- [x] 3.1 `RoomStore` room methods
- [x] 3.2 Action `create_room`
- [x] 3.3 Action `join_room`
- [x] 3.4 Action `room_state`
- [x] 3.5 Action `leave_room`
- [x] 3.6 Frontend: join success → lobby
- [x] 3.7 Lobby roster
- [x] 3.8 Share panel
- [x] 3.9 Poll `room_state`
- [x] 3.10 Start-race button (unwired)

Files in play (touch nothing else):

- `api/lib/RoomStore.php`
- `api/index.php`
- `public/index.html`
- `public/css/main.css`

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
> - Room doc shape: `docs/spec-rooms.md` §4. API contract: §5.

## Steps

### 3.1 `RoomStore` room methods

Add to `api/lib/RoomStore.php`: `createRoom(array $cfg): array` (id =
`bin2hex(random_bytes(16))`, `hostToken = bin2hex(random_bytes(32))`, doc per
§4 with `status: "lobby"`, empty `participants`, `raceNo: 0`, `history: []`),
plus `getRoom(string $id): ?array` and `saveRoom(array $doc): void` reusing
the locked read/write from step 1.5 (`$kind = "rooms"`).
Done when: created rooms round-trip from disk intact.

### 3.2 Action `create_room`

POST `{maxRacers 2..8, maxJoiners 2..16}` → validate, `createRoom`, reply
`{roomId, hostToken, joinUrl}` (`joinUrl` = current origin + `/?room=<id>`).
Bad input → 400 `bad_request`.
Done when: two calls yield two different room ids.

### 3.3 Action `join_room`

POST `{roomId, nickname}`: trim nickname, cap 24 chars (empty → 400
`bad_request`); missing room → 404 `room_not_found`; all slots taken
(`count(participants) >= maxJoiners`) → 409 `room_full`. Role: **racer** while
racer slots remain (assign `lane` in join order, 0..maxRacers-1), else
**spectator**. Store `{id, token, nickname, role, lane, joinedAt, prediction:
null}` (`id`/`token` random hex like 3.1) and reply `{participantId, token,
role, snapshot}`.
Done when: the tenth joiner of a 4-racer room is a spectator.

### 3.4 Action `room_state`

GET `room`, optional `tick`, `token`: load room (404 `room_not_found`),
bump `updatedAt = microtime(true)`, save, reply the snapshot `{id, status,
config, participants: [{id, nickname, role, lane}], raceNo, race: null}`.
(`race` comes alive in Stage 4 — always `null` for now.)
Done when: repeated calls only change `updatedAt`.

### 3.5 Action `leave_room`

POST `{roomId, token}`: remove the participant (a racer slot frees up before
the race starts), bump `updatedAt`, reply `{ok: true}`. Unknown token → 403
`not_a_participant`.
Done when: a departed racer's lane is claimable by the next joiner.

### 3.6 Frontend: join success → lobby

In `JoinGate`, replace the "Thou Art Inscribed" placeholder: on `join_room`
success store the session as `localStorage["chariot.player.<roomId>"]`
(already done) and render the **lobby** component with the returned
snapshot.
Done when: a successful join lands in the lobby, no reload.

### 3.7 Lobby roster

Roster list: every participant — nickname, gold role tag (*rider* /
*onlooker*), and the chariot color swatch for racers (reuse `RACERS` colors
by lane). Show the host badge (⚜ *host*) when
`localStorage["chariot.host.<roomId>"]` exists. Header line: "N of M seats
filled".
Done when: two browsers see the same roster ≤250 ms apart (step 3.9 polls).

### 3.8 Share panel

Above the roster: the full room URL as text + **Copy link** button
(`navigator.clipboard.writeText`, `textarea` fallback). On success the button
reads *Copied!* in gold for 2 s.
Done when: the copied URL opens the join gate in a fresh tab.

### 3.9 Poll `room_state`

Tiny shared helper `poll(url, intervalMs, onData)` (plain `fetch` +
`setTimeout`, stops on unmount — same shape as the practice polling from
Stage 1). Lobby polls every 250 ms with the participant `token`.
Done when: joiners/leavers appear in other browsers without reloads.

### 3.10 Start-race button (unwired)

Bottom of the lobby: host-only **Raise the Trumpet** button showing "N
riders" — enabled for the host only when `racers >= 2`, disabled otherwise
with the reason text (*"the host alone may call the race"* / *"two riders at
least"*). **No action on click yet** — Stage 4 wires it.
Done when: states (host/guest, 2+/&lt;2) all read correctly.

## Acceptance

- [x] Two browsers on `/?room=<id>` see the same roster live (≤250 ms lag).
- [x] Joining past the racer cap lands the joiner as spectator.
- [x] Copy button puts the exact join URL on the clipboard.
- [x] Reload keeps your identity (tokens in `localStorage`).
- [x] No Node/npm/npx introduced anywhere.

## Not in this stage

Running the race (`start_race`), shared race view, predictions, results,
export, TTL — later stages.
