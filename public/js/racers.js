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
