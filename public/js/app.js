/* --------------------------------- App ------------------------------ */
function App() {
  const [route, setRoute] = useState(parseRoute);

  useEffect(function () {
    function onPop() {
      setRoute(parseRoute());
    }
    window.addEventListener("popstate", onPop);
    return function () {
      window.removeEventListener("popstate", onPop);
    };
  }, []);

  function navigate(to) {
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
