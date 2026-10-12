# Stage 6 — Custom tracks & custom racers (spec + questions)

Status: **decided (2026-10-12)** — answers in §6.4; implementation is
[Stage 8](stage-8.md). No code in this stage.
Goal: agree on what *custom tracks* and *custom racers/horses with different
stats* mean — and leave the door open for **player-triggered abilities** as a
later option/alternative mode — before any implementation exists.

Progress (checked = written; 6.3/6.4 need the user):

- [x] 6.1 Spec draft: custom tracks
- [x] 6.2 Spec draft: custom racers/horses (stats)
- [x] 6.3 Open questions answered
- [x] 6.4 Decision log + implementation stage file

Files in play: **none** — this stage writes docs only. Code comes in a
follow-up stage created after 6.4.

> **Working rules — read first, keep to them**
>
> - This stage produces **no application code**. Do not build from the draft
>   below until the questions in §6.3 are answered and recorded in §6.4.
> - When implementation starts, the stack stays fixed: plain JS + JSX
>   (React UMD + Babel standalone), PHP 8.3, HTML/CSS. **Never Node.js /
>   npm / npx.**
> - The server stays authoritative and the sim stays deterministic: any
>   custom setup is part of the server-side race state, never client
>   opinion.
> - Anything not specified: pick the simplest option, note it in one line,
>   keep moving. UI copy keeps the medieval voice.

> **Replaces** the former Stage 6 (*Race export (MP4)*), which is removed.
> Export stays merely deferred in `docs/spec-rooms.md` §7 — nothing here
> touches it.

## Current state (what the draft builds on)

- **Track**: `buildTrack(w, h, n)` in `public/js/track.js` draws one oval
  with `n` lanes, its straights stretched to fill the window, and
  `lanePose` normalizes every lane to the same distance. The sim never
  sees the shape — only `p` (0..1 progress) — so today's track cannot
  affect a race at all.
- **Racers**: `api/lib/RaceEngine.php` draws everything from the seed:
  `rank` (shuffled), `surgeAmp` (0.10–0.22), `surgeW` (1.2–2.4),
  `surgePhase` — on top of shared constants `STEP = 1/60`,
  `BASE_SPEED = 0.12`, jitter ±0.025, rubber-band, and the endgame
  strength-from-rank curve. Apart from seed luck, all racers are identical.

## 6.1 Spec draft: custom tracks

> Draft for review (written 2026-10-12). **Superseded in part by §6.4:**
> courses are server-side SVG centre lines with exactly two lanes for any
> field size, so the n-lane presets, the per-lane segment tables and the
> lane-fairness options below no longer apply. Kept as the record of how
> the decision was reached.

### What a track is

- `track = {preset, laps, surface}` — plain data, validated server-side,
  stored in the race state like everything else the sim reads.
  - `preset`: `hippodrome` (default — today's oval) | `long_straight` |
    `tight_circuit`.
  - `laps`: 1 (default), 2 or 3 — the race distance.
  - `surface`: `dirt` (default) | `sand` | `cobbles`.
- **Lanes are not a track parameter.** The lane count is the race's `n`
  (riders at the start, or the practice picker's count), exactly as
  today. The earlier draft listed `lanes`; dropped — it duplicated `n`.
- **Laps, not a ½×–2× length multiplier** (changed from the earlier
  draft): the renderer fits the oval to the window, so a "2× longer"
  track would only look like slower chariots on the same oval. Laps read
  as a longer race and keep today's on-screen speed.
- No `track` anywhere (old rooms, old clients) ⇒
  `{hippodrome, 1, dirt}` ⇒ today's race, bit for bit.

### Presets v1

All three are **rounded polygons**: straights joined by circular turns,
lanes nested at a constant lane width. Today's oval is the two-straight
case, so one geometry routine (a list of straight and arc segments) can
draw all three, and `lanePose` walks that list instead of hard-coding the
stadium.

| Preset | Shape | Turns | Character |
| --- | --- | --- | --- |
| Hippodrome | stadium oval (today's) | 2 × 180° | balanced |
| Long Straight | stretched oval, wide turns | 2 × 180°, larger radius | straight-heavy |
| Tight Circuit | rounded rectangle, tight corners | 4 × 90°, small radius | turn-heavy |

Starting values, in lane widths, to tune on screen: Hippodrome as today
(inner-lane turn radius 1.8); Long Straight with straights ≈ 1.6× the
Hippodrome's and inner radius 3; Tight Circuit with long sides ≈ 0.6×,
short sides ≈ 0.3× and inner radius 1.2. Start/finish stays at the left
end of the top straight on every preset.

### Sim impact — two levels

**Level 0 — v1, classic races: only `laps` touches the sim.**

- One change in `RaceEngine::step`: the position increment is divided by
  the lap count — `p += max(0.03, v) * STEP / laps`. With `laps = 1` that
  divides by exactly 1, so every classic race replays bit-identically
  from its seed.
- `p` stays the **race** progress, 0 → 1. The finish (`p ≥ 1`), the
  hold-the-line rule (0.994), the endgame `smoothstep` over `p`, tick
  history, catch-up and interpolation are all unchanged.
- Only the renderer knows about laps: it draws each chariot at lap
  position `p × laps` (`lanePose` already wraps modulo the lane length),
  and the race view shows *"Lap 2 of 3"* for the leader.
- Duration scales with laps: ~8–10 s per lap, so ~25–30 s
  (≈ 1 600–1 800 ticks) at 3 laps. Still fine — the 900-step catch-up
  cap is per poll, not per race.
- Known side effect: surges run in seconds (`sin(t · surgeW)`) and jitter
  is drawn per tick, so a longer race sees more surge cycles and smoother
  noise. Acceptable; written down so nobody mistakes it for a bug.
- Shape and surface are **picture only** at this level.

**Level 1 — only together with 6.2's horses: the shape matters.**

6.2's horse table gives every horse a corner speed (*Turning*) and a
straight speed (*Gallop*), so the server must know, for any `p` and lane,
whether that chariot is on a straight or in a turn. Two consequences:

1. **The shape becomes canonical.** Today `buildTrack` (`public/js/track.js`)
   stretches the straights to fill the window
   (`straight = availW − 2·rOut − laneW`), so the oval differs per device.
   A shape that affects the race must be identical everywhere: each preset
   is defined in lane-width units and the renderer scales it **uniformly**
   to fit (letterboxed), never stretched.
2. **A segment table shared by server and renderer.** From the canonical
   geometry each lane gets `[{kind: "straight" | "turn", from, to}]` over
   one lap. The server looks it up per racer per tick (≤ 8 segments —
   cheap); `lanePose` walks the same table, so the picture and the sim
   always agree on where a chariot is.

**Lane fairness at Level 1** (feeds Q4). With nested lanes the outer lanes
spend far more of each lap in turns. Hippodrome, 8 lanes, straights of 24
lane widths, inner radius 1.8: the innermost lane is ≈ 19 % turn, the
outermost ≈ 54 %. At Level 0 this changes nothing — the sim ignores the
shape. At Level 1 it makes the lane matter for Turning-vs-Gallop horses,
and lanes currently go by **join order**. Options:

- (a) **Accept it** as part of the game: outer lanes favour good turners.
- (b) **One shared table** for all lanes (e.g. 35 % turn): fair by
  construction, but `lanePose` must then map `p` segment by segment, so
  on screen inner chariots crawl through the turns and outer ones whip
  round them.
- (c) **Draw lanes from the seed** at the start instead of join order:
  the lane advantage becomes luck, not punctuality.

Draft default: (c) + (a) — fair through the seed, natural picture.

### Data & API (for the implementation stage — not now)

- Rooms: `create_room {maxRacers, maxJoiners, track?}` → validated into
  `config.track`; anything off-list → 400 `bad_request`.
- `start_race` / `rematch` copy `config.track` into `race.track`, frozen
  like `race.names` / `race.riders`, so no track change can reach a
  running race.
- Practice: `create_practice {racers, track?}` → `track` on the practice
  doc.
- Snapshots: `race.track` (rooms) / `track` (practice) become public —
  `{preset, laps, surface}` holds no secrets. Tick lines are unchanged.

### Screens

- **Forge a Room** and **Ride the Practice Round**: a *Course* picker
  (three preset cards, each with a tiny drawn preview), *Laps* (1–3) and
  *Ground* (dirt / sand / cobbles).
- **Lobby**: one line under the share panel, e.g. *"The course: Tight
  Circuit, three laps upon cobbles"*. In v1 the course is fixed once the
  room is forged (changing it would need a new `set_track` host action —
  see Q3).
- **Race view**: `buildTrack(w, h, n, track)` draws the preset; the
  surface tints the track bed (dirt = today's colour, sand paler, cobbles
  a stone texture); a lap counter appears when `laps > 1`.
- **Results card**: the course named under *"The Race Is Run"*.

### Proposed answers to §6.3 A

| # | Draft answer |
| --- | --- |
| Q1 | Three presets + parameters; no track editor in v1. |
| Q2 | Level 0 now: only `laps` changes the race; shape and surface are picture-only. Level 1 (shape matters) arrives only with 6.2's horses. |
| Q3 | One track per race, the same for everyone. The host picks when forging the room, frozen into each race; the practice picker picks its own. Changing it between races is later work. |
| Q4 | Equal distance stays (`p` normalized per lane). At Level 1, option (c) + (a): lanes drawn from the seed. |

## 6.2 Spec draft: custom racers & horses (stats)

> Draft for review (written 2026-10-12). Built on the horse roster in the
> user's notes below, which replaces the earlier abstract stat block
> (`speed` / `surge` / `stamina` / `focus` under a points budget).
> **Superseded in part by §6.4:** the roster is rebalanced, stamina now
> recovers at half rate, the speed table is narrower, the drop-down
> defaults to *Random*, and horses run on two-lane courses (no fixed
> lanes).

### The horses (user's notes)

Each rider picks a horse from a drop-down in the lobby (the "prep page")
as people arrive, then clicks **Ready**; the host clicks **play** (*Raise
the Trumpet*) once everyone is ready.

| Stat | Meaning |
| --- | --- |
| Canter | the horse's regular cruising speed |
| Turning | its speed through the turns |
| Gallop | its speed along the straights |
| Stamina | how long it can hold a gallop |

| Horse | Canter | Turning | Gallop | Stamina |
|-------|--------|---------|--------|---------|
| Zebra | Slow | Normal | Fast | 150 |
| Fiery Tail | Fast | Fast | Fast | 100 |
| Thundercloud | Fast | Fast | Normal | 150 |
| Blazing | Fast | Normal | Fast | 100 |
| Brunellus | Fast | Normal | Fast | 100 |
| Purebred Bay | Normal | Normal | Normal | 150 |
| Night Charger | Fast | Normal | Fast | 100 |
| Sirocco | Slow | Normal | Normal | 150 |
| Purebred Bay Mare | Slow | Normal | Normal | 150 |
| Shadow | Slow | Normal | Fast | 100 |
| Phantom | Fast | Normal | Normal | 150 |

### Balance check (what the roster implies)

Scoring Slow / Normal / Fast as 0 / 1 / 2 and Stamina 100 / 150 as 0 / 1:

| Points | Horses |
| --- | --- |
| 6 | Fiery Tail, Thundercloud |
| 5 | Blazing, Brunellus, Night Charger, Phantom |
| 4 | Zebra, Purebred Bay |
| 3 | Sirocco, Purebred Bay Mare, Shadow |

- Only **Fiery Tail, Thundercloud and Zebra** trade one stat for another.
  Every other horse is matched or beaten on all four stats by one of
  them: Blazing, Brunellus, Night Charger and Shadow by Fiery Tail;
  Phantom, Purebred Bay, Sirocco and Purebred Bay Mare by Thundercloud.
- Identical stat lines: Blazing = Brunellus = Night Charger, and
  Sirocco = Purebred Bay Mare.
- Fine as a playful tier list, not as a fair contest — see Q6. The course
  (6.1) can only reshuffle the front three, through Gallop and Stamina:
  Long Straight favours Zebra's and Thundercloud's 150 stamina, Tight
  Circuit favours the Fast turners.

### How a horse runs — `custom` mode

Horses race only in a new race mode, `custom` (the `mode` flag reserved
below). `classic` races keep today's `create` and `step` untouched — same
draws, seeded `rank`, rubber band, hold-the-line — so old seeds replay
identically.

In `custom` mode the finishing order must **emerge** from the horses, so
the three rules that force today's seeded order are off: no `rank`, no
rubber band, no hold-the-line. Luck stays as today's seeded surge (at
half strength) and per-tick jitter.

Per racer, per tick — straight or turn comes from 6.1's Level 1 segment
table, so horses **require** Level 1:

```
in a turn:      speed = TURNING[turning];  stamina += 1   (a breather)
on a straight:  not blown and stamina > 0 → speed = GALLOP[gallop]; stamina −= 1
                otherwise                 → speed = CANTER[canter]; blown
v  = BASE_SPEED · speed · (1 + ½·surge + jitter)   // surge, jitter as today
p += max(0.03, v) · STEP / laps                    // laps: 6.1
```

- **Stamina** is counted in gallop ticks: 100 ≈ 1.7 s and 150 = 2.5 s of
  gallop, refilled one point per tick in the turns, never above the
  horse's own figure. A horse that runs dry is *blown*: it canters to the
  end of that straight instead of flickering between gallop and canter,
  and the next turn clears it.
- **Starting multipliers** of `BASE_SPEED` (tune on screen). The one hard
  rule: every Gallop beats every Canter, so galloping always pays.

  | | Slow | Normal | Fast |
  | --- | --- | --- | --- |
  | Canter | 0.90 | 0.96 | 1.02 |
  | Turning | 0.80 | 0.86 | 0.92 |
  | Gallop | 1.05 | 1.12 | 1.20 |

  The figures average near 1, so a horse race lasts about as long as a
  classic one.
- **Finish order**: by the tick each horse crosses `p = 1`; horses
  crossing in the same tick are ordered by how far into the tick each
  crossed, `(1 − p_before) / (p_after − p_before)`, then by lane. (Today's
  loop simply takes same-tick finishers in lane order.)
- **Deterministic**: seed + horses + track → outcome. Each lane's horse is
  frozen into the race at the start, like `race.names` / `race.riders`,
  so a replay needs nothing else.

### Data & API (for the implementation stage — not now)

- `RaceEngine::HORSES`: the roster keyed by slug (`zebra`, `fiery_tail`,
  …, `purebred_bay_mare`) with its four stats — the single source of
  truth. Clients read it from a small `horses` GET action rather than a
  JS copy that could drift.
- `create_room {…, mode?}` → `config.mode`: `classic` (default) |
  `custom`.
- Participants gain `horse` (slug or `null`) and `ready` (bool), both
  carried in the roster snapshot.
- `set_horse {roomId, token, horse}` — lobby only, riders only; refused
  while that rider is ready (409 `horse_locked`); unknown slug → 400.
- `set_ready {roomId, token, ready}` — lobby only, riders only;
  `leave_room` clears it, a re-attach keeps the horse.
- `start_race` in `custom` rooms: every non-left rider ready, else 409
  `riders_not_ready`; a rider with no pick rides Purebred Bay (all
  Normal). Engine racers gain `horse`, `stamina`, `blown` — server-only,
  stripped by `slimRacers`; `race.horses` (slugs by lane) is public, like
  `race.names`.
- `rematch`: horses carry over — a rematch skips the lobby (Q9).
- Practice: `create_practice {racers, track?, mode?}`; in `custom` mode
  each lane's horse is drawn from the seed.

### Screens

- **Forge a Room**: *Rules of the race* — "Classic (the gods decide)" or
  "Horses (the riders decide)".
- **Lobby (`custom`)**: on thine own row a *Thy steed* drop-down (name +
  four stat pips) and a **Ready** toggle that locks the drop-down. Every
  row shows the rider's horse and a ✓ once ready. The trumpet needs ≥ 2
  riders, all ready; otherwise its reason reads *"the riders are not all
  ready"*. Spectators pick no horse but still back a winner — now an
  informed wager.
- **Race view**: legend and standings read *"Swift Badger · Fiery Tail"*.
- **Results card**: the horse under each placing.

### Proposed answers to §6.3 B

| # | Draft answer |
| --- | --- |
| Q5 | Your four horse stats: Canter, Turning, Gallop, Stamina. The earlier `speed`/`surge`/`stamina`/`focus` block is dropped. |
| Q6 | A fixed roster, no points budget. Keep the tier list as written for v1; rebalance after playtests if Fiery Tail takes every race. |
| Q7 | Each rider picks their own horse in the lobby, then readies. Duplicates allowed. No pick ⇒ Purebred Bay. 7a: the host cannot start until all are ready (a rider can always be asked to leave). |
| Q8 | Horses only in `custom` rooms, chosen when forging; classic untouched. 8a: practice draws horses from the seed. |
| Q9 | Shown in the lobby, race view and results; carried over to rematches. |
| Q10 | Horse names only in v1; each chariot keeps its lane colour. |

### Reserved: interactive abilities (the future option/alternative mode)

The stat system is meant to grow into a mode where players act during the
race (urge the horses, rein in, rally). **Reserve, do not build:**

- API action `race_action {ability}` — validated server-side (cooldown,
  legality) and applied by the sim at the next tick; fits the existing
  250 ms polling loop as-is.
- Race state gains a per-racer `abilities` block (charges/cooldowns).
- A `mode` flag on the race: `classic` (today, seed-only) vs `custom`
  (the horses of §6.2 now, abilities later), so classic races are
  untouched by any of this.

## 6.3 Open questions (block until answered)

**A — Tracks**

1. Presets + parameters, or a real track editor? (v1 suggestion: three
   presets + parameters.)
2. Does a track change the **race** (length/surface nudging speed) or only
   the **picture**?
   - 2a. Race distance as **laps** (1–3) instead of a ½×–2× length
     multiplier? (§6.1 explains why.)
3. Confirm one track per race, same for everyone — host picks (rooms),
   picker picks (practice)?
4. Must lanes stay strictly equal-distance, or may a preset make some
   lanes harder?
   - 4a. Once the shape matters (Level 1, needed by 6.2's Turning/Gallop),
     which lane-fairness option: (a) accept, (b) one shared segment
     table, or (c) lanes drawn from the seed? (§6.1, *Lane fairness*.)

**B — Racers & horses**

5. Are the four horse stats (Canter, Turning, Gallop, Stamina) right — and
   is the earlier `speed` / `surge` / `stamina` / `focus` block dropped?
6. Balance: 8 of the 11 horses are matched or beaten on every stat by
   Fiery Tail, Thundercloud or Zebra, and two groups share identical
   stats (§6.2, *Balance check*). Keep the roster as a tier list, or
   rebalance so every horse trades something?
   - 6a. Starting multipliers and stamina-in-ticks (§6.2, *How a horse
     runs*) — fine as a first tuning, or do you have target numbers
     (e.g. "Fast gallop ≈ 20 % faster than Normal canter")?
7. Who picks: each rider their own horse in the lobby (as the notes say)?
   May two riders pick the same horse? Which horse if none is picked?
   - 7a. Ready: if a rider never readies, may the host start anyway?
8. Horses in every room, or only in a `custom` mode the host chooses when
   forging (classic untouched)?
   - 8a. Practice: horses drawn from the seed, or picked per lane in the
     practice picker?
9. Where are horses shown (lobby, race view, results), and do they carry
   over to rematches?
10. Cosmetics: horse names only, or per-horse art/colour beyond today's
    lane swatch?

**C — Interactive abilities (the future mode)**

11. Is ≤ ~250 ms press granularity (one poll window) acceptable, or does
    that mode need a faster loop?
12. One ability per racer, or a small loadout chosen pre-race? Cooldowns
    server-side only?
13. Confirm: abilities live only in `custom` mode; `classic` stays exactly
    as today?
14. Do spectators participate (cheers/nudges), or watch only?

## 6.4 Decision log & follow-up

Answers given by the user on 2026-10-12. Where a question got no answer,
the draft's proposed answer stands and is marked *(draft default)* —
overrule any of them before Stage 8 reaches that step.

| # | Question | Decision |
| --- | --- | --- |
| A1 | Presets or editor | Courses are **SVG centre lines kept on the server** (`api/tracks/`). The backend plots each one into a polyline, its length and a straight/turn table; clients draw from that plot. v1 ships three loops — Hippodrome, Long Straight, Tight Circuit. No in-app editor. |
| A2 | Race or picture | Race: the course decides where a horse turns or gallops. Surface stays picture-only and is not in v1. |
| A2a | Laps | The host picks the number of laps when the course loops (1–3, *draft default range*); an open course is always run once. |
| A3 | One course per race | Yes. The host picks course and laps when forging the room; both are frozen into each race. The practice picker picks its own *(draft default)*. |
| A4 / 4a | Lanes and fairness | Every course has **exactly two lanes**, whatever the field size. All horses race the same centre line — one shared straight/turn table, fair by construction. The lane is visual: a horse switches lanes to overtake and, if blocked, passes through. Start: a grid two abreast, rows going back in pairs, in **join order**; the grid gap is visual only and closes over the opening straight, so the starting slot never decides the race. |
| B5 | Stats | Canter, Turning, Gallop, Stamina (the user's roster). |
| B6 | Balance | Rebalanced — see *Rebalanced roster* below: new stamina for every horse; speed words nudged on the duplicates (Blazing, Brunellus; Sirocco) and on Shadow, which shared Zebra's words. |
| B6a | Tuning numbers | The model's numbers below are starting values; Stage 8's balance tool re-checks them on the real courses with seeded luck. |
| B7 | Who picks | Each rider in the lobby: *Thy steed* drop-down defaulting to **Random** (resolved from the race seed at the start), or one of the eleven. Duplicates allowed *(draft default)*. |
| B7a | Unready riders | The trumpet needs every rider ready. After **30 s** the host may force the start; unready riders ride their current pick (Random if none). |
| B8 | Mode | Horses are the new mode, chosen by the host when forging. Classic rooms are unchanged — today's oval, lanes and seeded order. |
| B8a | Practice | Horses drawn from the seed per racer *(draft default)*. |
| B9 | Shown where / rematch | Lobby, race view and results; horses carry over to rematches *(draft default)*. |
| B10 | Cosmetics | Every horse is drawn with the user's horse SVG (below), one colour per rider. |
| C11–14 | Abilities | Deferred — abilities stay reserved (§6.2) and are not part of v1; the questions reopen when that mode is scheduled. |

**The horse sprite** (user-supplied; goes into `public/js/racers.js`):

```svg
<svg viewBox="0 0 100 100" fill="currentColor"><path d="M24 92H76V84H24ZM32 82L42 60H30L18 52L28 32L42 18L46 6L54 16L66 28L72 52L68 82Z"/><path d="M42 18L54 34L72 52M28 32L54 34L42 60" fill="none" stroke="#fff" stroke-width="1.5"/><circle cx="44" cy="28" r="2.5" fill="#fff"/></svg>
```

### Rebalanced roster

How a horse runs (replaces the §6.2 draft numbers):

- Speeds are multiples of `BASE_SPEED`, narrower than the draft so that
  stamina matters more:

  | | Slow | Normal | Fast |
  | --- | --- | --- | --- |
  | Canter | 0.93 | 0.96 | 0.99 |
  | Turning | 0.85 | 0.88 | 0.91 |
  | Gallop | 1.11 | 1.15 | 1.19 |

- Stamina is a pool of gallop ticks: −1 per tick galloping, **+½ per tick
  otherwise** (turns and canter), never above the horse's figure. A horse
  that runs dry is blown — it canters to the end of that straight. Half-rate
  recovery makes stamina carry across laps, so it keeps paying in longer
  races instead of topping out after one straight.

| Horse | Canter | Turning | Gallop | Stamina | Was |
| --- | --- | --- | --- | --- | --- |
| Zebra | Slow | Normal | Fast | 90 | stamina 150 |
| Fiery Tail | Fast | Fast | Fast | 40 | stamina 100 — burns bright, briefly |
| Thundercloud | Fast | Fast | Normal | 50 | stamina 150 |
| Blazing | Normal | Slow | Fast | 140 | Fast / Normal / Fast, 100 |
| Brunellus | Normal | Normal | Fast | 80 | Fast / Normal / Fast, 100 |
| Purebred Bay | Normal | Normal | Normal | 150 | unchanged — the yardstick |
| Night Charger | Fast | Normal | Fast | 60 | stamina 100 |
| Sirocco | Slow | Slow | Fast | 160 | Slow / Normal / Normal, 150 |
| Purebred Bay Mare | Slow | Normal | Normal | 180 | stamina 150 |
| Shadow | Slow | Fast | Normal | 100 | Slow / Normal / Fast (Zebra's words) |
| Phantom | Fast | Normal | Normal | 80 | stamina 150 |

Why Shadow moved too: stamina only ever helps in this model, so two horses
with the same speed words cannot be balanced by stamina alone — the one
with more stamina simply wins.

**Modelled finish times** (seconds; no surge or jitter; course shapes
assumed: Hippodrome 35 % turns in two bends, Long Straight 20 % in two,
Tight Circuit 50 % in four):

| Horse | Hipp ×1 | Long ×1 | Tight ×1 | Hipp ×3 | Long ×3 | Tight ×3 |
| --- | --- | --- | --- | --- | --- | --- |
| Zebra | 8.35 | 8.28 | 8.30 | 25.05 | 24.85 | 25.14 |
| Fiery Tail | 8.41 | 8.30 | **8.25** | 25.22 | 24.89 | **24.75** |
| Thundercloud | 8.41 | 8.30 | 8.32 | 25.22 | 24.89 | 24.99 |
| Blazing | 8.31 | **8.16** | 8.40 | 25.07 | 24.60 | 25.43 |
| Brunellus | 8.32 | 8.20 | 8.31 | **24.95** | 24.60 | 25.10 |
| Purebred Bay | 8.30 | 8.20 | 8.36 | 25.07 | 24.74 | 25.31 |
| Night Charger | 8.38 | 8.22 | 8.34 | 25.15 | 24.67 | 25.08 |
| Sirocco | 8.27 | 8.22 | 8.40 | 25.18 | 24.85 | 25.41 |
| Purebred Bay Mare | **8.23** | 8.24 | 8.36 | 25.18 | 24.98 | 25.29 |
| Shadow | 8.34 | 8.32 | 8.27 | 25.06 | 24.99 | 25.04 |
| Phantom | 8.35 | 8.20 | 8.42 | 25.06 | **24.59** | 25.38 |
| *spread* | *2.1 %* | *2.0 %* | *2.1 %* | *1.1 %* | *1.6 %* | *2.8 %* |

The original roster spread 6–9.5 % per course and Fiery Tail won all six;
now six different horses take the six conditions, and course plus laps
decide who is favoured.

**Follow-up:** [Stage 8 — Horses mode](stage-8.md) implements all of the
above.

## Acceptance

- [x] Every question in §6.3 has an answer recorded in §6.4 (C11–14:
      deferred with abilities).
- [x] A follow-up implementation stage file exists —
      [stage-8.md](stage-8.md).
- [x] No application code written from this draft before §6.4.
- [x] No Node/npm/npx introduced anywhere.

## Not in this stage

Any code, and export (deferred — `docs/spec-rooms.md` §7).
