/* ------------------------------------------------------------------ *
 * Seeded randomness. The raw seed only ever lives inside these        *
 * closures — it is never written to the DOM and never logged.         *
 * ------------------------------------------------------------------ */
function mulberry32(a) {
  return function () {
    a |= 0;
    a = (a + 0x6d2b79f5) | 0;
    let t = Math.imul(a ^ (a >>> 15), 1 | a);
    t = (t + Math.imul(t ^ (t >>> 7), 61 | t)) ^ t;
    return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
  };
}

async function seededRandom() {
  const res = await fetch("api/?action=seed");
  const data = await res.json();
  const raw = atob(data.seed);
  const bytes = new Uint8Array(raw.length);
  for (let i = 0; i < raw.length; i++) bytes[i] = raw.charCodeAt(i);
  // payload is (seed ^ key) || key — fold the recovered seed bytes down
  // to one uint32 PRNG state via FNV-1a.
  let h = 2166136261 >>> 0;
  for (let i = 0; i < 8; i++) {
    h = Math.imul(h ^ (bytes[i] ^ bytes[i + 8]), 16777619);
  }
  return mulberry32(h >>> 0);
}
