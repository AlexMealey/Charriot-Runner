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

/* ------------------------ Courses (Stage 8) ------------------------- *
 * A horses race runs on a course plotted by the server (action=track):  *
 * evenly spaced points along its centre line. The course is scaled      *
 * uniformly into the window — letterboxed, never stretched — and drawn  *
 * two lanes wide whatever the field size.                               *
 * ------------------------------------------------------------------- */
/* Map images (Stage 9), loaded once per page and drawn when ready. */
const MAP_IMAGES = {};
function mapImage(src) {
  if (!MAP_IMAGES[src]) {
    const img = new Image();
    img.src = src;
    MAP_IMAGES[src] = img;
  }
  return MAP_IMAGES[src];
}

function buildCourse(w, h, plot) {
  const vb = String(plot.viewBox || "0 0 1000 600")
    .split(/[\s,]+/)
    .map(Number);
  const top = 112; // title and lap counter
  const bottom = 76; // legend
  const side = 40;
  const availW = Math.max(100, w - 2 * side);
  const availH = Math.max(100, h - top - bottom);
  const scale = Math.min(availW / vb[2], availH / vb[3]);
  const ox = side + (availW - vb[2] * scale) / 2 - vb[0] * scale;
  const oy = top + (availH - vb[3] * scale) / 2 - vb[1] * scale;
  const pts = [];
  for (let i = 0; i + 1 < plot.points.length; i += 2) {
    pts.push({ x: ox + plot.points[i] * scale, y: oy + plot.points[i + 1] * scale });
  }
  let lapPx = 0;
  for (let i = 1; i < pts.length; i++) {
    lapPx += Math.hypot(pts[i].x - pts[i - 1].x, pts[i].y - pts[i - 1].y);
  }
  if (plot.loop && pts.length > 1) {
    lapPx += Math.hypot(pts[0].x - pts[pts.length - 1].x, pts[0].y - pts[pts.length - 1].y);
  }
  // a map course (Stage 9) lays its loop over the map's own roads: narrow
  // lanes, the map image behind, laid exactly over the viewBox
  const isMap = !!plot.image;
  const laneW = isMap
    ? Math.max(6, Math.min(12, 4 * scale))
    : Math.max(10, Math.min(26, 22 * scale));
  const horseH = isMap
    ? Math.max(18, Math.min(28, laneW * 2.2))
    : Math.max(18, Math.min(32, laneW * 1.25));
  const first = plot.segments && plot.segments[0];
  return {
    course: true,
    map: isMap
      ? {
          img: mapImage(plot.image),
          x: ox + vb[0] * scale,
          y: oy + vb[1] * scale,
          w: vb[2] * scale,
          h: vb[3] * scale,
        }
      : null,
    pts: pts,
    loop: !!plot.loop,
    laneW: laneW,
    horseH: horseH,
    // the start grid (drawn only): each row 1.2 horse widths further back,
    // the gap closing over the opening straight
    gridGap: (1.2 * horseH * 0.6) / Math.max(1, lapPx),
    closeLaps: first && first.kind === "straight" ? first.to : 0.15,
  };
}

/* Where a horse `d` laps into the race stands (d may run past one lap or
 * sit behind the line), shifted `off` px across the course; dx/dy is the
 * way it runs. */
function coursePose(c, d, off) {
  const n = c.pts.length;
  const span = c.loop ? n : n - 1;
  const f = c.loop ? d - Math.floor(d) : Math.max(0, Math.min(1, d));
  const u = f * span;
  const i = Math.min(Math.floor(u), span - 1);
  const t = u - i;
  const a = c.pts[i];
  const b = c.pts[(i + 1) % n];
  let dx = b.x - a.x;
  let dy = b.y - a.y;
  const len = Math.hypot(dx, dy) || 1;
  dx /= len;
  dy /= len;
  return {
    x: a.x + (b.x - a.x) * t - dy * off,
    y: a.y + (b.y - a.y) * t + dx * off,
    dx: dx,
    dy: dy,
  };
}
