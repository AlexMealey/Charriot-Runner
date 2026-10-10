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
    /* Smoothing (4.10): the race view relaxes the 250 ms lock to ~500 ms —
     * hidden tabs still throttle timers to ~1 s — and the frame loop below
     * keeps the picture gliding in between the polls. */
    const POLL_MS = 500;
    const LAG_MS = 500; // the picture runs one poll behind the newest tick
    const SNAP_MS = 1500; // a gap this long is a jump: snap, never ease it
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
    /* Positions come only from state polls (spec §3): the two newest
     * snapshots form a segment on the arrival-time axis, and the frame
     * loop plays it back one poll behind real time — easing between the
     * two snapshots, gliding on the last velocity while the next is late,
     * and never past the line before the ticks record it. Labels and
     * placements ride the ticks unchanged: smoothing moves pictures,
     * never standings. */
    function viewState() {
      const curr = currRef.current;
      if (!curr) return null;
      const prev = prevRef.current || curr;
      const span = Math.max(
        timeRef.current.curr - timeRef.current.prev,
        LAG_MS
      );
      // one poll behind, gliding at most one poll past the newest
      // snapshot — then the picture holds for the next one
      const shown = Math.min(
        performance.now() - LAG_MS,
        timeRef.current.curr + LAG_MS
      );
      const frac = Math.max(0, (shown - timeRef.current.prev) / span);
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
          let p = before.p + (rc.p - before.p) * frac; // frac > 1 glides on
          // a winner glides no further than the line the ticks record,
          // and no chariot wraps the loop before the server says done
          p = rc.done ? Math.min(p, rc.p) : Math.min(p, 1);
          return {
            lane: rc.lane,
            p: p,
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
      const now = performance.now();
      // A snapshot that jumps — a wavered link, a backoff, a reconnect —
      // snaps the picture to server truth instead of easing across the gap.
      const jumped =
        !had || data.tick < had.tick || now - timeRef.current.curr > SNAP_MS;
      prevRef.current = jumped ? data : had;
      currRef.current = data;
      timeRef.current = jumped
        ? { prev: now, curr: now }
        : { prev: timeRef.current.curr, curr: now };
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
      // Rooms ride room_state (step 4.4); practice keeps its own feed —
      // both at the relaxed race-view rate (4.10).
      const url = props.roomId
        ? "api/?action=room_state&room=" + encodeURIComponent(props.roomId)
        : "api/?action=practice_state&race=" + encodeURIComponent(idRef.current);
      fetch(url, { cache: "no-store" })
        .then(function (res) {
          if (res.status === 404) {
            waver(true); // the link itself answered
            doneRef.current = true;
            setLabel("This scroll hath turned to dust");
            return null;
          }
          // a refusal rides the failure path below, and polls on
          if (!res.ok) throw new Error("poll " + res.status);
          return res.json();
        })
        .then(function (data) {
          if (!aliveRef.current || !data) return;
          waver(true);
          if (props.roomId) {
            // The race snapshot rides beside `names` (nicknames by lane).
            if (data.race) {
              accept(Object.assign({}, data.race, { names: data.names }));
            }
          } else {
            accept(data);
          }
          if (!doneRef.current) timerRef.current = setTimeout(poll, POLL_MS);
        })
        .catch(function () {
          // a wavered link simply polls again (the notice shows it)
          waver(false);
          if (aliveRef.current && !doneRef.current) {
            timerRef.current = setTimeout(poll, POLL_MS);
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
