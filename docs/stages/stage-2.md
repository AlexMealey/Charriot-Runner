# Stage 2 — Landing page & routing

Status: **done** (2026-10-08)
Goal: `/` is a landing page; query-param routing reaches practice, the room
join gate, and the dust scroll.

Progress (see steps below; checked = done and reviewed):

- [x] 2.1 Query-param routing
- [x] 2.2 Landing screen
- [x] 2.3 Nickname step with the dice
- [x] 2.4 Dust scroll + housekeeping

Files in play: `public/index.html`, `public/css/main.css`.

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

## Steps (all complete — kept as a record)

### 2.1 Query-param routing — done

`parseRoute()` over `URLSearchParams`: `/` → landing; `/?room=<32-hex>` →
join gate (bad mark → dust); `/?practice=<id>` → race view; both params or a
malformed mark → dust. `App` handles `pushState` + `popstate`.
Done when: back/forward switch views without reloads.

### 2.2 Landing screen — done

Parchment card: title **CHARIOT RACING**, tagline, and three buttons —
*Create a Room* (form: chariots 2–8 slider + souls 2–16), *Join a Room* (room
mark input, accepts a pasted link), *Practice*.
Done when: `/` renders the card in the medieval theme.

### 2.3 Nickname step with the dice — done

`/?room=<id>` join gate: name field prefilled from `wordName()` (adjective +
noun lists), ⚄ dice button rerolls, *Join the Race* → `join_room` (failure →
notice; `room_not_found` → dust). Enter submits.
Done when: the dice always yields a two-word name.

### 2.4 Dust scroll + housekeeping — done

Dust card ("This Scroll Hath Turned to Dust") with *Return to the Gates*;
`◂ The Gates` link on non-landing views; rAF loop + listeners cleaned up on
view unmount so navigation mid-race cannot leak a running loop.

## Acceptance (met)

- [x] `/` shows the landing page.
- [x] Practice is reachable end-to-end from it (currently the local sim;
      Stage 1 moves it to the backend).
- [x] `/?room=deadbeef…` shows the fallback card.
- [x] No Node/npm/npx introduced anywhere.

## Not in this stage

Room creation/join against the API (Stage 3 wires the buttons that today show
the raw API error), lobby, races from the backend, results, export, TTL.
