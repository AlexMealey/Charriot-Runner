# Commands that fail here — do not retry

Recorded so future stages don't burn attempts on them. The app runs only
inside Docker; the editing environment has neither a host PHP CLI nor Docker.

| Command                             | What happens                    | Why                                        |
| ----------------------------------- | ------------------------------- | ------------------------------------------ |
| `php …` (host CLI: `php -l`, `php -r`, …) | exit 127: `php: not found`  | No PHP on the host PATH.                   |
| `docker …`, `docker compose …`      | tool call is rejected outright  | Docker is unavailable at this stage of work. |

## Rules

- Do not retry these commands, and do not look for alternate ways to run
  them (wrappers, images, remote shells).
- PHP changes are verified **by reading** in the editor. Runtime checks
  (`curl`, browser) happen when the user runs `docker compose up` themselves.
