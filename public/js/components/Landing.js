/* ------------------------------- Landing ----------------------------- */
function Landing(props) {
  const [panel, setPanel] = useState("menu"); // menu | create | join | practice
  const [racers, setRacers] = useState(4);
  const [souls, setSouls] = useState(8);
  const [code, setCode] = useState("");
  const [notice, setNotice] = useState("");
  const [busy, setBusy] = useState(false);

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
    setBusy(true);
    setNotice("");
    api("create_room", {
      maxRacers: racers,
      maxJoiners: Math.max(racers, souls),
    })
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
    api("create_practice", { racers: racers })
      .then(function (data) {
        setBusy(false);
        localStorage.setItem("chariot.practiceId", data.practiceId);
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
            <span className="tally">{Math.max(racers, souls)}</span>
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
