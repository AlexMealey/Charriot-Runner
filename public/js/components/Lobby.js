/* -------------------------------- Lobby ----------------------------- *
 * Post-join gathering: how many seats are filled, then every soul in   *
 * the room — nickname, gold role tag, a chariot swatch for the riders. *
 * The host badge shows only where the host token lives (spec §2).      *
 * -------------------------------------------------------------------- */
function Lobby(props) {
  const [copied, setCopied] = useState(false);
  const [busy, setBusy] = useState(false);
  const [notice, setNotice] = useState("");
  const [snap, setSnap] = useState(props.session.snapshot);
  // The room vanished under us (404): the dust scroll stands in (spec §6).
  const [lost, setLost] = useState(false);
  const isHost = !!localStorage.getItem("chariot.host." + props.roomId);
  const url = window.location.origin + "/?room=" + props.roomId;
  // Thine own wager as the roster poll last carried it (4.7): the radio
  // moves at once on click, and every poll — or a refusal — reconciles it.
  const me = snap.participants.find(function (p) {
    return p.id === props.session.participantId;
  });
  const myPick = me ? me.prediction : null;
  const [pick, setPick] = useState(myPick);
  useEffect(function () {
    setPick(myPick);
  }, [myPick]);
  const riders = snap.participants.filter(function (p) {
    return p.role === "racer";
  });
  const racers = riders.length;
  const backed =
    pick === null
      ? null
      : riders.find(function (p) {
          return p.lane === pick;
        });
  // Horses rooms (8.4/8.5): each rider's steed and readiness, and the
  // trumpet's grace — counted on the server, carried by every poll.
  const custom = (snap.config.mode || "classic") === "custom";
  const allReady = riders.every(function (p) {
    return p.ready;
  });
  const graceLeft = snap.trumpetLeft === undefined ? null : snap.trumpetLeft;
  const waiting = custom && !allReady && graceLeft !== null && graceLeft > 0;
  const forceable = custom && !allReady && graceLeft !== null && graceLeft <= 0;
  const canStart = isHost && racers >= 2 && !waiting;
  // Which reason a disabled trumpet shows: role first, then the count
  // (a running grace speaks for itself in the line above the trumpet).
  const reason = !isHost
    ? "the host alone may call the race"
    : racers < 2
    ? "two riders at least"
    : null;
  const graceNote = waiting
    ? "The trumpet hath sounded — " + Math.ceil(graceLeft) + " s to make ready"
    : forceable
    ? isHost
      ? "The grace is spent — thou mayest start regardless"
      : "The grace is spent — the host may start regardless"
    : custom && isHost && !allReady && racers >= 2
    ? "Not every rider is ready — the trumpet grants them 30 s"
    : null;
  // Thine own steed and readiness, moved at once on click and reconciled
  // by every poll — or rolled back by a refusal (as the wager is).
  const myHorse = me ? me.horse : null;
  const myReady = me ? !!me.ready : false;
  const [steed, setSteed] = useState(myHorse);
  useEffect(function () {
    setSteed(myHorse);
  }, [myHorse]);
  const [readyNow, setReadyNow] = useState(myReady);
  useEffect(function () {
    setReadyNow(myReady);
  }, [myReady]);
  // The stables (8.2): names and stats straight from the server's roster.
  const [stable, setStable] = useState([]);
  useEffect(function () {
    if (!custom) return;
    let alive = true;
    fetch("api/?action=horses")
      .then(function (res) {
        return res.ok ? res.json() : null;
      })
      .then(function (data) {
        if (alive && data && data.horses) setStable(data.horses);
      })
      .catch(function () {
        /* no roster: Random still rides */
      });
    return function () {
      alive = false;
    };
  }, [custom]);

  // Live roster: joins and leaves land in every browser within 250 ms.
  // Once the race is on, the lobby stops polling — the race view takes over.
  useEffect(function () {
    if (snap.status !== "lobby") return;
    const q =
      "api/?action=room_state&room=" +
      encodeURIComponent(props.roomId) +
      "&token=" +
      encodeURIComponent(props.session.token);
    return poll(q, 250, setSnap, function () {
      setLost(true);
    });
  }, [snap.status]);

  /* Quitting the lobby frees the seat (3.5); the kept identity lets the
   * same soul re-attach later (4.9). Fire and forget — the gates open
   * whether or not the herald hears. */
  function home(e) {
    e.preventDefault();
    api("leave_room", {
      roomId: props.roomId,
      token: props.session.token,
    }).catch(function () {});
    props.navigate("/");
  }

  function flashCopied() {
    setCopied(true);
    setTimeout(function () {
      setCopied(false);
    }, 2000);
  }

  /* Clipboard API fallback: a hidden textarea + execCommand. */
  function legacyCopy() {
    const ta = document.createElement("textarea");
    ta.value = url;
    ta.setAttribute("readonly", "");
    ta.style.position = "fixed";
    ta.style.opacity = "0";
    document.body.appendChild(ta);
    ta.select();
    let ok = false;
    try {
      ok = document.execCommand("copy");
    } catch (e) {
      ok = false;
    }
    document.body.removeChild(ta);
    return ok;
  }

  function copyLink() {
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(url).then(flashCopied, function () {
        if (legacyCopy()) flashCopied();
      });
    } else if (legacyCopy()) {
      flashCopied();
    }
  }

  /* The trumpet calls start_race; the roster poll then sees the room turn
   * to racing, and every open lobby rides to the race view without reload. */
  function startRace() {
    setBusy(true);
    setNotice("");
    api("start_race", {
      roomId: props.roomId,
      hostToken: localStorage.getItem("chariot.host." + props.roomId),
      // once the grace is spent the host starts regardless (8.5)
      force: forceable,
    })
      .then(function () {
        // stay busy: the race is begun, and the next roster poll carries
        // this lobby to the race view — a second blast would only be
        // refused
      })
      .catch(function (err) {
        setBusy(false);
        setNotice(err.message);
      });
  }

  /* Backing a rider calls set_prediction at once (4.6); the roster poll
   * then carries the pick to every other soul — a refusal rolls it back. */
  function backWinner(lane) {
    setPick(lane);
    setNotice("");
    api("set_prediction", {
      roomId: props.roomId,
      token: props.session.token,
      pick: lane,
    }).catch(function (err) {
      setPick(myPick);
      setNotice(err.message);
    });
  }

  /* Choosing a steed (8.4): null means Random. */
  function chooseSteed(value) {
    const horse = value || null;
    setSteed(horse);
    setNotice("");
    api("set_horse", {
      roomId: props.roomId,
      token: props.session.token,
      horse: horse,
    }).catch(function (err) {
      setSteed(myHorse);
      setNotice(err.message);
    });
  }

  /* Ready locks the steed; standing down unlocks it (8.4). */
  function toggleReady() {
    const next = !readyNow;
    setReadyNow(next);
    setNotice("");
    api("set_ready", {
      roomId: props.roomId,
      token: props.session.token,
      ready: next,
    }).catch(function (err) {
      setReadyNow(myReady);
      setNotice(err.message);
    });
  }

  if (lost) return <DustScroll navigate={props.navigate} />;

  // Racing elsewhere (or already run): this lobby is over — hand over to
  // the shared race view.
  if (snap.status !== "lobby") {
    return <RaceView roomId={props.roomId} navigate={props.navigate} />;
  }

  return (
    <div className="stage">
      <div className="card lobby">
        <h1>The Hippodrome</h1>
        <div className="share">
          <span className="roomurl">{url}</span>
          <button
            className={copied ? "copy copied" : "copy"}
            onClick={copyLink}
          >
            {copied ? "Copied!" : "Copy link"}
          </button>
        </div>
        <p className="tally seats">
          {snap.participants.length} of {snap.config.maxJoiners} seats
          filled
        </p>
        <ul className="roster">
          {snap.participants.map(function (p) {
            const mine = p.id === props.session.participantId;
            return (
              <li key={p.id} className="roster-row">
                <span
                  className="swatch"
                  style={{
                    background:
                      p.role === "racer"
                        ? RACERS[p.lane].color
                        : "transparent",
                  }}
                />
                <span className="nick">{p.nickname}</span>
                {custom && p.role === "racer" ? (
                  <span className="steed">
                    {p.horse ? horseName(p.horse) : "Random"}
                    {p.ready ? <span className="readymark"> ✓</span> : null}
                  </span>
                ) : null}
                <span className="role">
                  {p.role === "racer" ? "rider" : "onlooker"}
                </span>
                {isHost && mine ? (
                  <span className="hostbadge">⚜ host</span>
                ) : null}
              </li>
            );
          })}
        </ul>
        {custom && me && me.role === "racer" ? (
          <div className="steedpick">
            <h2>Thy steed</h2>
            <div className="steed-row">
              <SteedPicker
                stable={stable}
                value={steed}
                color={RACERS[me.lane].color}
                disabled={readyNow}
                onChange={chooseSteed}
              />
              <button
                className={readyNow ? "ready on" : "ready"}
                onClick={toggleReady}
              >
                {readyNow ? "Ready ✓" : "Make ready"}
              </button>
            </div>
          </div>
        ) : null}
        {riders.length ? (
          <div className="backing">
            <h2>Back the winner</h2>
            <ul className="picks">
              {riders.map(function (p) {
                return (
                  <li key={p.id}>
                    <label>
                      <input
                        type="radio"
                        name="prediction"
                        checked={pick === p.lane}
                        onChange={function () {
                          backWinner(p.lane);
                        }}
                      />
                      <span
                        className="swatch"
                        style={{ background: RACERS[p.lane].color }}
                      />
                      <span className="nick">{p.nickname}</span>
                    </label>
                  </li>
                );
              })}
            </ul>
            {backed ? (
              <p className="backed">thou backest: {backed.nickname}</p>
            ) : null}
          </div>
        ) : null}
        {notice ? <p className="notice">{notice}</p> : null}
        {graceNote ? <p className="grace">{graceNote}</p> : null}
        <div className="menu">
          <button
            className="trumpet"
            disabled={!canStart || busy}
            onClick={startRace}
          >
            {forceable ? "Start regardless" : "Raise the Trumpet"} —{" "}
            {racers} riders
          </button>
          {reason ? <p className="reason">{reason}</p> : null}
          <a className="linkish" href="/" onClick={home}>
            ◂ The Gates
          </a>
        </div>
      </div>
    </div>
  );
}
