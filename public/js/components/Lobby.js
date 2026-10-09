/* -------------------------------- Lobby ----------------------------- *
 * Post-join gathering: how many seats are filled, then every soul in   *
 * the room — nickname, gold role tag, a chariot swatch for the riders. *
 * The host badge shows only where the host token lives (spec §2).      *
 * -------------------------------------------------------------------- */
function Lobby(props) {
  const [copied, setCopied] = useState(false);
  const [snap, setSnap] = useState(props.session.snapshot);
  const isHost = !!localStorage.getItem("chariot.host." + props.roomId);
  const url = window.location.origin + "/?room=" + props.roomId;
  const racers = snap.participants.filter(function (p) {
    return p.role === "racer";
  }).length;
  const canStart = isHost && racers >= 2;
  // Which reason a disabled trumpet shows: role first, then the count.
  const reason = !isHost
    ? "the host alone may call the race"
    : "two riders at least";

  // Live roster: joins and leaves land in every browser within 250 ms.
  useEffect(function () {
    const q =
      "api/?action=room_state&room=" +
      encodeURIComponent(props.roomId) +
      "&token=" +
      encodeURIComponent(props.session.token);
    return poll(q, 250, setSnap);
  }, []);

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
        <div className="menu">
          {/* Deliberately unwired: Stage 4 raises the trumpet. */}
          <button className="trumpet" disabled={!canStart}>
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
