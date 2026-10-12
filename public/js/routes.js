/* ------------------------------- Routing ----------------------------- *
 * Views follow the query string:                                      *
 *   /                    → the gates (landing)                        *
 *   /?room=<32-hex>      → the join gate (nickname & dice)            *
 *   /?practice=<16-hex>  → the hippodrome (practice race)             *
 * Both marks at once, or a malformed one, turn to dust; any other     *
 * query (e.g. /?panel=practice) falls back to the gates.              *
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
  if (practice) {
    return /^[0-9a-fA-F]{16}$/.test(practice)
      ? { name: "practice", practiceId: practice.toLowerCase() }
      : { name: "dust" };
  }
  return { name: "landing" };
}
