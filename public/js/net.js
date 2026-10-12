/* ------------------------- Shared client helpers -------------------- */
/* One medieval line per API refusal (7.3): the notice a known code shows,
 * whatever the server said. Codes not listed keep the server's message —
 * already in voice, and sometimes carrying a count (riders_not_ready). */
const ERROR_LINES = {
  room_not_found: "This room hath turned to dust.",
  room_full: "The hippodrome is full — every seat is taken.",
  not_host: "The host alone may call the race.",
  too_few_racers: "Two riders at least.",
  already_racing: "The chariots are already upon the track.",
  race_started: "The race hath begun — that choice is sealed.",
  race_not_finished: "No race hath finished to run again.",
  practice_not_found: "This practice race hath turned to dust.",
  not_a_participant: "Thou art not of this room.",
  storage_error: "The scribes could not set it down — try once more.",
  server_error: "The sages stumbled — try once more.",
};

/* Tiny JSON POST helper for the PHP action router.                    */
async function api(action, body) {
  const res = await fetch("api/?action=" + action, {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify(body || {}),
  });
  let data = null;
  try {
    data = await res.json();
  } catch (e) {
    data = null;
  }
  if (!res.ok) {
    // only the error envelope's words ever reach a notice — never raw text
    const raw = data && typeof data.error === "object" ? data.error : null;
    const message =
      (raw && ERROR_LINES[raw.code]) ||
      (raw && raw.message) ||
      "The sages return no answer (" + res.status + ").";
    const err = new Error(message);
    if (raw && raw.code) err.code = raw.code;
    throw err;
  }
  return data;
}

/* The wavering-link line (4.8): two consecutive failed polls raise a dim
 * notice, the next answer lowers it. Counted here so every short-poller
 * reports one shared link — the notice itself is pure CSS (body.waver). */
let waverMisses = 0;
function waver(ok) {
  waverMisses = ok ? 0 : waverMisses + 1;
  document.body.classList.toggle("waver", waverMisses >= 2);
}

/* The wait before the next poll (7.4): the given beat while the tab is
 * seen, at least a second while it is hidden. */
function pollDelay(ms) {
  return document.visibilityState === "hidden" ? Math.max(ms, 1000) : ms;
}

/* Tiny shared short-poller: fetch `url` every `intervalMs`, hand the
 * decoded JSON to `onData`, and stop (returned) on unmount — the same
 * shape as the Stage 1 practice polling. A 404 means the scroll is gone:
 * given `onGone`, the poller calls it once and falls silent. Other non-OK
 * answers are skipped. Hidden tabs poll once a second; a tab brought back
 * into view polls at once rather than waiting out the slow beat (7.4). */
function poll(url, intervalMs, onData, onGone) {
  let alive = true;
  let timer = 0;
  let inFlight = false;
  function later() {
    timer = setTimeout(tick, pollDelay(intervalMs));
  }
  function onVisible() {
    if (document.visibilityState === "visible" && alive && !inFlight) {
      clearTimeout(timer);
      tick();
    }
  }
  document.addEventListener("visibilitychange", onVisible);
  function tick() {
    inFlight = true;
    fetch(url, { cache: "no-store" })
      .then(function (res) {
        if (res.status === 404 && onGone) {
          if (alive) {
            alive = false;
            waver(true); // the link itself answered
            onGone();
          }
          return null;
        }
        return res.ok ? res.json() : null;
      })
      .then(function (data) {
        inFlight = false;
        if (!alive) return;
        waver(!!data); // a non-OK answer counts as a missed poll
        if (data) onData(data);
        later();
      })
      .catch(function () {
        inFlight = false;
        // a wavered link simply polls again — a stopped poller stays mute
        if (!alive) return;
        waver(false);
        later();
      });
  }
  tick();
  return function stop() {
    alive = false;
    clearTimeout(timer);
    document.removeEventListener("visibilitychange", onVisible);
  };
}

/* Accepts a bare room mark or a pasted room link.                     */
function extractRoomId(raw) {
  const s = (raw || "").trim();
  const m = s.match(/room=([A-Za-z0-9]+)/);
  if (m) return m[1];
  return s.replace(/^.*[\/#]/, "").trim();
}
