/* ---------------------------- Results card -------------------------- *
 * A parchment raised over the frozen track: the finishing order in      *
 * ordinals, each with its chariot swatch and the rider's name — the     *
 * swatch tells duplicate names apart. Beneath it stand the wagerers:    *
 * everyone who sealed a pick, with a gold "true seer" tag for those     *
 * who backed the winner and the rest left dim. Placements, names and    *
 * the roster arrive as props; only Race Again speaks to the server.     *
 * -------------------------------------------------------------------- */
function ResultsCard(props) {
  const [busy, setBusy] = useState(false);
  const [notice, setNotice] = useState("");
  // Rooms gate the button on the host token (5.4); practice (5.5) carries
  // no room, so its button always rides.
  const isRoom = !!props.roomId;
  const isHost =
    isRoom && !!localStorage.getItem("chariot.host." + props.roomId);
  const order = (props.placements || [])
    .slice()
    .sort(function (a, b) {
      return a.place - b.place;
    });
  // Roster order kept — riders and onlookers alike; souls who never
  // picked stand outside the list.
  const backers = (props.participants || []).filter(function (p) {
    return p.prediction !== null;
  });
  const winner = order.find(function (r) {
    return r.place === 1;
  });

  /* The twin of Lobby's trumpet (5.4): the rematch posts the host token,
   * a refusal rolls its message up, and success hands the view back to
   * RaceView — which drops the card and spins the feed up again. Practice
   * (5.5) posts nothing: Race Again walks straight back to the picker. */
  function raceAgain() {
    if (!isRoom) {
      props.onRaceAgain();
      return;
    }
    setBusy(true);
    setNotice("");
    api("rematch", {
      roomId: props.roomId,
      hostToken: localStorage.getItem("chariot.host." + props.roomId),
    })
      .then(function () {
        setBusy(false);
        props.onRaceAgain();
      })
      .catch(function (err) {
        setBusy(false);
        setNotice(err.message);
      });
  }
  return (
    <div className="stage results">
      <div className="card">
        <h1>The Race Is Run</h1>
        <ul className="placings">
          {order.map(function (r) {
            return (
              <li key={r.lane} className="placing">
                <span className="place">{ORDINAL[r.place - 1]}</span>
                <span
                  className="swatch"
                  style={{ background: RACERS[r.lane].color }}
                />
                <span className="nick">
                  {props.names[r.lane]}
                  {/* horses races (8.8): the steed under the rider */}
                  {props.horses ? (
                    <small className="steed">{horseName(props.horses[r.lane])}</small>
                  ) : null}
                </span>
              </li>
            );
          })}
        </ul>
        {backers.length ? (
          <ul className="placings">
            {backers.map(function (p) {
              const seer = !!winner && p.prediction === winner.lane;
              return (
                <li
                  key={p.id}
                  className={seer ? "placing seer" : "placing dim"}
                >
                  <span className="nick">{p.nickname}</span>
                  <span
                    className="swatch"
                    style={{ background: RACERS[p.prediction].color }}
                  />
                  <span className="nick">{props.names[p.prediction]}</span>
                </li>
              );
            })}
          </ul>
        ) : null}
        {notice ? <p className="notice">{notice}</p> : null}
        <div className="menu">
          <button
            className="trumpet"
            disabled={isRoom && (!isHost || busy)}
            onClick={raceAgain}
          >
            Race Again
          </button>
          {isRoom && !isHost ? (
            <p className="reason">the host alone may call the race</p>
          ) : null}
        </div>
      </div>
    </div>
  );
}
