# Tool errors — basic instructions

Record every failing tool call here (and as a row in
`docs/failed-commands.md`). Keep entries short: what failed, why, what worked.

## Rules

1. **Never repeat the identical call.** A tool that returned an error will
   fail the same way again — blind retries burn attempts.
2. **Halve the batch.** An error like `tool input was not fully received`
   means one call in a parallel batch was empty or missing required args,
   which rejects the whole batch. Re-issue as a **single** call with every
   required argument filled in.
3. **Two strikes → stop.** If the single re-issue fails too, stop, log it
   (below and in `failed-commands.md`), and tell the user instead of trying
   a third time.
4. **Record before moving on.** Append the log entry first, then continue
   the task — the record is the deliverable of a failure.

## Log

| Date | Tool | Error | Cause | Fix |
| --- | --- | --- | --- | --- |
| 2026-10-09 | `edit_file` | `No tool named edit_file exists` | Tool set changed mid-session (tool may have been disabled/renamed) | Do not retry `edit_file`; use `write_file` (full-file rewrite) for records until it returns |
| 2026-10-09 | `write_file` | `No tool named write_file exists` (repeated) | Same tool-set change as `edit_file` | No write tools → record the entries in the reply to the user; delegate file writes to a sub-agent; retry file records once tools return |
| 2026-10-09 | `read_file` with `start_line`/`end_line` | `tool input was not fully received` (repeated; ranged reads fail where path-only reads succeed) | Same phantom empty second call; ranged content never returned | Read whole files (path-only) when small; `grep` snippets or report the limitation for large files |
| 2026-10-09 | `spawn_agent` | `tool input was not fully received` | Phantom empty second call in batch (same pattern as `read_file`) | One tool call per turn; the batch with one full + one empty call fails entirely |
