/* --------------------------- Race simulation ------------------------ *
 * A fixed 60 Hz step keeps each race deterministic for its seed:       *
 * surges and fades trade the lead back and forth, then the endgame     *
 * separates the field into the seeded order 1st → 4th.                 *
 * ------------------------------------------------------------------- */
function smoothstep(e0, e1, x) {
  const t = Math.min(1, Math.max(0, (x - e0) / (e1 - e0)));
  return t * t * (3 - 2 * t);
}

const STEP = 1 / 60;
const BASE_SPEED = 0.12; // laps per second

function createRace(rand) {
  const order = [0, 1, 2, 3];
  for (let i = 3; i > 0; i--) {
    const j = Math.floor(rand() * (i + 1));
    const tmp = order[i];
    order[i] = order[j];
    order[j] = tmp;
  }
  const racers = [0, 1, 2, 3].map(function (lane) {
    return {
      lane: lane,
      rank: order.indexOf(lane), // 0 = seeded winner
      p: -0.012,
      done: false,
      place: 0,
      surgeAmp: 0.1 + rand() * 0.12,
      surgeW: 1.2 + rand() * 1.2,
      surgePhase: rand() * Math.PI * 2,
    };
  });
  return { rand: rand, t: 0, finished: 0, racers: racers, placements: [] };
}

function stepRace(race) {
  const rand = race.rand;
  const racers = race.racers;
  race.t += STEP;
  let maxP = -1;
  for (let i = 0; i < racers.length; i++) {
    if (!racers[i].done && racers[i].p > maxP) maxP = racers[i].p;
  }
  const endgame = smoothstep(0.55, 0.9, Math.max(0, maxP));
  for (let i = 0; i < racers.length; i++) {
    const rc = racers[i];
    if (rc.done) continue;
    const gap = maxP - rc.p;
    const surge = rc.surgeAmp * Math.sin(rc.surgePhase + race.t * rc.surgeW);
    const jitter = (rand() - 0.5) * 0.05;
    // rubber banding: stragglers surge, the leader is reined in — but
    // only mid-race; the endgame lets raw rank strength decide.
    const band = (gap - 0.015) * 3 * (1 - endgame);
    const strength = 1 + (1.5 - rc.rank) * 0.06 * (0.25 + 2.5 * endgame);
    const v = BASE_SPEED * strength * (1 + surge + jitter + band);
    rc.p += Math.max(0.03, v) * STEP;
    if (rc.p >= 1) {
      const blocked = racers.some(function (o) { return !o.done && o.rank < rc.rank; });
      if (blocked) {
        rc.p = 0.994; // hold the line until the seeded order is clear
      } else {
        rc.done = true;
        race.finished += 1;
        rc.place = race.finished;
        race.placements.push(rc);
      }
    }
  }
}
