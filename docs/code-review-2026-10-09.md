# Code review — findings vs. spec (2026-10-09)

Status: **open — nothing fixed yet**; this is the review output, to be worked
off stage by stage.
Reviewed: `api/index.php`, `api/lib/RoomStore.php`, `api/lib/RaceEngine.php`
(full), `public/index.html` (outline + code segments — see caveat), against
`docs/spec-rooms.md` and `docs/stages/stage-1..5.md`.

Verdict: the PHP sim port is faithful (`mulberry32`, shuffle, surge/jitter/
band/endgame/hold-line constants match the JS originals exactly). The
problems cluster around concurrency, lifecycle, and contract details.

Caveat: ranged `read_file` calls failed repeatedly (see `docs/tool-errors.md`),
so `RaceView`/`JoinGate`/`Lobby` internals were reviewed via outline + code
snippets. A follow-up pass on `public/index.html` L427–600 and L904–1000 is
needed when tooling recovers.

---

## Critical

### C1. Read-modify-write is not atomic — tick history will corrupt

`practice_state` (`api/index.php` L256–291) does `get → step → appendTicks →
put` with no lock spanning the operation. Two polls in flight (second tab,
reload, Stage 4 multi-client rooms) both load the same doc, both step it, both
append tick lines → duplicated/interleaved ticks and lost steps (last `put`
wins). `RoomStore::appendTicks` (L63–78) has no lock at all; `put`'s flock
protects only the individual write.

Fix: add `RoomStore::mutate(string $kind, string $id, callable $fn)` that
holds `LOCK_EX` on the `.lock` sidecar across read → modify → write (tick
appends inside the same lock). Stage 4.2's `room_state` catch-up must use it
— the same pattern is copied there and will corrupt room tick files the same
way.

### C2. Tick history grows forever after the finish

When all racers are done, `RaceEngine::step` still increments `tick`/`t`
(L105–106), and `practice_state` keeps appending frozen tick lines on every
late poll (reload = more lines).

Fix: stop catch-up when `$doc['finished'] >= $doc['n']`, stamp `finishedAt`,
skip `appendTicks` when nothing advanced. Same guard required in Stage 4.2
(spec §5: *"When all are done: set `status: "finished"`"*).

### C3. `leave_room` deletes the participant — contradicts spec §11 and Stage 4.9

`api/index.php` L221 removes the row entirely. Spec §11 says a mid-race
leaver's chariot finishes as a ghost and the roster marks them *(departed)* —
impossible once the row is gone. A quitter's cached id/token (spec §9,
stage 3.6) can then never be re-honored (Stage 4.9 requirement), and closing
the tab *without* `leave_room` then rejoining creates a duplicate row for the
same person (two seats, two lanes).

Fix: the `left: true` design from Stage 4.9 resolves all three at once —
counts ignore `left` participants; `join_room` re-attaches on the cached
`{participantId, token}`.

## Moderate

### M1. Silent write failures lie to the client

`RoomStore::put` (L22–45) returns `void` and silently drops on bad id, lock
failure, or encode failure; `join_room` then replies with a participant/token
that was never persisted.

Fix: return `bool` from `put`/`saveRoom`; answer 500 `storage_error` when a
required write fails.

### M2. Joins while `status: "finished"` get racer lanes

`join_room` (L155) excludes racer roles only when `status !== "racing"` — a
joiner during the results phase claims a freed lane and enters the next
`rematch` as a racer. Spec §2: joiners *after the start* are spectators.

Fix: treat `finished` like `racing` (spectator) — or record the
deliberate alternative (late arrivals race in the rematch) in spec §11.

### M3. `create_practice` silently clamps input

L244 `max(2, min(8, …))` clamps; `create_room` returns 400 `bad_request`.
Pick one style — validate and 400 for spec §5 consistency.

### M4. `create_room` allows `maxJoiners < maxRacers`

L96–99 misses the relation; spec §2 defines max joiners as racers +
spectators. Add the condition.

### M5. Error shape drifts on unknown actions

`api/index.php` L295 returns `{"error": "string"}` instead of spec §5's
`{"error": {"code", "message"}}`. Every other case is correct.

## Minor / notes

- **Snapshot gaps vs. §5:** `roomSnapshot` (L24–43) omits `history` and the
  top-level `tick` §5 lists (`{status, tick, participants, race?, history?}`)
  — fine now, needed by Stage 5.
- **Placements leak sim internals:** `RaceEngine` L144 pushes the full racer
  (incl. `rank`, `surgeAmp`, `surgeW`, `surgePhase`) into `placements`, which
  reaches snapshots and tick files. Slim to `{lane, place}` (+ nickname at
  render).
- **Race state shape vs. §4:** spec's `race` block is
  `{seed, prngState, racers: [{lane, p, done, place}], …}` with `seed`
  "opaque base64"; the engine returns richer state and an int `prng`. Decide
  in 4.1: expose the spec shape as a projection (recommended — engine state
  stays private) or amend §4.
- **`finished` is a count, not a boolean** (`RaceEngine` L88/142) — truthy
  for `n ≥ 2` by accident of naming. Expose `finishedAll: bool` or rename.
- **CORS `Access-Control-Allow-Origin: *`** (index.php L46) is wide open vs.
  spec §9's spirit; dropping it (same-origin app) costs nothing.
- **`validId` (RoomStore L125) accepts any hex length** — enforce 32-hex
  (rooms) / 16-hex (practice) per §2/§3.
- **Mixed time formats:** `updatedAt`/`joinedAt` are ISO strings, `startedAt`
  is `microtime(true)` float. Fine for now (Stage 7.1 GC: "mtime or
  `updatedAt`"), but be consistent when GC lands.
- **Dead code:** `mulberry32`, `seededRandom` (calls `action=seed`),
  `createRace`, `stepRace` in `public/index.html` (L28–245) are superseded by
  the PHP engine; Stage 7.5 schedules removal. Before that, verify nothing
  references them (`begin()` L556 is the one to check) — no second, drifting
  sim.

## Work-off checklist

- [ ] C1 `RoomStore::mutate` lock across read-modify-write (+ `appendTicks`)
- [ ] C2 Finish guard: stop catch-up/tick writes after the race ends
- [ ] C3 `left: true` leave/rejoin (implement Stage 4.9 as written)
- [ ] M1 `put`/`saveRoom` return success; 500 on required-write failure
- [ ] M2 Join-after-finish role rule
- [ ] M3/M4 Input validation (`create_practice`, `maxJoiners >= maxRacers`)
- [ ] M5 Unknown-action error shape
- [ ] Minor items decided in the relevant stage steps
- [ ] Follow-up frontend pass (RaceView L427–600, JoinGate L904–1000)
