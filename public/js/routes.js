/* ------------------------------- Routing ----------------------------- *
 * Views follow the query string:                                      *
 *   /                    → the gates (landing)                        *
 *   /?room=<32-hex>      → the join gate (nickname & dice)            *
 *   /?practice=<id>      → the hippodrome (practice race)             *
 * Anything else turns to dust.                                        *
 * ------------------------------------------------------------------- */
function parseRoute() {
  const q = new URLSearchParams(window.location.search);
  const room = (q.get("room") || "").trim();
  const practice = (q.get("practice") || "").trim();
  if (room && practice) return { name: "dust" };
  if (room) {
    return /^[0-9a-fA-F]{32}$/.test(room)
      ? { name: "join", roomId: room.toLowerCase() }
      : { name: "dust" };
  }
  if (practice) return { name: "practice", practiceId: practice };
  return { name: "landing" };
}
