/* ---------------------------- The dust scroll ------------------------ */
function DustScroll(props) {
  return (
    <div className="stage">
      <div className="card dust">
        <h1>
          This Scroll
          <br />
          Hath Turned to Dust
        </h1>
        <p className="tagline">
          the room you seek hath expired, or never was wrought at all
        </p>
        <div className="menu">
          <button onClick={function () { props.navigate("/"); }}>
            Return to the Gates
          </button>
        </div>
      </div>
    </div>
  );
}
