/* ------------------------- Shared client helpers -------------------- */
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
    const raw = data && data.error;
    const message =
      raw && raw.message
        ? raw.message
        : raw
        ? String(raw)
        : "The sages return no answer (" + res.status + ")";
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

/* Tiny shared short-poller: fetch `url` every `intervalMs`, hand the
 * decoded JSON to `onData`, and stop (returned) on unmount — the same
 * shape as the Stage 1 practice polling. Non-OK answers are skipped. */
function poll(url, intervalMs, onData) {
  let alive = true;
  let timer = 0;
  function tick() {
    fetch(url, { cache: "no-store" })
      .then(function (res) {
        return res.ok ? res.json() : null;
      })
      .then(function (data) {
        if (!alive) return;
        waver(!!data); // a non-OK answer counts as a missed poll
        if (data) onData(data);
        timer = setTimeout(tick, intervalMs);
      })
      .catch(function () {
        // a wavered link simply polls again
        waver(false);
        if (alive) timer = setTimeout(tick, intervalMs);
      });
  }
  tick();
  return function stop() {
    alive = false;
    clearTimeout(timer);
  };
}

/* Accepts a bare room mark or a pasted room link.                     */
function extractRoomId(raw) {
  const s = (raw || "").trim();
  const m = s.match(/room=([A-Za-z0-9]+)/);
  if (m) return m[1];
  return s.replace(/^.*[\/#]/, "").trim();
}
