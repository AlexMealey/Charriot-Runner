/* ------------------------------- Render ----------------------------- */
function roundRect(ctx, x, y, w, h, r) {
  ctx.beginPath();
  ctx.moveTo(x + r, y);
  ctx.arcTo(x + w, y, x + w, y + h, r);
  ctx.arcTo(x + w, y + h, x, y + h, r);
  ctx.arcTo(x, y + h, x, y, r);
  ctx.arcTo(x, y, x + w, y, r);
  ctx.closePath();
}

function parchmentCard(ctx, x, y, w, h) {
  const T = theme();
  ctx.save();
  ctx.fillStyle = "rgba(0, 0, 0, 0.45)";
  roundRect(ctx, x + 4, y + 6, w, h, 8);
  ctx.fill();
  const g = ctx.createLinearGradient(x, y, x, y + h);
  g.addColorStop(0, "#efe2bd");
  g.addColorStop(1, "#d9c391");
  ctx.fillStyle = g;
  roundRect(ctx, x, y, w, h, 8);
  ctx.fill();
  ctx.strokeStyle = T.goldDim;
  ctx.lineWidth = 2;
  roundRect(ctx, x, y, w, h, 8);
  ctx.stroke();
  ctx.restore();
}

const IDLE = {
  n: 4,
  t: 0,
  finished: 0,
  placements: [],
  racers: [0, 1, 2, 3].map(function (lane) {
    return { lane: lane, p: -0.012, done: false, place: 0 };
  }),
};

function draw(ctx, w, h, tr, race) {
  const T = theme();
  const state = race || IDLE;
  const n = state.n || 4;
  const names = state.names || [];
  // horses races (Stage 8) label every rider with their steed too
  const horses = state.horses || null;
  function label(lane) {
    const who = names[lane] || RACERS[lane].name;
    return horses ? who + " · " + horseName(horses[lane]) : who;
  }

  // torch-lit field
  const bg = ctx.createRadialGradient(
    w / 2, h * 0.42, Math.min(w, h) * 0.1,
    w / 2, h * 0.5, Math.max(w, h) * 0.75
  );
  bg.addColorStop(0, "#241a12");
  bg.addColorStop(0.6, T.field);
  bg.addColorStop(1, "#0b0806");
  ctx.fillStyle = bg;
  ctx.fillRect(0, 0, w, h);

  // title
  ctx.textAlign = "center";
  const tg = ctx.createLinearGradient(0, 22, 0, 58);
  tg.addColorStop(0, T.goldBright);
  tg.addColorStop(1, T.goldDim);
  ctx.fillStyle = tg;
  ctx.font = "700 30px Cinzel, Georgia, serif";
  ctx.fillText("CHARIOT RACING", w / 2, 52);
  ctx.fillStyle = T.inkDim;
  ctx.font = "italic 15px 'IM Fell English', Georgia, serif";
  ctx.fillText("a contest of speed, cunning and the favour of the gods", w / 2, 76);

  // the course: today's n-lane oval, or a horses race's two-lane course
  if (tr.course) {
    drawCourse(ctx, w, tr, state, T);
  } else {
    drawOval(ctx, tr, state, n, T);
  }

  // torchlight vignette
  const vg = ctx.createRadialGradient(
    w / 2, h / 2, Math.min(w, h) * 0.35,
    w / 2, h / 2, Math.max(w, h) * 0.75
  );
  vg.addColorStop(0, "rgba(0, 0, 0, 0)");
  vg.addColorStop(1, "rgba(0, 0, 0, 0.55)");
  ctx.fillStyle = vg;
  ctx.fillRect(0, 0, w, h);

  // standings — a small parchment scroll (wider when steeds are named)
  const sw = horses ? 330 : 238;
  const sx = w - sw - 24;
  const sy = 96;
  const sh = 58 + state.placements.length * 30;
  parchmentCard(ctx, sx, sy, sw, sh);
  ctx.textAlign = "left";
  ctx.fillStyle = "#6d2d22";
  ctx.font = "700 15px Cinzel, Georgia, serif";
  ctx.fillText("STANDINGS", sx + 22, sy + 28);
  if (state.placements.length === 0) {
    ctx.fillStyle = "#5c4a30";
    ctx.font = "italic 14px 'IM Fell English', Georgia, serif";
    ctx.fillText("awaiting the trumpet…", sx + 22, sy + 52);
  }
  state.placements.forEach(function (rc, i) {
    const rowY = sy + 52 + i * 30;
    if (horses) {
      drawHorse(ctx, sx + 28, rowY + 6, RACERS[rc.lane].color, 18, false);
    } else {
      ctx.save();
      ctx.translate(sx + 28, rowY - 4);
      ctx.scale(0.5, 0.5);
      ctx.fillStyle = RACERS[rc.lane].color;
      ctx.fill(SHAPES[rc.lane]);
      ctx.restore();
    }
    ctx.fillStyle = "#3b2a16";
    ctx.font = "14px 'IM Fell English', Georgia, serif";
    ctx.fillText(ORDINAL[i] + "   " + label(rc.lane), sx + 46, rowY);
  });

  // legend shields
  ctx.textAlign = "left";
  if (horses) {
    drawHorseLegend(ctx, w, h, n, label, T);
    return;
  }
  RACERS.slice(0, n).forEach(function (r, i) {
    const gx = 34 + i * 96;
    const gy = h - 34;
    ctx.save();
    ctx.translate(gx, gy);
    ctx.scale(0.5, 0.5);
    ctx.fillStyle = r.color;
    ctx.fill(SHAPES[i]);
    ctx.restore();
    ctx.fillStyle = T.inkDim;
    ctx.font = "14px 'IM Fell English', Georgia, serif";
    ctx.fillText(names[i] || r.name, gx + 16, gy + 5);
  });
}

/* The classic course: today's n-lane oval with its chariots. */
function drawOval(ctx, tr, state, n, T) {
  const rin = tr.r0 - tr.laneW / 2;
  const rout = tr.rOut + tr.laneW / 2;
  const mid = (rin + rout) / 2;

  // track bed with stone edging
  ctx.strokeStyle = T.trackEdge;
  ctx.lineWidth = rout - rin + 10;
  ctx.stroke(stadiumPath(tr, mid));
  ctx.strokeStyle = T.track;
  ctx.lineWidth = rout - rin;
  ctx.stroke(stadiumPath(tr, mid));

  // lane dividers
  ctx.strokeStyle = T.laneLine;
  ctx.lineWidth = 1.5;
  ctx.setLineDash([8, 6]);
  for (let k = 0; k <= n; k++) {
    ctx.stroke(stadiumPath(tr, rin + k * tr.laneW));
  }
  ctx.setLineDash([]);

  // checkered start/finish strip (parchment & blood)
  const fx = tr.cx - tr.half;
  const cell = 5;
  const fy0 = tr.cy - rout;
  const fy1 = tr.cy - rin;
  for (let y = fy0, row = 0; y < fy1; y += cell, row++) {
    for (let col = 0; col < 2; col++) {
      ctx.fillStyle = (row + col) % 2 === 0 ? "#efe2bd" : T.blood;
      ctx.fillRect(fx - 5 + col * cell, y, cell, cell);
    }
  }

  // racers
  state.racers.forEach(function (rc) {
    const pose = lanePose(tr, rc.lane, rc.p);
    ctx.save();
    ctx.translate(pose.x, pose.y);
    ctx.rotate(pose.a);
    ctx.shadowColor = "rgba(0, 0, 0, 0.6)";
    ctx.shadowBlur = 6;
    ctx.shadowOffsetY = 2;
    ctx.fillStyle = RACERS[rc.lane].color;
    ctx.fill(SHAPES[rc.lane]);
    ctx.restore();
    if (rc.done) {
      ctx.fillStyle = T.goldBright;
      ctx.font = "700 14px Cinzel, Georgia, serif";
      ctx.textAlign = "center";
      ctx.fillText(ORDINAL[rc.place - 1], pose.x, pose.y - 18);
    }
  });
}

/* A horses race (Stage 8): the server's course two lanes wide, the
 * start/finish strip across both lanes, and every horse at its place. The
 * start grid is drawn only — two abreast in slot order, each row a little
 * further back — and closes up over the opening straight. */
function drawCourse(ctx, w, tr, state, T) {
  // a map course (Stage 9): the map first, its roads showing through
  if (tr.map && tr.map.img.complete && tr.map.img.naturalWidth) {
    ctx.drawImage(tr.map.img, tr.map.x, tr.map.y, tr.map.w, tr.map.h);
  }
  const pts = tr.pts;
  const line = new Path2D();
  line.moveTo(pts[0].x, pts[0].y);
  for (let i = 1; i < pts.length; i++) line.lineTo(pts[i].x, pts[i].y);
  if (tr.loop) line.closePath();
  ctx.save();
  ctx.lineJoin = "round";
  ctx.lineCap = "round";
  // on a map the loop is a highlighted band over the road; elsewhere the
  // stone-edged bed of a drawn course
  ctx.strokeStyle = tr.map ? "rgba(0, 0, 0, 0.55)" : T.trackEdge;
  ctx.lineWidth = tr.laneW * 2 + (tr.map ? 4 : 10);
  ctx.stroke(line);
  ctx.strokeStyle = tr.map ? "rgba(233, 200, 119, 0.5)" : T.track;
  ctx.lineWidth = tr.laneW * 2;
  ctx.stroke(line);
  ctx.strokeStyle = tr.map ? "rgba(20, 16, 12, 0.55)" : T.laneLine;
  ctx.lineWidth = 1.5;
  ctx.setLineDash([8, 6]);
  ctx.stroke(line);
  ctx.restore();

  // checkered start/finish strip (parchment & blood) across both lanes
  const start = coursePose(tr, 0, 0);
  const cell = 5;
  ctx.save();
  ctx.translate(start.x, start.y);
  ctx.rotate(Math.atan2(start.dy, start.dx));
  for (let y = -tr.laneW, row = 0; y < tr.laneW; y += cell, row++) {
    for (let col = 0; col < 2; col++) {
      ctx.fillStyle = (row + col) % 2 === 0 ? "#efe2bd" : T.blood;
      ctx.fillRect(-cell + col * cell, y, cell, Math.min(cell, tr.laneW - y));
    }
  }
  ctx.restore();

  // horses, back to front so the nearer ones stand over the farther
  const laps = state.laps || 1;
  let lead = 0;
  const placed = state.racers.map(function (rc) {
    const d = rc.p * laps;
    if (!rc.done) lead = Math.max(lead, d);
    const back = Math.floor(rc.lane / 2) * tr.gridGap * Math.max(0, 1 - d / tr.closeLaps);
    const side = rc.side === undefined ? rc.lane % 2 : rc.side;
    return { rc: rc, pose: coursePose(tr, d - back, (side - 0.5) * tr.laneW) };
  });
  placed.sort(function (a, b) {
    return a.pose.y - b.pose.y;
  });
  placed.forEach(function (it) {
    const rc = it.rc;
    const pose = it.pose;
    drawHorse(ctx, pose.x, pose.y + tr.horseH * 0.35, RACERS[rc.lane].color, tr.horseH, pose.dx > 0);
    if (rc.done) {
      ctx.fillStyle = T.goldBright;
      ctx.font = "700 14px Cinzel, Georgia, serif";
      ctx.textAlign = "center";
      ctx.fillText(ORDINAL[rc.place - 1], pose.x, pose.y - tr.horseH * 0.75);
    }
  });

  // the leader's lap, while more than one is run
  if (laps > 1 && state.finished < state.n) {
    ctx.fillStyle = T.goldBright;
    ctx.font = "700 15px Cinzel, Georgia, serif";
    ctx.textAlign = "center";
    ctx.fillText("Lap " + Math.min(laps, Math.floor(lead) + 1) + " of " + laps, w / 2, 100);
  }
}

/* The legend for a horses race: each rider's horse in their colour and
 * "Nickname · Steed", flowing into a second row when the first is full. */
function drawHorseLegend(ctx, w, h, n, label, T) {
  ctx.font = "14px 'IM Fell English', Georgia, serif";
  const rows = [[]];
  let x = 34;
  for (let i = 0; i < n; i++) {
    const text = label(i);
    const width = 22 + ctx.measureText(text).width + 26;
    if (x + width > w - 20 && rows[rows.length - 1].length) {
      rows.push([]);
      x = 34;
    }
    rows[rows.length - 1].push({ lane: i, text: text, x: x });
    x += width;
  }
  rows.forEach(function (row, r) {
    const gy = h - 22 - (rows.length - 1 - r) * 26;
    row.forEach(function (it) {
      drawHorse(ctx, it.x, gy + 4, RACERS[it.lane].color, 18, false);
      ctx.fillStyle = T.inkDim;
      ctx.fillText(it.text, it.x + 14, gy);
    });
  });
}
