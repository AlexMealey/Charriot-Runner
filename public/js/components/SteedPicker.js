/* ---------------------------- Steed picker ---------------------------- *
 * The horses lobby's "Thy steed" (8.4) on the app's own Dropdown: every  *
 * option is the horse in the rider's colour, its name, and its stats as  *
 * pips beneath; Random comes first. Also home to the small icons the     *
 * other drop-downs reuse (horse, chariot, course).                       *
 * --------------------------------------------------------------------- */
const STEED_LEVEL = { slow: 1, normal: 2, fast: 3 };

/* The horse sprite (racers.js) as a small inline icon. */
function HorseIcon(props) {
  return (
    <span className="dd-icon" aria-hidden="true">
      <svg viewBox="0 0 100 100">
        <path
          d={HORSE_BODY_D}
          fill={props.color}
          stroke="rgba(0, 0, 0, 0.7)"
          strokeWidth="4"
          strokeLinejoin="round"
        />
        <path d={HORSE_LINES_D} fill="none" stroke="#fff" strokeWidth="1.5" />
        <circle cx="44" cy="28" r="2.5" fill="#fff" />
      </svg>
    </span>
  );
}

/* The classic chariot (racers.js, the first racer's shape) as an icon. */
function ChariotIcon(props) {
  return (
    <span className="dd-icon" aria-hidden="true">
      <svg viewBox="-15 -13 30 26">
        <path d={RACERS[0].path} fill={props.color} stroke="rgba(0, 0, 0, 0.7)" strokeWidth="1.5" />
      </svg>
    </span>
  );
}

/* A drawn course as an icon: two lanes round an oval. */
function CourseIcon() {
  return (
    <span className="dd-icon" aria-hidden="true">
      <svg viewBox="0 0 40 40">
        <rect x="3" y="11" width="34" height="18" rx="9" fill="none" strokeWidth="5" style={{ stroke: "var(--gold)" }} />
        <rect x="3" y="11" width="34" height="18" rx="9" fill="none" strokeWidth="1" strokeDasharray="3 2" style={{ stroke: "var(--plaque)" }} />
      </svg>
    </span>
  );
}

/* A glyph in a gold ring (Random's "?", say) as an icon. */
function GlyphIcon(props) {
  return (
    <span className="dd-icon glyph" aria-hidden="true">
      {props.children}
    </span>
  );
}

/* Canter, Turning and Gallop as three pips each; Stamina as a bar against
 * the stoutest horse in the stable, with its figure. */
function SteedStats(props) {
  const h = props.horse;
  const said =
    "canter " + h.canter + ", turning " + h.turning + ", gallop " + h.gallop +
    ", stamina " + h.stamina;
  return (
    <span className="steed-stats" aria-label={said}>
      {["canter", "turning", "gallop"].map(function (k) {
        const level = STEED_LEVEL[h[k]] || 0;
        return (
          <span key={k} className="stat" aria-hidden="true" title={k + ": " + h[k]}>
            <em>{k}</em>
            <span className="pips">
              {[1, 2, 3].map(function (n) {
                return <b key={n} className={n <= level ? "on" : ""} />;
              })}
            </span>
          </span>
        );
      })}
      <span className="stat" aria-hidden="true" title={"stamina: " + h.stamina}>
        <em>stamina</em>
        <span className="bar">
          <b style={{ width: Math.round((h.stamina / props.most) * 100) + "%" }} />
        </span>
        <span className="num">{h.stamina}</span>
      </span>
    </span>
  );
}

/* props: stable (the server's roster), value (slug or null = Random),
 * color (the rider's), disabled, onChange(slug or null). */
function SteedPicker(props) {
  const most = Math.max.apply(
    null,
    [1].concat(
      props.stable.map(function (h) {
        return h.stamina;
      })
    )
  );
  const options = [
    {
      value: null,
      face: (
        <DropdownFace icon={<GlyphIcon>?</GlyphIcon>} name="Random">
          <span className="dd-note">the stable master chooses at the start</span>
        </DropdownFace>
      ),
    },
  ].concat(
    props.stable.map(function (h) {
      return {
        value: h.slug,
        face: (
          <DropdownFace icon={<HorseIcon color={props.color} />} name={h.name}>
            <SteedStats horse={h} most={most} />
          </DropdownFace>
        ),
      };
    })
  );
  // a pick made before the stable loads is still named on the trigger
  if (props.value && !props.stable.some(function (h) { return h.slug === props.value; })) {
    options.push({
      value: props.value,
      face: <DropdownFace icon={<HorseIcon color={props.color} />} name={horseName(props.value)} />,
    });
  }
  return (
    <Dropdown
      id="steed"
      label="Thy steed"
      options={options}
      value={props.value || null}
      disabled={props.disabled}
      onChange={props.onChange}
    />
  );
}
