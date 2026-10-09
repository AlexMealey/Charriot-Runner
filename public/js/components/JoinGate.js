/* ------------------------------ Join gate ---------------------------- *
 * Shown at /?room=<id> before entering: a name for the heralds, rolled *
 * afresh with the dice. Once join_room answers, the lobby takes over.    *
 * ------------------------------------------------------------------- */
function JoinGate(props) {
  const [nickname, setNickname] = useState(wordName);
  const [busy, setBusy] = useState(false);
  const [notice, setNotice] = useState("");
  const [lost, setLost] = useState(false);
  const [session, setSession] = useState(null);

  // Reload keeps thy identity: the cached join (step 3.6) is re-checked
  // against the live roster and lifted straight back into the lobby.
  useEffect(function () {
    let alive = true;
    let raw = null;
    try {
      raw = localStorage.getItem("chariot.player." + props.roomId);
    } catch (e) {
      raw = null;
    }
    if (!raw) return function () { alive = false; };
    let cached = null;
    try {
      cached = JSON.parse(raw);
    } catch (e) {
      cached = null;
    }
    if (!cached || !cached.participantId) {
      localStorage.removeItem("chariot.player." + props.roomId);
      return function () { alive = false; };
    }
    fetch("api/?action=room_state&room=" + encodeURIComponent(props.roomId))
      .then(function (res) { return res.ok ? res.json() : null; })
      .then(function (snap) {
        if (!alive || !snap || !Array.isArray(snap.participants)) return;
        const mine = snap.participants.some(function (p) {
          return p.id === cached.participantId;
        });
        if (mine) {
          setSession({
            participantId: cached.participantId,
            token: cached.token,
            role: cached.role,
            snapshot: snap,
          });
        } else {
          // gone from the roster (left, or room expired mid-join)
          localStorage.removeItem("chariot.player." + props.roomId);
        }
      })
      .catch(function () { /* wavering link: the join form simply stays */ });
    return function () { alive = false; };
  }, [props.roomId]);

  if (lost) return <DustScroll navigate={props.navigate} />;

  function home(e) {
    e.preventDefault();
    props.navigate("/");
  }

  function roll() {
    setNickname(wordName());
  }

  function join() {
    const name = nickname.trim();
    if (!name) {
      setNotice("The heralds need a name to cry.");
      return;
    }
    setBusy(true);
    setNotice("");
    api("join_room", { roomId: props.roomId, nickname: name })
      .then(function (data) {
        setBusy(false);
        setSession(data);
        localStorage.setItem(
          "chariot.player." + props.roomId,
          JSON.stringify(data)
        );
      })
      .catch(function (err) {
        setBusy(false);
        if (err.code === "room_not_found") {
          setLost(true);
          return;
        }
        setNotice(err.message);
      });
  }

  if (session) {
    return (
      <Lobby
        roomId={props.roomId}
        session={session}
        navigate={props.navigate}
      />
    );
  }

  return (
    <div className="stage">
      <div className="card">
        <h1>Enter the Hippodrome</h1>
        <p className="mark">{props.roomId}</p>
        <div className="field">
          <label>What name shall the heralds cry?</label>
          <div className="name-row">
            <input
              type="text"
              maxLength="24"
              value={nickname}
              onChange={function (e) {
                setNickname(e.target.value);
              }}
              onKeyDown={function (e) {
                if (e.key === "Enter") join();
              }}
            />
            <button
              className="dice"
              onClick={roll}
              title="Roll a new name"
              aria-label="Roll a new name"
            >
              ⚄
            </button>
          </div>
        </div>
        {notice ? <p className="notice">{notice}</p> : null}
        <div className="menu">
          <button onClick={join} disabled={busy}>
            {busy ? "Seeking…" : "Join the Race"}
          </button>
          <a className="linkish" href="/" onClick={home}>
            ◂ The Gates
          </a>
        </div>
      </div>
    </div>
  );
}
