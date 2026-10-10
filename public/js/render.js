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

  // torchlight vignette
  const vg = ctx.createRadialGradient(
    w / 2, h / 2, Math.min(w, h) * 0.35,
    w / 2, h / 2, Math.max(w, h) * 0.75
  );
  vg.addColorStop(0, "rgba(0, 0, 0, 0)");
  vg.addColorStop(1, "rgba(0, 0, 0, 0.55)");
  ctx.fillStyle = vg;
  ctx.fillRect(0, 0, w, h);

  // standings — a small parchment scroll
  const sx = w - 262;
  const sy = 96;
  const sw = 238;
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
    ctx.save();
    ctx.translate(sx + 28, rowY - 4);
    ctx.scale(0.5, 0.5);
    ctx.fillStyle = RACERS[rc.lane].color;
    ctx.fill(SHAPES[rc.lane]);
    ctx.restore();
    ctx.fillStyle = "#3b2a16";
    ctx.font = "14px 'IM Fell English', Georgia, serif";
    ctx.fillText(
      ORDINAL[i] + "   " + (names[rc.lane] || RACERS[rc.lane].name),
      sx + 46,
      rowY
    );
  });

  // legend shields
  ctx.textAlign = "left";
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
