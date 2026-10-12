/* ------------------------------ Drop-down ------------------------------ *
 * The app's own drop-down, in place of the browser's <select>, whose     *
 * options can only be one plain line of text. A trigger dressed as an    *
 * input shows the chosen option; the plaque-dark list shows every option *
 * as its own face — an icon, a name, a note or stat pips (callers build  *
 * the faces). Keyboard: Enter, Space or ↓ open; ↑/↓, Home and End move;  *
 * Enter picks; Esc or Tab close. A click anywhere else closes it too.    *
 *                                                                        *
 * props: id (unique on the page), label (read to screen readers),        *
 * options [{value, face}], value, onChange(value), disabled.             *
 * --------------------------------------------------------------------- */
function Dropdown(props) {
  const [open, setOpen] = useState(false);
  const [active, setActive] = useState(0);
  const rootRef = useRef(null);
  const listRef = useRef(null);
  const values = props.options.map(function (o) {
    return o.value;
  });
  const chosen = Math.max(0, values.indexOf(props.value));
  const current = props.options[chosen];

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

  // a lock (say, standing ready) folds the list away
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
    if (props.options[i].value !== props.value) props.onChange(props.options[i].value);
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
    const last = props.options.length - 1;
    if (e.key === "ArrowDown") {
      e.preventDefault();
      setActive(Math.min(last, active + 1));
    } else if (e.key === "ArrowUp") {
      e.preventDefault();
      setActive(Math.max(0, active - 1));
    } else if (e.key === "Home") {
      e.preventDefault();
      setActive(0);
    } else if (e.key === "End") {
      e.preventDefault();
      setActive(last);
    } else if (e.key === "Enter" || e.key === " ") {
      e.preventDefault();
      pick(active);
    } else if (e.key === "Escape" || e.key === "Tab") {
      setOpen(false);
    }
  }

  return (
    <div className={open ? "dropdown open" : "dropdown"} ref={rootRef}>
      <button
        type="button"
        className="dropdown-trigger"
        disabled={props.disabled}
        aria-label={props.label}
        aria-haspopup="listbox"
        aria-expanded={open}
        aria-controls={props.id + "-list"}
        aria-activedescendant={open ? props.id + "-opt-" + active : undefined}
        onClick={function () {
          if (open) setOpen(false);
          else show();
        }}
        onKeyDown={onKey}
      >
        {current ? current.face : null}
        <span className="caret" aria-hidden="true">
          ▾
        </span>
      </button>
      {open ? (
        <ul
          className="dropdown-list"
          id={props.id + "-list"}
          role="listbox"
          aria-label={props.label}
          ref={listRef}
        >
          {props.options.map(function (o, i) {
            let cls = "dropdown-option";
            if (i === active) cls += " active";
            if (i === chosen) cls += " chosen";
            return (
              <li
                key={String(o.value)}
                id={props.id + "-opt-" + i}
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
                {o.face}
              </li>
            );
          })}
        </ul>
      ) : null}
    </div>
  );
}

/* One option's face: an icon, the name, and a note (or anything) beneath. */
function DropdownFace(props) {
  return (
    <span className="dd-line">
      {props.icon}
      <span className="dd-text">
        <span className="dd-name">{props.name}</span>
        {props.children}
      </span>
    </span>
  );
}
