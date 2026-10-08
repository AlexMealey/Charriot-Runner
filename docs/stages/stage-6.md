# Stage 6 — Race export (MP4) — DEFERRED

Status: **deferred — not a core requirement**
Goal (when resumed): one finished race becomes a downloadable real **MP4**.

Progress (see steps below; all deferred until unblocked, none started):

- [ ] 6.1 Study the reference
- [ ] 6.2 Worker + wiring
- [ ] 6.3 API actions
- [ ] 6.4 UI

**Blocked on:** a reference exporter implementation from another project that
this one will **mimic**. Do not start this stage until that reference is
provided.

> **Working rules — read first, keep to them**
>
> - Do the steps below **in order, one at a time**. Stop a step when its
>   "Done when" line is true. Do not gold-plate.
> - Stack is fixed: plain JS + JSX (React UMD + Babel standalone), PHP 8.3,
>   HTML/CSS. **Never Node.js / npm / npx** — the exporter included.
> - The reference implementation decides the exporter's shape (language,
>   pipeline, job handling). Mimic it; do not invent a parallel design.

## Fixed constraints (agreed)

- Output: real **H.264 MP4**, built from the stored tick history of a
  finished race (`race-<n>.ticks.jsonl.gz`, `docs/spec-rooms.md` §3).
- Reserved API surface: `request_export` / `export_status` /
  `export_download` (`docs/spec-rooms.md` §5); job file shape in §4.
- Until then the results screen reserves the *Export race* button slot
  (`docs/spec-rooms.md` §6) — tick history is already fully written by
  Stage 1/4, so nothing must be re-simulated later.

## Steps (take only once unblocked)

### 6.1 Study the reference

Read the provided exporter implementation; note its job intake, rendering
pipeline, and output handling in 5–10 lines before writing code here.

### 6.2 Worker + wiring

Add the exporter following the reference (service/container layout included),
fed by `data/export-jobs/<jobId>.json`, writing `data/exports/<jobId>.mp4`.

### 6.3 API actions

Implement `request_export` (writes the job file), `export_status` (queued /
rendering / done / error + download URL), `export_download` (MP4 stream,
`status: "done"` only).

### 6.4 UI

Results screen: *Export race* button → progress line (*"The scribes
illuminate…"*) → download link. Rooms and practice both.

## Acceptance (when resumed)

- [ ] A finished ~10 s race exports a playable H.264 MP4.
- [ ] Downloadable from the results screen while the app keeps running.
- [ ] No Node/npm/npx introduced anywhere.
