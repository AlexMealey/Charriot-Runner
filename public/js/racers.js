/* --------------------------- Racers (SVG shapes) -------------------- */
const RACERS = [
  { name: "Red",    color: "#b3342f", path: "M 13 0 L -10 9 L -5 0 L -10 -9 Z" },
  { name: "Blue",   color: "#3a6ea5", path: "M -9 -9 H 9 V 9 H -9 Z" },
  { name: "Green",  color: "#4c7a3f", path: "M 0 -10 A 10 10 0 1 1 0 10 A 10 10 0 1 1 0 -10 Z" },
  { name: "Gold",   color: "#c9a45c", path: "M 13 0 L 0 10 L -13 0 L 0 -10 Z" },
  { name: "Violet", color: "#7a4a8f", path: "M 13 0 L -10 9 L -5 0 L -10 -9 Z" },
  { name: "Ember",  color: "#d9772e", path: "M -9 -9 H 9 V 9 H -9 Z" },
  { name: "Teal",   color: "#2f7f7a", path: "M 0 -10 A 10 10 0 1 1 0 10 A 10 10 0 1 1 0 -10 Z" },
  { name: "Silver", color: "#b9bec6", path: "M 13 0 L 0 10 L -13 0 L 0 -10 Z" },
];
const SHAPES = RACERS.map(function (r) { return new Path2D(r.path); });
const ORDINAL = ["1st", "2nd", "3rd", "4th", "5th", "6th", "7th", "8th"];

/* ------------------------- Horses (Stage 8) ------------------------- *
 * One sprite for every horse (stage-6 §6.4), filled with its rider's   *
 * colour. Drawn in its own 100×100 box, facing left, standing on a     *
 * base whose middle (50, 88) sits on the lane.                         *
 * ------------------------------------------------------------------- */
// path data shared by the canvas (Path2D) and the lobby's inline SVG icon
const HORSE_BODY_D =
  "M24 92H76V84H24ZM32 82L42 60H30L18 52L28 32L42 18L46 6L54 16L66 28L72 52L68 82Z";
const HORSE_LINES_D = "M42 18L54 34L72 52M28 32L54 34L42 60";
const HORSE_BODY = new Path2D(HORSE_BODY_D);
const HORSE_LINES = new Path2D(HORSE_LINES_D);

/* Paint a horse `height` px tall with its base at (x, y), turned to face
 * right when `faceRight` (the sprite itself faces left). */
function drawHorse(ctx, x, y, color, height, faceRight) {
  const s = height / 86; // the sprite stands 86 units tall (y 6 → 92)
  ctx.save();
  ctx.translate(x, y);
  ctx.scale(faceRight ? -s : s, s);
  ctx.translate(-50, -88);
  ctx.lineJoin = "round";
  ctx.strokeStyle = "rgba(0, 0, 0, 0.7)";
  ctx.lineWidth = 6;
  ctx.stroke(HORSE_BODY);
  ctx.fillStyle = color;
  ctx.fill(HORSE_BODY);
  ctx.strokeStyle = "#fff";
  ctx.lineWidth = 1.5;
  ctx.stroke(HORSE_LINES);
  ctx.fillStyle = "#fff";
  ctx.beginPath();
  ctx.arc(44, 28, 2.5, 0, Math.PI * 2);
  ctx.fill();
  ctx.restore();
}

/* A horse's display name from its roster slug ("fiery_tail" → "Fiery
 * Tail") — the roster names are exactly their slugs title-cased. */
function horseName(slug) {
  return String(slug || "")
    .split("_")
    .map(function (w) {
      return w.charAt(0).toUpperCase() + w.slice(1);
    })
    .join(" ");
}
