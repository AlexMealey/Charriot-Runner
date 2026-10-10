/* ------------------------------ Race view --------------------------- *
 * The canvas hall of the hippodrome. Drives practice races today and    *
 * shared room races once the backend rooms arise.                       *
 * ------------------------------------------------------------------- */
function RaceView(props) {
  const aliveRef = useRef(true);
  const rafRef = useRef(0);
  const timerRef = useRef(0);
  const canvasRef = useRef(null);
  const raceRef = useRef(null); // view state handed to draw()
  const prevRef = useRef(null); // second-recent practice snapshot
  const currRef = useRef(null); // most recent practice snapshot
  const timeRef = useRef({ prev: 0, curr: 0 });
  const idRef = useRef(
    props.practiceId || localStorage.getItem("chariot.practiceId") || ""
  );
  const nRef = useRef(4);
  const fracRef = useRef(1);
  const doneRef = useRef(false);
  const trackRef = useRef(null);
  const [label, setLabel] = useState("Summoning the chariots…");

  useEffect(function () {
    const canvas = canvasRef.current;
    const ctx = canvas.getContext("2d");
    function resize() {
      const dpr = window.devicePixelRatio || 1;
      const w = window.innerWidth;
      const h = window.innerHeight;
      canvas.width = w * dpr;
      canvas.height = h * dpr;
      canvas.style.width = w + "px";
      canvas.style.height = h + "px";
      ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
      trackRef.current = buildTrack(w, h, nRef.current);
      draw(ctx, w, h, trackRef.current, raceRef.current);
    }
    window.addEventListener("resize", resize);
    resize();
    // repaint once the medieval faces arrive
    if (document.fonts && document.fonts.ready) {
      document.fonts.ready.then(function () {
        draw(ctx, window.innerWidth, window.innerHeight, trackRef.current, raceRef.current);
      });
    }
    /* Positions come only from practice_state polls: keep the last two
     * snapshots and lerp racers[].p between them every frame. */
    function viewState() {
      const curr = currRef.current;
      if (!curr) return null;
      const prev = prevRef.current || curr;
      const span = timeRef.current.curr - timeRef.current.prev;
      let frac = 1;
      if (span > 0) {
        frac = Math.min(1, Math.max(0, (performance.now() - timeRef.current.prev) / span));
      }
      fracRef.current = frac;
      return {
        tick: curr.tick,
        t: curr.t,
        n: curr.n,
        names: curr.names,
        finished: curr.finished,
        placements: curr.placements,
        racers: curr.racers.map(function (rc, i) {
          const before = prev.racers[i] || rc;
          return {
            lane: rc.lane,
            p: before.p + (rc.p - before.p) * frac,
            done: rc.done,
            place: rc.place,
          };
        }),
      };
    }

    function frame() {
      if (!aliveRef.current) return;
      const view = viewState();
      if (view) {
        raceRef.current = view;
        draw(ctx, window.innerWidth, window.innerHeight, trackRef.current, view);
      }
      if (!doneRef.current || fracRef.current < 1) {
        rafRef.current = requestAnimationFrame(frame);
      }
    }
    rafRef.current = requestAnimationFrame(frame);

    function accept(data) {
      const had = currRef.current;
      prevRef.current = had;
      currRef.current = data;
      const now = performance.now();
      timeRef.current = { prev: had ? timeRef.current.curr : now, curr: now };
      if (data.n && data.n !== nRef.current) {
        nRef.current = data.n;
        trackRef.current = buildTrack(window.innerWidth, window.innerHeight, nRef.current);
      }
      if (data.finished >= data.n) {
        doneRef.current = true;
        setLabel("The race is run");
      } else {
        setLabel("Racing…");
      }
    }

    function poll() {
      // Rooms ride room_state (250 ms, step 4.4); practice keeps its own feed.
      const url = props.roomId
        ? "api/?action=room_state&room=" + encodeURIComponent(props.roomId)
        : "api/?action=practice_state&race=" + encodeURIComponent(idRef.current);
      fetch(url, { cache: "no-store" })
        .then(function (res) {
          if (res.status === 404) {
            doneRef.current = true;
            setLabel("This scroll hath turned to dust");
            return null;
          }
          return res.ok ? res.json() : null;
        })
        .then(function (data) {
          if (!aliveRef.current || !data) return;
          if (props.roomId) {
            // The race snapshot rides beside `names` (nicknames by lane).
            if (data.race) {
              accept(Object.assign({}, data.race, { names: data.names }));
            }
          } else {
            accept(data);
          }
          if (!doneRef.current) timerRef.current = setTimeout(poll, 250);
        })
        .catch(function () {
          // a wavered link simply polls again (the notice arrives in 4.8)
          if (aliveRef.current && !doneRef.current) {
            timerRef.current = setTimeout(poll, 250);
          }
        });
    }

    // An id from the landing or localStorage starts polling at once;
    // without one, open a fresh practice race and keep its id + snapshot.
    // A room rides room_state (step 4.4) — never summon a practice race
    // on its behalf.
    function begin() {
      if (props.roomId) {
        poll();
        return;
      }
      if (idRef.current) {
        poll();
        return;
      }
      api("create_practice", { racers: 4 })
        .then(function (data) {
          idRef.current = data.practiceId;
          localStorage.setItem("chariot.practiceId", data.practiceId);
          if (data.snapshot) accept(data.snapshot);
          poll();
        })
        .catch(function () {
          if (aliveRef.current) timerRef.current = setTimeout(begin, 1000);
        });
    }
    begin();

    return function () {
      aliveRef.current = false;
      clearTimeout(timerRef.current);
      cancelAnimationFrame(rafRef.current);
      window.removeEventListener("resize", resize);
    };
  }, []);

  return (
    <React.Fragment>
      <canvas id="game" ref={canvasRef} />
      <div className="top">
        <button disabled>{label}</button>
      </div>
      <a
        className="gate-link"
        href="/"
        onClick={function (e) {
          e.preventDefault();
          props.navigate("/");
        }}
      >
        ◂ The Gates
      </a>
    </React.Fragment>
  );
}
