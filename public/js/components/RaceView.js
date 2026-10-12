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
  const idRef = useRef(props.practiceId || "");
  const nRef = useRef(4);
  const fracRef = useRef(1);
  const doneRef = useRef(false);
  const trackRef = useRef(null);
  // a horses race's course plot (8.7), fetched once per course — a map
  // course (Stage 9) once per race, keyed by the snapshot's courseKey
  const plotRef = useRef(null);
  const plotSlugRef = useRef("");
  const restartRef = useRef(null); // the feed effect's start-over (5.4)
  const [label, setLabel] = useState("Summoning the chariots…");
  // The room's finished verdict with the standings and wagerers it carries
  // (5.1–5.2): while set, the results card stands in place of the finish
  // label.
  const [results, setResults] = useState(null);
  // An unknown or expired race (404) yields to the dust scroll (spec §6).
  const [lost, setLost] = useState(false);

  useEffect(function () {
    /* Smoothing (4.10): the race view relaxes the 250 ms lock to ~500 ms —
     * hidden tabs still throttle timers to ~1 s — and the frame loop below
     * keeps the picture gliding in between the polls. */
    const POLL_MS = 500;
    const LAG_MS = 500; // the picture runs one poll behind the newest tick
    const SNAP_MS = 1500; // a gap this long is a jump: snap, never ease it
    const canvas = canvasRef.current;
    const ctx = canvas.getContext("2d");
    // The course to draw: a horses race's plotted course once it has
    // arrived, else today's n-lane oval.
    function layout(w, h) {
      return plotRef.current
        ? buildCourse(w, h, plotRef.current)
        : buildTrack(w, h, nRef.current);
    }
    function resize() {
      const dpr = window.devicePixelRatio || 1;
      const w = window.innerWidth;
      const h = window.innerHeight;
      canvas.width = w * dpr;
      canvas.height = h * dpr;
      canvas.style.width = w + "px";
      canvas.style.height = h + "px";
      ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
      trackRef.current = layout(w, h);
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
        // horses races (Stage 8): mode, laps and every slot's steed
        mode: curr.mode,
        laps: curr.laps,
        horses: curr.horses,
        racers: curr.racers.map(function (rc, i) {
          const before = prev.racers[i] || rc;
          let p = before.p + (rc.p - before.p) * frac; // frac > 1 glides on
          // a winner glides no further than the line the ticks record,
          // and no chariot wraps the loop before the server says done
          p = rc.done ? Math.min(p, rc.p) : Math.min(p, 1);
          const out = {
            lane: rc.lane,
            p: p,
            done: rc.done,
            place: rc.place,
          };
          // a horse changing lanes eases across over one poll (8.7)
          if (rc.side !== undefined) {
            const from = before.side !== undefined ? before.side : rc.side;
            out.side = from + (rc.side - from) * Math.min(1, frac);
          }
          return out;
        }),
      };
    }

    function frame() {
      if (!aliveRef.current) return;
      const view = viewState();
      // a horses race waits for its course before the first picture
      const ready = view && (view.mode !== "custom" || trackRef.current.course);
      if (ready) {
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
        trackRef.current = layout(window.innerWidth, window.innerHeight);
      }
      if (data.mode === "custom") {
        const key = data.track + "|" + (data.courseKey || "");
        if (key !== plotSlugRef.current) loadCourse(data.track, key);
      }
      if (data.finished >= data.n) {
        doneRef.current = true;
        setLabel("The race is run");
      } else {
        setLabel("Racing…");
      }
    }

    /* A horses race's course comes from the server (8.1/8.7): fetched
     * once per slug, then laid out like the oval. A failed fetch clears
     * the slug so the next snapshot asks again. */
    function loadCourse(track, key) {
      plotSlugRef.current = key;
      // hold the picture until the new course arrives — never a new race
      // drawn on the last one's road
      plotRef.current = null;
      trackRef.current = layout(window.innerWidth, window.innerHeight);
      // a map course (Stage 9) is this race's own loop; a drawn one is shared
      const url =
        track.indexOf("map:") === 0
          ? "api/?action=course&" +
            (props.roomId
              ? "room=" + encodeURIComponent(props.roomId)
              : "practice=" + encodeURIComponent(idRef.current))
          : "api/?action=track&slug=" + encodeURIComponent(track);
      fetch(url, { cache: "no-store" })
        .then(function (res) {
          if (!res.ok) throw new Error("course " + res.status);
          return res.json();
        })
        .then(function (plot) {
          if (!aliveRef.current || plotSlugRef.current !== key) return;
          plotRef.current = plot;
          trackRef.current = layout(window.innerWidth, window.innerHeight);
        })
        .catch(function () {
          if (plotSlugRef.current === key) plotSlugRef.current = "";
        });
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
            if (aliveRef.current) setLost(true);
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
            // The room itself turns "finished" once the race is run: that
            // verdict raises the results card, and `accept` above has
            // already fallen the 500 ms feed silent (5.1). The card keeps
            // its raceNo so the watch below knows a newer race (5.4).
            if (data.status === "finished" && data.race) {
              raiseResults({
                raceNo: data.raceNo,
                placements: data.race.placements,
                names: data.names,
                horses: data.race.horses,
                participants: data.participants,
              });
            }
          } else {
            accept(data);
            // Practice wears the same parchment (5.5): placements only —
            // no participants ride along, so no seers block, and Race
            // Again walks back to the picker instead of calling rematch.
            if (data.finished >= data.n) {
              raiseResults({
                placements: data.placements,
                names: data.names,
                horses: data.horses,
              });
            }
          }
          if (!doneRef.current) {
            timerRef.current = setTimeout(poll, pollDelay(POLL_MS)); // 1 s hidden (7.4)
          }
        })
        .catch(function () {
          // a wavered link simply polls again (the notice shows it) — but
          // a view already left raises no notice over the next one
          if (!aliveRef.current) return;
          waver(false);
          if (!doneRef.current) {
            timerRef.current = setTimeout(poll, pollDelay(POLL_MS)); // 1 s hidden (7.4)
          }
        });
    }

    // The picture runs one poll behind the ticks (4.10): raise the card
    // once it has caught up, so the last chariots cross the line in plain
    // sight rather than under the parchment.
    function raiseResults(res) {
      setTimeout(function () {
        if (aliveRef.current) setResults(res);
      }, LAG_MS);
    }

    // Both feeds start polling at once: a room rides room_state (step
    // 4.4); practice rides the id in /?practice=<id> — the landing's Ride!
    // opened that race, and a reload keeps the same URL, so the same race.
    poll();

    /* A rematch begins the view anew (5.4): the card drops, the segment
     * refs blank so the fresh race snaps in from tick 0, and both loops
     * spin again — the host's api answer and the slow watch both land
     * here. Only a finished view restarts, so a second caller racing the
     * first cannot spin a second feed. */
    function restart() {
      if (!doneRef.current) return;
      clearTimeout(timerRef.current);
      doneRef.current = false;
      prevRef.current = null;
      currRef.current = null;
      timeRef.current = { prev: 0, curr: 0 };
      setResults(null);
      setLabel("Summoning the chariots…");
      cancelAnimationFrame(rafRef.current);
      rafRef.current = requestAnimationFrame(frame);
      poll();
    }
    restartRef.current = restart;

    return function () {
      aliveRef.current = false;
      clearTimeout(timerRef.current);
      cancelAnimationFrame(rafRef.current);
      window.removeEventListener("resize", resize);
    };
  }, []);

  /* Every browser keeps watch while the card stands (5.4): the fast feed
   * stops at the finish, so this slow room_state poll — the only traffic
   * left — is what notices a rematch; the card then drops on the same
   * reset the host takes from its api answer. A newer raceNo is the sign,
   * not "racing": a sleeping phone may wake after the rematch has already
   * run, and a host's second tab has no button answer of its own. A room
   * gone to dust takes the card with it. */
  useEffect(function () {
    if (!props.roomId || !results) return;
    const q =
      "api/?action=room_state&room=" + encodeURIComponent(props.roomId);
    return poll(
      q,
      2000,
      function (data) {
        if (data.raceNo !== results.raceNo && restartRef.current) {
          restartRef.current();
        }
      },
      function () {
        setLost(true);
      }
    );
  }, [results]);

  if (lost) return <DustScroll navigate={props.navigate} />;

  return (
    <React.Fragment>
      <canvas id="game" ref={canvasRef} />
      {results ? (
        <ResultsCard
          roomId={props.roomId}
          placements={results.placements}
          names={results.names}
          horses={results.horses}
          participants={results.participants}
          onRaceAgain={function () {
            // Rooms restart in place on the fresh race (5.4); practice
            // walks back to the picker, whose Ride! opens a new race (5.5).
            if (props.roomId) {
              if (restartRef.current) restartRef.current();
            } else {
              props.navigate("/?panel=practice");
            }
          }}
        />
      ) : (
        <div className="top">
          <button disabled>{label}</button>
        </div>
      )}
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
