/* ---------------------------- Steed picker ---------------------------- *
 * The horses lobby's "Thy steed" (8.4) as a themed drop-down. A native   *
 * <select> shows one plain line per option, so the four stats ran into  *
 * each other; here every option is the horse in the rider's colour, its *
 * name, and its stats as pips beneath. Keyboard: Enter, Space or ↓ open *
 * the list; ↑/↓, Home and End move; Enter picks; Esc or Tab close. A    *
 * click anywhere else closes it too.                                     *
 * --------------------------------------------------------------------- */
const STEED_LEVEL = { slow: 1, normal: 2, fast: 3 };

/* The horse sprite (racers.js) as a small inline icon. */
function HorseIcon(props) {
  return (
    <svg className="horse-icon" viewBox="0 0 100 100" aria-hidden="true">
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

function SteedPicker(props) {
  const [open, setOpen] = useState(false);
  const [active, setActive] = useState(0);
  const rootRef = useRef(null);
  const listRef = useRef(null);
  // option 0 is Random (null), then the stable in roster order
  const options = [null].concat(
    props.stable.map(function (h) {
      return h.slug;
    })
  );
  const bySlug = {};
  props.stable.forEach(function (h) {
    bySlug[h.slug] = h;
  });
  const most = Math.max.apply(
    null,
    [1].concat(
      props.stable.map(function (h) {
        return h.stamina;
      })
    )
  );
  const chosen = Math.max(0, options.indexOf(props.value || null));
  // the pick as the trigger shows it — named even before the stable loads
  const current = props.value
    ? bySlug[props.value] || { name: horseName(props.value) }
    : null;

  // a click or touch anywhere else closes the list
  useEffect(
    function () {
      if (!open) return;
      function away(e) {
        if (rootRef.current && !rootRef.current.contains(e.target)) setOpen(false);
      }
      document.addEventListener("mousedown", away);
      document.addEventListener("touchstart", away);
      return function () {
        document.removeEventListener("mousedown", away);
        document.removeEventListener("touchstart", away);
      };
    },
    [open]
  );

  // keep the highlighted row in view while the keys move it
  useEffect(
    function () {
      if (!open || !listRef.current) return;
      const row = listRef.current.children[active];
      if (row) row.scrollIntoView({ block: "nearest" });
    },
    [open, active]
  );

  // standing ready locks the pick: the list folds away
  useEffect(
    function () {
      if (props.disabled) setOpen(false);
    },
    [props.disabled]
  );

  function show() {
    if (props.disabled) return;
    setActive(chosen);
    setOpen(true);
  }

  function pick(i) {
    setOpen(false);
    if (options[i] !== (props.value || null)) props.onChange(options[i]);
  }

  function onKey(e) {
    if (props.disabled) return;
    if (!open) {
      if (["Enter", " ", "ArrowDown", "ArrowUp"].indexOf(e.key) >= 0) {
        e.preventDefault();
        show();
      }
      return;
    }
    if (e.key === "ArrowDown") {
      e.preventDefault();
      setActive(Math.min(options.length - 1, active + 1));
    } else if (e.key === "ArrowUp") {
      e.preventDefault();
      setActive(Math.max(0, active - 1));
    } else if (e.key === "Home") {
      e.preventDefault();
      setActive(0);
    } else if (e.key === "End") {
      e.preventDefault();
      setActive(options.length - 1);
    } else if (e.key === "Enter" || e.key === " ") {
      e.preventDefault();
      pick(active);
    } else if (e.key === "Escape" || e.key === "Tab") {
      setOpen(false);
    }
  }

  // One option's face: icon, name, and the stats line (or Random's note).
  function face(horse) {
    if (!horse) {
      return (
        <span className="steed-line">
          <span className="horse-icon random" aria-hidden="true">
            ?
          </span>
          <span className="steed-text">
            <span className="steed-name">Random</span>
            <span className="steed-note">the stable master chooses at the start</span>
          </span>
        </span>
      );
    }
    return (
      <span className="steed-line">
        <HorseIcon color={props.color} />
        <span className="steed-text">
          <span className="steed-name">{horse.name}</span>
          {horse.stamina !== undefined ? <SteedStats horse={horse} most={most} /> : null}
        </span>
      </span>
    );
  }

  return (
    <div className={open ? "steed-picker open" : "steed-picker"} ref={rootRef}>
      <button
        type="button"
        className="steed-trigger"
        disabled={props.disabled}
        aria-haspopup="listbox"
        aria-expanded={open}
        aria-controls="steed-list"
        aria-activedescendant={open ? "steed-opt-" + active : undefined}
        onClick={function () {
          if (open) setOpen(false);
          else show();
        }}
        onKeyDown={onKey}
      >
        {face(current)}
        <span className="caret" aria-hidden="true">
          ▾
        </span>
      </button>
      {open ? (
        <ul
          className="steed-list"
          id="steed-list"
          role="listbox"
          aria-label="Thy steed"
          ref={listRef}
        >
          {options.map(function (slug, i) {
            let cls = "steed-option";
            if (i === active) cls += " active";
            if (i === chosen) cls += " chosen";
            return (
              <li
                key={slug || "random"}
                id={"steed-opt-" + i}
                role="option"
                aria-selected={i === chosen}
                className={cls}
                onMouseEnter={function () {
                  setActive(i);
                }}
                onMouseDown={function (e) {
                  e.preventDefault(); // keep focus on the trigger
                }}
                onClick={function () {
                  pick(i);
                }}
              >
                {face(slug ? bySlug[slug] : null)}
              </li>
            );
          })}
        </ul>
      ) : null}
    </div>
  );
}
