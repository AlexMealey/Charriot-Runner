# Stage 4 — Multiplayer race + predictions

Status: not started
Goal: the host starts one server-authoritative race that every client sees
identically; players back a winner beforehand.

Progress (see steps below; checked = done and reviewed):

- [ ] 4.1 Action `start_race`
- [ ] 4.2 `room_state` catch-up + tick history
- [ ] 4.3 Wire the lobby button
- [ ] 4.4 Shared race view
- [ ] 4.5 Late join & reload
- [ ] 4.6 Action `set_prediction`
- [ ] 4.7 Prediction picker in the lobby
- [ ] 4.8 Wavering-connection notice

Files in play (touch nothing else):

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

### 4.1 Action `start_race`

POST `{roomId, hostToken}`: 404 `room_not_found`; wrong token → 403 `not_host`;
`status != "lobby"` → 409 `already_racing`; fewer than 2 racers → 409
`too_few_racers`. Otherwise `RaceEngine::create` with `n =` racer count (lanes
follow the participants' `lane` order), store as `race` with `startedAt =
microtime(true)`, set `status: "racing"`, reply `{ok: true}`.
Done when: clicking the lobby button flips the room to `racing` for everyone.

### 4.2 `room_state` catch-up + tick history

While `status == "racing"`: `targetTick = floor((now - startedAt) * 60)`,
`RaceEngine::advance`, append the new tick lines to
`race-<raceNo>.ticks.jsonl.gz` (§3 tick format), save, and include `race`
plus `names` (racer nicknames by lane) in the snapshot. When all are done:
set `status: "finished"`, `finishedAt`.
Done when: a paused tab catches up correctly on its next poll.

### 4.3 Wire the lobby button

Replace step 3.10's unwired **Raise the Trumpet**: host click → `start_race`;
server errors show as the existing notice (`not_host`, `too_few_racers`,
`already_racing` wording in medieval voice).
Done when: every open lobby switches to the race view without reload.

### 4.4 Shared race view

Rooms reuse `RaceView` fed by `room_state` polls (250 ms) with the same
interpolation as practice. Chariot labels use the `names` array (player
nicknames) instead of generated ones.
Done when: 3+ browsers show the same positions and finish order.

### 4.5 Late join & reload

When `room_state` reports `racing`, the join gate goes straight to the race
view at the current tick (skip the lobby). Reload mid-race does the same.
Done when: a joiner at mid-race sees the true current standings.

### 4.6 Action `set_prediction`

POST `{roomId, token, pick}`: only while `status == "lobby"` (409
`race_started`); `pick` must be a racer lane (400 `bad_request` otherwise);
write `prediction = pick` on the participant; reply `{ok: true}`.
Done when: polls after the start can no longer change a prediction.

### 4.7 Prediction picker in the lobby

List of racer nicknames as one-select choices + heading *"Back the winner"*;
sends `set_prediction` on change; shows thy pick as *"thou backest: X"*. The
whole block disappears (or greys out) once the race starts.
Done when: two browsers see each other's picks via the roster poll.

### 4.8 Wavering-connection notice

Small dim line *"thine link doth waver…"* after 2 consecutive failed polls;
clears itself on the next success. No retry logic beyond the ongoing poll.
Done when: stopping the API briefly shows and then clears the line.

## Acceptance

- [ ] 3+ clients observe the same positions and identical final placements.
- [ ] A client joining mid-race sees the true current standings.
- [ ] Predictions lock at the start.
- [ ] No Node/npm/npx introduced anywhere.

## Not in this stage

Results screen and rematch (Stage 5) — when `finished`, just stop polling and
keep the frozen final frame; export, TTL — later stages.
