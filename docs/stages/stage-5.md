# Stage 5 — Results + rematch

Status: not started
Goal: the finish is celebrated with placements and true seers, and the host
can run a fresh race on the same roster.

Progress (see steps below; checked = done and reviewed):

- [ ] 5.1 Results card (rooms)
- [ ] 5.2 Seers block
- [ ] 5.3 Action `rematch`
- [ ] 5.4 Race again button
- [ ] 5.5 Practice results

Files in play (touch nothing else):

- `api/lib/RoomStore.php` (only if archiving needs a helper)
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

## Steps

### 5.1 Results card (rooms)

When `room_state` reports `finished`, show a parchment results card over the
frozen canvas: heading *"The Race Is Run"*, placements in order — `ORDINAL`,
color swatch, nickname. Polling stops.
Done when: placements match what was on track.

### 5.2 Seers block

Under the placements: each participant's pick, with backers of the winner
tagged *true seer* in gold, the rest dim. Include spectators' picks.
Done when: correct and incorrect backers read differently at a glance.

### 5.3 Action `rematch`

POST `{roomId, hostToken}`: 404/403 as `start_race`; only from
`status == "finished"` (409 `race_not_finished` or `already_racing`). Archive
the finished race into `history[]` (`raceNo`, `placements`, `predictions`,
ticks filename), then `RaceEngine::create` a fresh race (same n/lanes),
`raceNo++`, `status: "racing"`; reply `{ok: true}`.
Done when: the tick file of race 1 survives the start of race 2.

### 5.4 Race again button

On the results card: **Race Again** for the host (wired to `rematch`);
non-hosts see it disabled with *"the host alone may call the race"*. Success
returns everyone to the live race view.
Done when: the second race differs from the first but keeps the roster.

### 5.5 Practice results

Practice keeps it simpler: results card offers **Race Again** returning to
the count picker (Stage 1, step 1.9) — no room, host, or history involved.
Done when: repeated practice races work without touching room code.

## Acceptance

- [ ] Results match what was on track (ordinals, nicknames, colors).
- [ ] Winner backers are tagged *true seer*; others are not.
- [ ] *Race Again* yields a different race for the same roster.
- [ ] Earlier races stay in `history` with their tick files.
- [ ] No Node/npm/npx introduced anywhere.

## Not in this stage

Export (deferred — `docs/stages/stage-6.md`), TTL and docs (Stage 7).
