# Commands that fail here — do not retry

Recorded so future stages don't burn attempts on them. The app runs only
inside Docker; the editing environment has neither a host PHP CLI nor Docker.

| Command                             | What happens                    | Why                                        |
| ----------------------------------- | ------------------------------- | ------------------------------------------ |
| `php …` (host CLI: `php -l`, `php -r`, …) | exit 127: `php: not found`  | No PHP on the host PATH.                   |
| `docker …`, `docker compose …`      | tool call is rejected outright  | Docker is unavailable at this stage of work. |
| Any tool call issued in a parallel batch that contains an empty/malformed call | `tool input was not fully received` | The whole batch is rejected while one call is missing required args (e.g. `read_file` without `path`). |
| `grep` as part of a parallel batch (4×) | `tool input was not fully received` | Each batch carried a phantom empty `grep`; details in `docs/tool-errors.md`. |
| `grep` / ranged `read_file` (2026-10-10, repeated) | `Error parsing input JSON: EOF while parsing a value` | Payload truncated mid-argument; stop after two strikes, read whole files path-only (`docs/tool-errors.md`). |

## Tool-call errors (agent-side)

Tool errors are recorded here **and** in `docs/tool-errors.md`, which holds
the basic instructions for what to do when a tool call fails (do not repeat
the identical call; re-issue once as a single complete call; two failures →
stop and report).

## Rules

- Do not retry these commands, and do not look for alternate ways to run
  them (wrappers, images, remote shells).
- PHP changes are verified **by reading** in the editor. Runtime checks
  (`curl`, browser) happen when the user runs `docker compose up` themselves.
