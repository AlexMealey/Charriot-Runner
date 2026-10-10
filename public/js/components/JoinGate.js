/* ------------------------------ Join gate ---------------------------- *
 * Shown at /?room=<id> before entering: a name for the heralds, rolled *
 * afresh with the dice. Once join_room answers, the lobby takes over —     *
 * or the race view when the contest already runs (step 4.5).              *
 * ------------------------------------------------------------------- */
function JoinGate(props) {
  const [nickname, setNickname] = useState(wordName);
  const [busy, setBusy] = useState(false);
  const [notice, setNotice] = useState("");
  const [lost, setLost] = useState(false);
  const [session, setSession] = useState(null);

  // Thine identity survives the quitting (step 4.9): the kept id+token is
  // offered to join_room first — a match re-attaches the same player (name
  // and wager kept, no second roster row) — and only when no scroll is kept
  // do the heralds ask for a name, the cached one already written in it.
  useEffect(function () {
    let alive = true;
    const key = "chariot.player." + props.roomId;
    let raw = null;
    try {
      raw = localStorage.getItem(key);
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
    if (!cached || !cached.participantId || !cached.token) {
      localStorage.removeItem(key);
      return function () { alive = false; };
    }
    const nick = typeof cached.nickname === "string" ? cached.nickname : "";
    if (nick) setNickname(nick);
    api("join_room", {
      roomId: props.roomId,
      nickname: nick || wordName(),
      participantId: cached.participantId,
      token: cached.token,
    })
      .then(function (data) {
        if (!alive) return;
        cacheSession(data);
        setSession(data);
      })
      .catch(function (err) {
        if (!alive) return;
        if (err.code === "room_not_found") {
          localStorage.removeItem(key);
          setLost(true);
          return;
        }
        // a full room or a wavering link: the heralds ask, name pre-filled
        if (err.message) setNotice(err.message);
      });
    return function () { alive = false; };
  }, [props.roomId]);

  /* The kept identity (steps 3.6/4.9): id, token, role, and the name the
   * roster actually shows — a re-attach keeps the stored one. */
  function cacheSession(data) {
    let nick = nickname;
    (data.snapshot.participants || []).some(function (p) {
      if (p.id === data.participantId) {
        nick = p.nickname;
        return true;
      }
      return false;
    });
    try {
      localStorage.setItem(
        "chariot.player." + props.roomId,
        JSON.stringify({
          participantId: data.participantId,
          token: data.token,
          role: data.role,
          nickname: nick,
          snapshot: data.snapshot,
        })
      );
    } catch (e) {
      /* a full chest keeps no scroll */
    }
  }

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
    // The cached identity rides along (4.9): a still-known player is
    // re-attached rather than inscribed twice; a stale one falls through.
    let cached = null;
    try {
      cached = JSON.parse(localStorage.getItem("chariot.player." + props.roomId));
    } catch (e) {
      cached = null;
    }
    api("join_room", {
      roomId: props.roomId,
      nickname: name,
      participantId: cached ? cached.participantId : "",
      token: cached ? cached.token : "",
    })
      .then(function (data) {
        setBusy(false);
        setSession(data);
        cacheSession(data);
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
    // Mid-race (or already run): the lobby is over — hand straight to the
    // shared race view at the current tick (step 4.5). A reload takes this
    // same path through the cached identity above.
    if (session.snapshot && session.snapshot.status !== "lobby") {
      return <RaceView roomId={props.roomId} navigate={props.navigate} />;
    }
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
