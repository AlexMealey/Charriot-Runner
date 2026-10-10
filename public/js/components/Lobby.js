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
  const canStart = isHost && racers >= 2;
  // Which reason a disabled trumpet shows: role first, then the count.
  const reason = !isHost
    ? "the host alone may call the race"
    : "two riders at least";

  // Live roster: joins and leaves land in every browser within 250 ms.
  // Once the race is on, the lobby stops polling — the race view takes over.
  useEffect(function () {
    if (snap.status !== "lobby") return;
    const q =
      "api/?action=room_state&room=" +
      encodeURIComponent(props.roomId) +
      "&token=" +
      encodeURIComponent(props.session.token);
    return poll(q, 250, setSnap);
  }, [snap.status]);

  function home(e) {
    e.preventDefault();
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
    })
      .then(function () {
        setBusy(false);
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
        <div className="menu">
          <button
            className="trumpet"
            disabled={!canStart || busy}
            onClick={startRace}
          >
            Raise the Trumpet — {racers} riders
          </button>
          {canStart ? null : <p className="reason">{reason}</p>}
          <a className="linkish" href="/" onClick={home}>
            ◂ The Gates
          </a>
        </div>
      </div>
    </div>
  );
}
