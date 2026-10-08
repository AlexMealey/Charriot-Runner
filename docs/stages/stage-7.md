# Stage 7 — TTL, hardening, docs

Status: not started
Goal: old rooms quietly crumble to dust, rough edges read kindly, and the
README tells the truth.

Progress (see steps below; checked = done and reviewed):

- [ ] 7.1 Garbage collection (6 h)
- [ ] 7.2 Expired room → dust scroll
- [ ] 7.3 Error wording
- [ ] 7.4 Poll backoff on hidden tabs
- [ ] 7.5 Remove the local-sim leftovers
- [ ] 7.6 README

Files in play (touch nothing else):

- `api/lib/RoomStore.php`
- `api/index.php`
- `public/index.html`
- `public/css/main.css`
- `README.md`

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

## Steps

### 7.1 Garbage collection (6 h)

`RoomStore::gc()`: delete `data/rooms/*`, `data/practice/*` and
`data/exports/*` untouched for **6 hours** (simplest: file/directory mtime;
an existing `updatedAt` check is fine too). Deletes room/practice docs, their
`race-*.ticks.jsonl.gz`, and finished exports. Call it at the top of
`create_room`, `join_room`, `create_practice`.
Done when: an aged test room disappears without breaking live ones.

### 7.2 Expired room → dust scroll

`room_state` / `join_room` on a missing id keep returning 404
`room_not_found` (Stage 3). Frontend: opening `/?room=<id>` probes
`room_state` once on mount — `room_not_found` → the dust scroll (the
`JoinGate` `lost` path from Stage 2). Keep the nickname form only for rooms
that answer.
Done when: an expired link shows dust immediately, no join form.

### 7.3 Error wording

Map the API codes to medieval one-liners in the existing notice style:
`room_full` (*"the hippodrome is full — every seat is taken"*), `not_host`
(*"the host alone may call the race"*), `too_few_racers` (*"two riders at
least"*), `already_racing`, `race_started`, `practice_not_found`. No new UI
elements — notices only.
Done when: each code has its own line and no raw PHP text leaks to users.

### 7.4 Poll backoff on hidden tabs

In the shared poll helper: 250 ms while `document.visibilityState ==
"visible"`, 1000 ms while hidden; switch on `visibilitychange`.
Done when: throttling appears in dev tools and races still sync.

### 7.5 Remove the local-sim leftovers

Delete `seededRandom`, the `action=seed` case in `api/index.php`, and any
unused local race-sim code left in `public/index.html` from before Stage 1
(keep `mulberry32` only if still referenced).
Done when: no dead code remains and practice still runs.

### 7.6 README

Update `README.md`: layout list, the action table (create/join/room/practice
actions, minus deferred export), polling note, and a pointer to
`docs/spec-rooms.md` + `docs/stages/`. Keep it short.
Done when: README matches the shipped API exactly.

## Acceptance

- [ ] An untouched room disappears after 6 hours and its URL shows the dust
      card.
- [ ] All listed error codes read as medieval one-liners.
- [ ] Hidden tabs poll slowly; visible tabs stay at 250 ms.
- [ ] README matches the shipped API.
- [ ] No Node/npm/npx introduced anywhere.

## Not in this stage

Export (deferred — `docs/stages/stage-6.md`), and anything listed in
`docs/spec-rooms.md` §12 (out of scope).
