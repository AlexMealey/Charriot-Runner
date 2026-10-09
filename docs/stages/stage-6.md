# Stage 6 — Custom tracks & custom racers (spec + questions)

Status: **spec draft — no code; blocked on the answers in §6.3**
Goal: agree on what *custom tracks* and *custom racers/horses with different
stats* mean — and leave the door open for **player-triggered abilities** as a
later option/alternative mode — before any implementation exists.

Progress (checked = written; 6.3/6.4 need the user):

- [ ] 6.1 Spec draft: custom tracks
- [ ] 6.2 Spec draft: custom racers/horses (stats)
- [ ] 6.3 Open questions answered
- [ ] 6.4 Decision log + implementation stage file

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

- **Track**: `buildTrack(w, h, n)` in `public/index.html` draws one oval
  with `n` lanes, normalized so every lane races the same distance. The sim
  never sees the shape — only `p` (0..1 progress) — so today's track cannot
  affect a race at all.
- **Racers**: `api/lib/RaceEngine.php` draws everything from the seed:
  `rank` (shuffled), `surgeAmp` (0.10–0.22), `surgeW` (1.2–2.4),
  `surgePhase` — on top of shared constants `STEP = 1/60`,
  `BASE_SPEED = 0.12`, jitter ±0.025, rubber-band, and the endgame
  strength-from-rank curve. Apart from seed luck, all racers are identical.

## 6.1 Spec draft: custom tracks

- **Track = named preset + parameters**, chosen when a room is created or a
  practice race is set up; stored in `config.track` (rooms) / the practice
  request.
  - Presets v1 (three): *Hippodrome* (today's oval), *Long Straight* (few,
    wide turns), *Tight Circuit* (many short turns).
  - Parameters: `lanes` (2–8, already runtime `n`), `length` (race-distance
    multiplier, 0.5×–2×), `surface` (`dirt` | `sand` | `cobbles` — visual
    now, mechanical later).
- **Sim impact kept tiny on purpose**: one `length` factor scales
  `BASE_SPEED` (longer track ⇒ slower progress per tick). Shape stays a
  renderer concern; `p` stays normalized, so tick history, replay, and
  interpolation remain valid unchanged.
- **One track per race, same for everyone.** Host picks in rooms; the
  practice picker picks its own (per-racer track choice = open question Q2).
- **Renderer**: `buildTrack` gains preset branches; the equal-distance
  per-lane normalization rule stays.

## 6.2 Spec draft: custom racers & horses (stats)

- **Racer = participant + stat block**, chosen before the start (lobby for
  rooms, picker for practice):
  - `speed` — multiplies `BASE_SPEED`.
  - `surge` — scales `surgeAmp`/`surgeW` (punchiness of the bursts).
  - `stamina` — resists rubber-banding and the late-race fade.
  - `focus` — scales down the PRNG jitter.
- **Bounded budget**: total stat points capped (e.g. 10 across the four
  stats) so custom racers stay balanced against each other.
- **Server-side only**: stats enter the race state at `create`; the client
  never computes them. Determinism preserved: `seed + stats → outcome`, and
  one seed still suffices for replay.
- **Seed draws are the defaults**: no stats supplied ⇒ today's behavior
  exactly — classic races never change.
- **Cosmetics** (name, horse/chariot color) attach to the same record —
  layout only, no stat effect.
- **Fairness knob**: whether custom stats are legal in competitive rooms
  from day one, or first restricted to practice/custom mode (Q8) — the mode
  flag below covers both.

### Reserved: interactive abilities (the future option/alternative mode)

The stat system is meant to grow into a mode where players act during the
race (urge the horses, rein in, rally). **Reserve, do not build:**

- API action `race_action {ability}` — validated server-side (cooldown,
  legality) and applied by the sim at the next tick; fits the existing
  250 ms polling loop as-is.
- Race state gains a per-racer `abilities` block (charges/cooldowns).
- A `mode` flag on the race: `classic` (today, seed-only) vs `custom`
  (stats + abilities), so classic races are untouched by any of this.

## 6.3 Open questions (block until answered)

**A — Tracks**

1. Presets + parameters, or a real track editor? (v1 suggestion: three
   presets + parameters.)
2. Does a track change the **race** (length/surface nudging speed) or only
   the **picture**?
3. Confirm one track per race, same for everyone — host picks (rooms),
   picker picks (practice)?
4. Must lanes stay strictly equal-distance, or may a preset make some
   lanes harder?

**B — Racers & stats**

5. Are the four stats (`speed`, `surge`, `stamina`, `focus`) right? Cut,
   add, rename?
6. Fixed budget with trade-offs, or free choice within per-stat caps?
7. Who assigns stats: each player their own racer in the lobby, host
   assigns, or random draw?
8. Legal in competitive rooms immediately, or practice/custom mode first?
9. Where are stats visible (lobby card, results), and do they persist
   across rematches?
10. Cosmetics: do horses get their own name/art beyond today's color
    swatch?

**C — Interactive abilities (the future mode)**

11. Is ≤ ~250 ms press granularity (one poll window) acceptable, or does
    that mode need a faster loop?
12. One ability per racer, or a small loadout chosen pre-race? Cooldowns
    server-side only?
13. Confirm: abilities live only in `custom` mode; `classic` stays exactly
    as today?
14. Do spectators participate (cheers/nudges), or watch only?

## 6.4 Decision log & follow-up

Answers from §6.3 land here as a short table; only then write the
implementation stage from it (a new Stage 8, or an expanded 6.x).

| # | Question | Decision |
| --- | --- | --- |
| — | (filled in after §6.3) | |

## Acceptance

- [ ] Every question in §6.3 has an answer recorded in §6.4.
- [ ] A follow-up implementation stage file exists — or the stage is
      explicitly shelved.
- [ ] No application code written from this draft before §6.4.
- [ ] No Node/npm/npx introduced anywhere.

## Not in this stage

Any code, and export (deferred — `docs/spec-rooms.md` §7).
