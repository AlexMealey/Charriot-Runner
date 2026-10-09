/* ------------------------------------------------------------------ *
 * Track: four nested loop lanes, each exactly one lap long. Movement  *
 * is normalized per lane, so all four lanes race an equal distance.   *
 * ------------------------------------------------------------------ */
function buildTrack(w, h, n) {
  n = n || 4;
  const availW = w - 110;
  const availH = h - 180;
  let laneW = Math.max(14, Math.min(44, availH * 0.085));
  let r0 = laneW * 1.8;
  let rOut = r0 + (n - 1) * laneW;
  let straight = availW - 2 * rOut - laneW;
  if (straight < 120) {
    laneW = Math.max(10, (availW - 130) / (2 * n));
    r0 = laneW * 1.8;
    rOut = r0 + (n - 1) * laneW;
    straight = Math.max(80, availW - 2 * rOut - laneW);
  }
  const half = straight / 2;
  const lanes = [];
  for (let i = 0; i < n; i++) {
    const R = r0 + i * laneW;
    lanes.push({ R: R, half: half, len: 2 * straight + 2 * Math.PI * R });
  }
  return {
    cx: w / 2,
    cy: h / 2 + 24,
    laneW: laneW,
    r0: r0,
    rOut: rOut,
    half: half,
    lanes: lanes,
  };
}

function lanePose(tr, i, u) {
  const lane = tr.lanes[i];
  const R = lane.R;
  const half = lane.half;
  const len = lane.len;
  let d = (u * len) % len;
  if (d < 0) d += len;
  const straight = 2 * half;
  const cap = Math.PI * R;
  const cx = tr.cx;
  const cy = tr.cy;
  if (d < straight) return { x: cx - half + d, y: cy - R, a: 0 };
  d -= straight;
  if (d < cap) {
    const th = -Math.PI / 2 + d / R;
    return {
      x: cx + half + R * Math.cos(th),
      y: cy + R * Math.sin(th),
      a: th + Math.PI / 2,
    };
  }
  d -= cap;
  if (d < straight) return { x: cx + half - d, y: cy + R, a: Math.PI };
  d -= straight;
  const th2 = Math.PI / 2 + d / R;
  return {
    x: cx - half + R * Math.cos(th2),
    y: cy + R * Math.sin(th2),
    a: th2 + Math.PI / 2,
  };
}

function stadiumPath(tr, R) {
  const cx = tr.cx;
  const cy = tr.cy;
  const half = tr.half;
  const p = new Path2D();
  p.moveTo(cx - half, cy - R);
  p.lineTo(cx + half, cy - R);
  p.arc(cx + half, cy, R, -Math.PI / 2, Math.PI / 2);
  p.lineTo(cx - half, cy + R);
  p.arc(cx - half, cy, R, Math.PI / 2, (3 * Math.PI) / 2);
  p.closePath();
  return p;
}
