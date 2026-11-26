/**
 * Splash screen: logo + brand 3s fade, blue-purple gradient, sound
 */
(function () {
  const SHOWN_KEY = "struggle_splash_shown";
  // Always show splash once per session
  if (sessionStorage.getItem(SHOWN_KEY) === "1") return;

  const style = document.createElement("style");
  style.textContent = `
    #splash-screen {
      position: fixed; inset: 0; z-index: 99999;
      display: flex; flex-direction: column; align-items: center; justify-content: center;
      background: linear-gradient(135deg, #1a1a2e 0%, #16213e 30%, #0f3460 60%, #533483 100%);
      transition: opacity 0.6s ease, visibility 0.6s;
    }
    #splash-screen.fade-out { opacity: 0; visibility: hidden; pointer-events: none; }
    #splash-screen .splash-logo {
      width: 120px; height: 120px; border-radius: 50%;
      box-shadow: 0 0 0 0 rgba(100, 120, 255, 0.5);
      animation: pulse-ring 1.5s ease-out infinite, fadeInUp 0.8s ease;
      object-fit: cover; background: #111;
    }
    #splash-screen .splash-brand {
      margin-top: 24px; text-align: center;
      animation: fadeInUp 0.8s ease 0.3s both;
    }
    #splash-screen .splash-brand h1 {
      font-family: 'Plus Jakarta Sans', sans-serif;
      font-size: 28px; font-weight: 700; letter-spacing: 0.12em;
      color: #fff; margin: 0;
    }
    #splash-screen .splash-brand p {
      font-size: 12px; letter-spacing: 0.2em; text-transform: uppercase;
      color: rgba(255,255,255,0.7); margin-top: 8px;
    }
    #splash-screen .splash-sub {
      font-size: 11px; color: rgba(255,255,255,0.5); margin-top: 4px;
      letter-spacing: 0.15em;
    }
    @keyframes pulse-ring {
      0% { box-shadow: 0 0 0 0 rgba(120, 100, 255, 0.6); }
      70% { box-shadow: 0 0 0 30px rgba(120, 100, 255, 0); }
      100% { box-shadow: 0 0 0 0 rgba(120, 100, 255, 0); }
    }
    @keyframes fadeInUp {
      from { opacity: 0; transform: translateY(16px); }
      to { opacity: 1; transform: translateY(0); }
    }
  `;
  document.head.appendChild(style);

  const splash = document.createElement("div");
  splash.id = "splash-screen";
  splash.innerHTML = `
    <img class="splash-logo" src="assets/images/logo.png" alt="SFV">
    <div class="splash-brand">
      <h1>SFV</h1>
      <p>STUDIO</p>
      <div class="splash-sub">Struggle For Victory</div>
    </div>
  `;
  document.body.appendChild(splash);
  document.body.style.overflow = "hidden";

  // Soft ambient "whoosh" using Web Audio API
  try {
    const ctx = new (window.AudioContext || window.webkitAudioContext)();
    const osc = ctx.createOscillator();
    const gain = ctx.createGain();
    osc.type = "sine";
    osc.frequency.setValueAtTime(180, ctx.currentTime);
    osc.frequency.exponentialRampToValueAtTime(60, ctx.currentTime + 0.8);
    gain.gain.setValueAtTime(0.0001, ctx.currentTime);
    gain.gain.exponentialRampToValueAtTime(0.08, ctx.currentTime + 0.15);
    gain.gain.exponentialRampToValueAtTime(0.0001, ctx.currentTime + 1.2);
    osc.connect(gain);
    gain.connect(ctx.destination);
    osc.start();
    osc.stop(ctx.currentTime + 1.3);
  } catch (e) {}

  setTimeout(() => {
    splash.classList.add("fade-out");
    document.body.style.overflow = "";
    sessionStorage.setItem(SHOWN_KEY, "1");
    setTimeout(() => splash.remove(), 700);
  }, 3000);
})();
