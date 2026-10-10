# Stage 5 — Results + rematch

Status: in progress — 5.1–5.5 done, acceptance pending
Goal: the finish is celebrated with placements and true seers, and the host
can run a fresh race on the same roster.

Progress (see steps below; checked = done and reviewed):

- [x] 5.1 Results card (rooms)
- [x] 5.2 Seers block
- [x] 5.3 Action `rematch`
- [x] 5.4 Race again button
- [x] 5.5 Practice results

Files in play (touch nothing else):

- `api/index.php` (the `rematch` case only)
- `api/lib/RoomStore.php` (only if archiving needs a helper — likely untouched)
- `public/js/components/RaceView.js`
- `public/js/components/ResultsCard.js` (new)
- `public/js/components/Landing.js` (5.5 only)
- `public/index.html` (script tag for the new component)
- `public/css/main.css`
- `docs/stages/stage-5.md` (checkboxes)

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

## Ground facts (verified against the code — don't re-derive)

**Server (`api/index.php`)**

- The snapshots already carry everything the results UI needs — no snapshot
  change is required in this stage:
  - `room_state` → `roomSnapshot`: `{status, raceNo, race, names, participants, …}`;
    `race` is `raceSnapshot` — `{tick, t, n, finished, placements, racers,
    startedAt, finishedAt}` with slim `placements` `[{lane, place}]`; `names`
    is the racer nickname by lane.
  - `participants` rows: `{id, nickname, role, lane, prediction}` —
    `prediction` is a racer lane or `null`; spectators are included. Rows
    flagged `left` are already filtered out of `participants`, but keep their
    nickname in `names`, so a departed racer's placement still labels right.
  - practice snapshot: `{tick, t, n, finished, placements, racers, names,
    startedAt}` — no participants, no predictions.
- Rooms flip themselves: `catchUpRoomRace` sets `status: "finished"` +
  `finishedAt` once `finished >= n`; later polls keep the frozen frame.
- Tick files: `data/rooms/<roomId>/race-<raceNo>.ticks.jsonl.gz`
  (`RoomStore::appendTicks`). `raceNo` is **0-based** — note 4.1 leaves the
  bump to `rematch`, so the first race's file is `race-0.ticks.jsonl.gz`
  (spec §4's `raceNo: 1` example is illustrative). Only `rematch` increments
  `raceNo`.
- Locked read-modify-write exists: `RoomStore::mutate('rooms', $id, fn)`.
  `rematch` must run inside one, styled on the `set_prediction` case (errors
  via a `&$err` out-param) — review C1: every room lifecycle write goes
  through one lock.
- Error shape is `{"error": {"code", "message"}}` + HTTP status (spec §5).
  The `start_race` 404 `room_not_found` / 403 `not_host` blocks are the
  template for `rematch`.
- `history[]` (spec §4) is a **server-side archive only** in this stage —
  nothing in the UI reads it (§5 marks `history?` optional, so omitting it
  from the snapshot is contract-legal); the export stage (§7) surfaces it.

**Client (`public/js/`)**

- Modules are classic scripts sharing one global scope (order listed in
  `public/index.html`); top-level `const`s must not be redeclared. Register
  the new component's `<script>` before `RaceView.js`, which renders it (as
  `Lobby.js` sits before `JoinGate.js`).
- `RaceView` is the shared room + practice race screen (`props.roomId` for
  rooms, else practice; `props.navigate`). It already:
  - polls `room_state` / `practice_state` at ~500 ms and stops when
    `finished >= n` (`doneRef`, label *"The race is run"*);
  - snaps — never eases — across a snapshot jump (`accept()`: `data.tick <
    had.tick`, or a gap > 1.5 s), so a fresh race arriving at tick 0 needs
    no special case;
  - rebuilds the track when `n` changes.
- Reuse the globals: `RACERS[lane].color` / `RACERS[lane].name` and
  `ORDINAL[place - 1]` (`public/js/racers.js`), `api(action, body)`
  (`public/js/net.js`).
- Host check: `localStorage.getItem("chariot.host." + roomId)` — exactly as
  `Lobby.js` does (`isHost`, `startRace()`); `rematch` posts
  `{roomId, hostToken}` just like `start_race`.
- Landing's practice panel: `panel === "practice"`, 2–8 slider state
  `racers`, `ride()` → `create_practice` (stage 1 step 1.9).

## Steps

### 5.1 Results card (rooms)

New `public/js/components/ResultsCard.js`: a parchment card (`.stage > .card`
markup as in `Landing`) rendered **over** the frozen canvas — one overlay
block in `main.css`. Give it placements + names as props; no fetching inside.

Rooms: when the `room_state` snapshot has `status === "finished"`,
`RaceView` renders the card instead of merely labelling the finish. Heading
*"The Race Is Run"*, then placements in placing order (`race.placements`
sorted by `place`): `ORDINAL[place - 1]`, color swatch
(`RACERS[lane].color`), nickname (`names[lane]` — the swatch also
disambiguates duplicate names). The 500 ms race feed stops (the slow watch
added in 5.4 is then the only traffic).
Done when: placements match what was on track.

### 5.2 Seers block

Under the placements, one row per wagered participant in roster order —
racers and spectators alike (`prediction !== null`; players who never picked
are left out). Row: the backer's nickname, then the chariot they backed
(swatch + racer nickname `names[prediction]`). Winner = the `placements`
entry with `place === 1`; its backers get a gold tag *"true seer"*, every
other row is dim (one `.seer` / `.dim` pair in `main.css`).
Done when: correct and incorrect backers read differently at a glance.

### 5.3 Action `rematch`

New `rematch` case in `api/index.php`, styled on the `start_race` case and
run inside one `RoomStore::mutate` like `set_prediction` (archive + restart
+ save must be one locked write):

- POST body `{roomId, hostToken}`; reply `{ok: true}`.
- 404 `room_not_found` and 403 `not_host` — the `start_race` blocks verbatim.
- Status gate (pinned): `racing` → 409 `already_racing` ("The chariots are
  already upon the track."); `lobby` → 409 `race_not_finished` ("No race
  hath finished to re-run.").
- Roster: renumber lanes exactly as `start_race` does (non-`left` racers,
  lanes 0..n-1) — copy the block, do not refactor; <2 racers → 409
  `too_few_racers`. In the normal case this is the same n/lanes as the race
  just run. Predictions are **not** reset — a wager once sealed stands for
  the meeting.
- Archive first (spec §4 shape), then bump, then start:
  1. `history[]` += `{raceNo, seed, placements: [{lane, place, nickname}],
     predictions: [{nickname, pick, correct}], ticks:
     "race-<raceNo>.ticks.jsonl.gz"}` — `seed` copied from the stored race,
     placement nicknames from the lane→nickname map `roomSnapshot` builds
     (copy that block), `predictions` = every row with a non-null
     `prediction`, `correct` = `pick === winnerLane`. This entry uses the
     **current** `raceNo` (the number its tick file was written under).
  2. `raceNo++`.
  3. `RaceEngine::create(RaceEngine::prngNew(), n)` as `start_race` does
     (set `seed`, `startedAt`, `finishedAt: null`), `status: "racing"`,
     `updatedAt`.
Done when: after one rematch, the first race's tick file is intact and the
second race's ticks land in its own fresh `race-<new raceNo>.ticks.jsonl.gz`
(the first race is race 0 — see Ground facts).

### 5.4 Race again button

On the room results card: **Race Again** wired to
`api("rematch", {roomId, hostToken})` with `hostToken` from
`localStorage "chariot.host." + roomId` (same as `Lobby.startRace()`). Only
this browser's host sees it enabled; everyone else sees it disabled with the
caption *"the host alone may call the race"* (`not_host`'s exact words).
Surface API errors as `Lobby` does.

Success returns everyone to the live race view: the card goes away and the
500 ms feed resumes on the fresh race. Two halves:

- Host: on `{ok: true}`, hide the card, reset `doneRef` and the snapshot
  refs, restart the poll loop (a `restartRef` holding the effect's closure
  is enough).
- Everyone else (pinned): while a room card is up, keep a slow `room_state`
  watch (~2 s — it is the *fast* feed that "stops" in 5.1). When it reports
  `status === "racing"` again (new `raceNo`), tear the card down and resume
  the feed, same reset as the host.
Done when: the second race differs from the first but keeps the roster.

### 5.5 Practice results

Practice keeps it simpler: the same card, placements only (no seers block —
practice has no participants), and **Race Again** returns to the count
picker (stage 1 step 1.9): navigate to `/?panel=practice` and have `Landing`
read that query in its initial `panel` state (one-line `useState`) so the
2–8 slider opens straight away. No room, host or history involved and no
`rematch` call — the picker's *Ride!* starts a fresh practice race as always.
Done when: repeated practice races work without touching room code.

## Acceptance

- [ ] Results match what was on track (ordinals, nicknames, colors).
- [ ] Winner backers are tagged *true seer*; others are not.
- [ ] *Race Again* yields a different race for the same roster.
- [ ] Earlier races stay in `history` with their tick files.
- [ ] No Node/npm/npx introduced anywhere.

## Not in this stage

Export (deferred — `docs/spec-rooms.md` §7), custom tracks & racers
(spec-only — `docs/stages/stage-6.md`), TTL and docs (Stage 7). Also out of
scope here: review M2 (a join during results claiming a racer lane) and
surfacing `history` in the snapshot — neither blocks these steps.
