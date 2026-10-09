/* ------------------------------------------------------------------ *
 * Theme: one palette shared between the stylesheet (CSS custom        *
 * properties) and the canvas renderer.                                *
 * ------------------------------------------------------------------ */
let THEME = null;
function theme() {
  if (!THEME) {
    const cs = getComputedStyle(document.documentElement);
    function v(name, fallback) {
      const val = cs.getPropertyValue(name).trim();
      return val || fallback;
    }
    THEME = {
      field: v("--field", "#14100c"),
      track: v("--track", "#4c3a26"),
      trackEdge: v("--track-edge", "#2a1f14"),
      laneLine: v("--lane-line", "rgba(233, 200, 119, 0.22)"),
      ink: v("--ink", "#f3e9d2"),
      inkDim: v("--ink-dim", "#cbb98f"),
      gold: v("--gold", "#c9a45c"),
      goldBright: v("--gold-bright", "#e9c877"),
      goldDim: v("--gold-dim", "#8a6d3b"),
      blood: v("--blood", "#8c2f2f"),
    };
  }
  return THEME;
}
