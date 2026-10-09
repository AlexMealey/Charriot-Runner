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
        if (data) onData(data);
        timer = setTimeout(tick, intervalMs);
      })
      .catch(function () {
        // a wavered link simply polls again
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
