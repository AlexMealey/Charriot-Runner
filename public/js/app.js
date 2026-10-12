/* --------------------------------- App ------------------------------ */
function App() {
  const [route, setRoute] = useState(parseRoute);

  // Each view starts on a steady link: the wavering notice (4.8) belongs
  // to the pollers of the view just left, and no one there can lower it.
  useEffect(function () {
    function onPop() {
      waver(true);
      setRoute(parseRoute());
    }
    window.addEventListener("popstate", onPop);
    return function () {
      window.removeEventListener("popstate", onPop);
    };
  }, []);

  function navigate(to) {
    waver(true);
    window.history.pushState({}, "", to);
    setRoute(parseRoute());
  }

  let view;
  if (route.name === "practice") {
    view = <RaceView practiceId={route.practiceId} navigate={navigate} />;
  } else if (route.name === "join") {
    view = <JoinGate roomId={route.roomId} navigate={navigate} />;
  } else if (route.name === "dust") {
    view = <DustScroll navigate={navigate} />;
  } else {
    view = <Landing navigate={navigate} />;
  }

  return (
    <React.Fragment>
      {view}
      <div className="frame" />
    </React.Fragment>
  );
}

ReactDOM.createRoot(document.getElementById("root")).render(<App />);
