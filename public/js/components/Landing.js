/* ------------------------------- Landing ----------------------------- */
function Landing(props) {
  // 5.5: Race Again's /?panel=practice reopens the count picker at once.
  const [panel, setPanel] = useState(
    new URLSearchParams(window.location.search).get("panel") || "menu"
  ); // menu | create | join | practice
  const [racers, setRacers] = useState(4);
  const [souls, setSouls] = useState(8);
  const [code, setCode] = useState("");
  const [notice, setNotice] = useState("");
  const [busy, setBusy] = useState(false);
  // Souls who may enter: never fewer than the chariots, never past the
  // sixteen the server allows (a typed 99 is only a wish).
  const seats = Math.min(16, Math.max(racers, souls));
  // Rules of the race (8.3/8.9): classic chariots, or horses on a course
  // the server plots — chosen here for rooms and practice alike.
  const [mode, setMode] = useState("classic");
  const [track, setTrack] = useState("hippodrome");
  const [laps, setLaps] = useState(1);
  const [tracks, setTracks] = useState([]);
  useEffect(function () {
    let alive = true;
    fetch("api/?action=tracks")
      .then(function (res) {
        return res.ok ? res.json() : null;
      })
      .then(function (data) {
        if (alive && data && data.tracks) setTracks(data.tracks);
      })
      .catch(function () {
        /* no list: the server's default course still serves */
      });
    return function () {
      alive = false;
    };
  }, []);
  const course = tracks.find(function (t) {
    return t.slug === track;
  });

  // What every create call adds for the chosen rules.
  function setup() {
    return mode === "custom"
      ? { mode: "custom", track: track, laps: course && !course.loop ? 1 : laps }
      : { mode: "classic" };
  }

  // The rules, course and laps fields, shared by both panels.
  function rulesFields() {
    return (
      <React.Fragment>
        <div className="field">
          <label>Rules of the race</label>
          <select
            value={mode}
            onChange={function (e) {
              setMode(e.target.value);
            }}
          >
            <option value="classic">Classic — the gods decide</option>
            <option value="custom">Horses — the riders decide</option>
          </select>
        </div>
        {mode === "custom" && tracks.length ? (
          <div className="field">
            <label>The course</label>
            <select
              value={track}
              onChange={function (e) {
                setTrack(e.target.value);
              }}
            >
              {tracks.map(function (t) {
                return (
                  <option key={t.slug} value={t.slug}>
                    {t.name}
                  </option>
                );
              })}
            </select>
          </div>
        ) : null}
        {mode === "custom" && (!course || course.loop) ? (
          <div className="field">
            <div className="rowline">
              <label>Laps</label>
              <span className="tally">{laps}</span>
            </div>
            <input
              type="range"
              min="1"
              max="3"
              value={laps}
              onChange={function (e) {
                setLaps(parseInt(e.target.value, 10));
              }}
            />
          </div>
        ) : null}
      </React.Fragment>
    );
  }

  function enterKey(fn) {
    return function (e) {
      if (e.key === "Enter") fn();
    };
  }

  function backToMenu(e) {
    e.preventDefault();
    setPanel("menu");
    setNotice("");
  }

  function forgeRoom() {
    if (busy) return; // Enter rides past the disabled button: one room per plea
    setBusy(true);
    setNotice("");
    api(
      "create_room",
      Object.assign({ maxRacers: racers, maxJoiners: seats }, setup())
    )
      .then(function (data) {
        setBusy(false);
        localStorage.setItem("chariot.host." + data.roomId, data.hostToken);
        props.navigate("/?room=" + data.roomId);
      })
      .catch(function (err) {
        setBusy(false);
        setNotice(err.message);
      });
  }

  function seekRoom() {
    const id = extractRoomId(code);
    if (!id) {
      setNotice("Inscribe a room mark first.");
      return;
    }
    props.navigate("/?room=" + encodeURIComponent(id));
  }

  function practice() {
    setPanel("practice");
  }

  function ride() {
    setBusy(true);
    setNotice("");
    api("create_practice", Object.assign({ racers: racers }, setup()))
      .then(function (data) {
        setBusy(false);
        props.navigate("/?practice=" + data.practiceId);
      })
      .catch(function (err) {
        setBusy(false);
        setNotice(err.message);
      });
  }

  let body;
  if (panel === "create") {
    body = (
      <React.Fragment>
        <h1>Forge a Room</h1>
        <p className="tagline">thou shalt be host of the hippodrome</p>
        <div className="field">
          <div className="rowline">
            <label>Chariots upon the track</label>
            <span className="tally">{racers}</span>
          </div>
          <input
            type="range"
            min="2"
            max="8"
            value={racers}
            onChange={function (e) {
              setRacers(parseInt(e.target.value, 10));
            }}
          />
        </div>
        <div className="field">
          <div className="rowline">
            <label>Souls who may enter</label>
            <span className="tally">{seats}</span>
          </div>
          <input
            type="number"
            min="2"
            max="16"
            value={souls}
            onChange={function (e) {
              setSouls(parseInt(e.target.value, 10) || 0);
            }}
            onKeyDown={enterKey(forgeRoom)}
          />
        </div>
        {rulesFields()}
        {notice ? <p className="notice">{notice}</p> : null}
        <div className="menu">
          <button onClick={forgeRoom} disabled={busy}>
            {busy ? "Summoning…" : "Forge the Room"}
          </button>
          <a className="linkish" href="/" onClick={backToMenu}>
            ◂ The Gates
          </a>
        </div>
      </React.Fragment>
    );
  } else if (panel === "join") {
    body = (
      <React.Fragment>
        <h1>Seek a Room</h1>
        <p className="tagline">inscribe the room's mark, or its full scroll-link</p>
        <div className="field">
          <label>Room mark</label>
          <input
            type="text"
            placeholder="thirty-two runes of hex"
            value={code}
            onChange={function (e) {
              setCode(e.target.value);
            }}
            onKeyDown={enterKey(seekRoom)}
          />
        </div>
        {notice ? <p className="notice">{notice}</p> : null}
        <div className="menu">
          <button onClick={seekRoom}>Seek the Room</button>
          <a className="linkish" href="/" onClick={backToMenu}>
            ◂ The Gates
          </a>
        </div>
      </React.Fragment>
    );
  } else if (panel === "practice") {
    body = (
      <React.Fragment>
        <h1>Ride the Practice Round</h1>
        <p className="tagline">the chariots run whether thou watchest or not</p>
        <div className="field">
          <div className="rowline">
            <label>Chariots upon the track</label>
            <span className="tally">{racers}</span>
          </div>
          <input
            type="range"
            min="2"
            max="8"
            value={racers}
            onChange={function (e) {
              setRacers(parseInt(e.target.value, 10));
            }}
          />
        </div>
        {rulesFields()}
        {notice ? <p className="notice">{notice}</p> : null}
        <div className="menu">
          <button onClick={ride} disabled={busy}>
            {busy ? "Summoning…" : "Ride!"}
          </button>
          <a className="linkish" href="/" onClick={backToMenu}>
            ◂ The Gates
          </a>
        </div>
      </React.Fragment>
    );
  } else {
    body = (
      <React.Fragment>
        <h1>CHARIOT RACING</h1>
        <p className="tagline">
          a contest of speed, cunning and the favour of the gods
        </p>
        <div className="menu">
          <button onClick={function () { setPanel("create"); }}>
            Create a Room
          </button>
          <button onClick={function () { setPanel("join"); }}>
            Join a Room
          </button>
          <button onClick={practice}>Practice</button>
        </div>
      </React.Fragment>
    );
  }

  return <div className="stage"><div className="card">{body}</div></div>;
}
