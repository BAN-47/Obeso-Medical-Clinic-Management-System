<?php
session_start();

require_once __DIR__ . "/Obeso-Clinic-Management-System/Config/database.php";

$database = new Database();
$conn = $database->connect();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Obeso's Clinic Management System</title>
  <link rel="icon" type="image/png" href="Assets/Obesos_Clinic_Logo.png" />
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@300;400;500;600&family=DM+Sans:wght@300;400;500&display=swap" rel="stylesheet">

  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

    :root {
      --navy:   #042a42;
      --ocean:  #075179;
      --teal:   #0a8fb5;
      --sky:    #38bdf8;
      --ice:    #e0f6ff;
      --white:  #ffffff;
      --slate:  #4a6475;
      --mist:   #f0f8fc;
    }

    html, body {
      height: 100%;
    }

    body {
      min-height: 100vh;
      width: 100%;
      display: flex;
      align-items: center;
      justify-content: center;
      background: var(--navy);
      font-family: 'DM Sans', sans-serif;
      overflow: auto;
      padding: 1.5rem;
    }

    /* ── Animated background ── */
    .bg-canvas {
      position: fixed; inset: 0; z-index: 0;
      background: linear-gradient(135deg, #021b2b 0%, #042a42 40%, #063349 70%, #0a4d6e 100%);
    }

    .bg-canvas::before {
      content: '';
      position: absolute; inset: 0;
      background:
        radial-gradient(ellipse 80% 60% at 15% 50%, rgba(10,143,181,.18) 0%, transparent 60%),
        radial-gradient(ellipse 60% 80% at 85% 20%, rgba(56,189,248,.10) 0%, transparent 55%),
        radial-gradient(ellipse 50% 40% at 60% 80%, rgba(7,81,121,.25) 0%, transparent 55%);
      animation: pulse-bg 8s ease-in-out infinite alternate;
    }

    @keyframes pulse-bg {
      from { opacity: .7; }
      to   { opacity: 1;  }
    }

    /* Floating medical cross ornaments */
    .ornaments { position: fixed; inset: 0; z-index: 0; pointer-events: none; overflow: hidden; }
    .cross {
      position: absolute;
      opacity: .04;
      font-size: 6rem;
      color: var(--sky);
      animation: float 18s ease-in-out infinite;
      font-weight: 100;
      user-select: none;
    }
    .cross:nth-child(1) { top: 8%;  left: 5%;  font-size: 5rem;  animation-delay: 0s;   animation-duration: 20s; }
    .cross:nth-child(2) { top: 60%; left: 2%;  font-size: 8rem;  animation-delay: -6s;  animation-duration: 22s; }
    .cross:nth-child(3) { top: 20%; right: 4%; font-size: 4rem;  animation-delay: -3s;  animation-duration: 17s; }
    .cross:nth-child(4) { top: 75%; right: 6%; font-size: 7rem;  animation-delay: -10s; animation-duration: 24s; }
    .cross:nth-child(5) { top: 45%; left: 48%; font-size: 3rem;  animation-delay: -14s; animation-duration: 19s; }

    @keyframes float {
      0%,100% { transform: translateY(0)   rotate(0deg);   }
      33%      { transform: translateY(-24px) rotate(5deg); }
      66%      { transform: translateY(12px)  rotate(-3deg);}
    }

    /* Grid lines */
    .grid-lines {
      position: fixed; inset: 0; z-index: 0; pointer-events: none;
      background-image:
        linear-gradient(rgba(56,189,248,.03) 1px, transparent 1px),
        linear-gradient(90deg, rgba(56,189,248,.03) 1px, transparent 1px);
      background-size: 60px 60px;
    }

    /* ── Card ── */
    .card {
      position: relative; z-index: 1;
      width: 100%; max-width: 460px;
      margin: 0 auto;
      background: rgba(255,255,255,.96);
      border-radius: 24px;
      padding: 3rem 3rem 2.5rem;
      box-shadow:
        0 0 0 1px rgba(56,189,248,.12),
        0 32px 80px rgba(4,42,66,.55),
        0  8px 24px rgba(4,42,66,.35);
      backdrop-filter: blur(12px);
      animation: card-in .7s cubic-bezier(.22,1,.36,1) both;
    }

    @keyframes card-in {
      from { opacity: 0; transform: translateY(28px) scale(.97); }
      to   { opacity: 1; transform: none; }
    }

    /* Accent bar at top of card */
    .card::before {
      content: '';
      position: absolute;
      top: 0; left: 5%; right: 5%;
      height: 3px;
      border-radius: 0 0 4px 4px;
      background: linear-gradient(90deg, transparent, var(--teal), var(--sky), var(--teal), transparent);
    }

    /* ── Header ── */
    .header { text-align: center; margin-bottom: 2.5rem; }

    .logo-ring {
      display: inline-flex; align-items: center; justify-content: center;
      width: 68px; height: 68px;
      border-radius: 50%;
      background: linear-gradient(135deg, var(--ocean), var(--teal));
      box-shadow: 0 6px 24px rgba(10,143,181,.35);
      margin-bottom: 1.25rem;
      animation: ring-pop .5s .3s cubic-bezier(.34,1.56,.64,1) both;
    }

    @keyframes ring-pop {
      from { opacity: 0; transform: scale(.5); }
      to   { opacity: 1; transform: scale(1);  }
    }

    .logo-ring svg { width: 34px; height: 34px; fill: none; stroke: #fff; stroke-width: 2; stroke-linecap: round; }

    .clinic-name {
      font-family: 'Cormorant Garamond', serif;
      font-size: 1.55rem;
      font-weight: 500;
      color: var(--navy);
      letter-spacing: .01em;
      line-height: 1.2;
    }

    .clinic-sub {
      font-size: .78rem;
      font-weight: 400;
      color: var(--teal);
      letter-spacing: .12em;
      text-transform: uppercase;
      margin-top: .35rem;
    }

    /* ── Divider ── */
    .divider {
      display: flex; align-items: center; gap: .75rem;
      margin: 0 0 1.75rem;
    }
    .divider::before, .divider::after {
      content: ''; flex: 1;
      height: 1px;
      background: linear-gradient(90deg, transparent, #cbd5e1, transparent);
    }
    .divider span {
      font-family: 'Cormorant Garamond', serif;
      font-size: 1.1rem;
      color: #94a3b8;
      font-style: italic;
    }

    /* ── Form ── */
    .form { display: flex; flex-direction: column; gap: 1.1rem; }

    .field { position: relative; }

    .field-label {
      display: block;
      font-size: .72rem;
      font-weight: 500;
      letter-spacing: .1em;
      text-transform: uppercase;
      color: var(--slate);
      margin-bottom: .4rem;
      padding-left: .1rem;
    }

    .field-icon {
      position: absolute;
      left: 1rem; bottom: 0;
      height: calc(100% - 1.4rem);
      display: flex; align-items: center;
      color: #94a3b8;
      pointer-events: none;
      transition: color .2s;
    }
    .field-icon svg { width: 17px; height: 17px; }

    .field input {
      width: 100%;
      padding: .9rem 1rem .9rem 2.75rem;
      font-family: 'DM Sans', sans-serif;
      font-size: .95rem;
      color: var(--navy);
      background: var(--mist);
      border: 1.5px solid #dde6ee;
      border-radius: 12px;
      outline: none;
      transition: border-color .2s, box-shadow .2s, background .2s;
    }

    .field input::placeholder { color: #a8b8c4; }

    .field input:focus {
      border-color: var(--teal);
      background: #fff;
      box-shadow: 0 0 0 3px rgba(10,143,181,.12);
    }

    .field input:focus + .field-icon,
    .field:focus-within .field-icon { color: var(--teal); }

    /* password eye */
    .eye-btn {
      position: absolute;
      right: .85rem; bottom: 0;
      height: calc(100% - 1.4rem);
      display: flex; align-items: center;
      background: none; border: none; cursor: pointer;
      color: #94a3b8; padding: 0 .2rem;
      transition: color .2s;
    }
    .eye-btn:hover { color: var(--teal); }
    .eye-btn svg { width: 18px; height: 18px; }

    /* ── Submit ── */
    .submit-btn {
      margin-top: .5rem;
      width: 100%;
      padding: 1rem;
      font-family: 'DM Sans', sans-serif;
      font-size: 1rem;
      font-weight: 500;
      letter-spacing: .04em;
      color: #fff;
      background: linear-gradient(135deg, var(--ocean) 0%, var(--teal) 100%);
      border: none; border-radius: 12px;
      cursor: pointer;
      position: relative; overflow: hidden;
      transition: transform .15s, box-shadow .2s;
      box-shadow: 0 4px 16px rgba(10,143,181,.35);
    }

    .submit-btn::after {
      content: '';
      position: absolute; inset: 0;
      background: linear-gradient(135deg, rgba(255,255,255,.15), transparent);
      opacity: 0;
      transition: opacity .2s;
    }

    .submit-btn:hover { transform: translateY(-1px); box-shadow: 0 8px 24px rgba(10,143,181,.45); }
    .submit-btn:hover::after { opacity: 1; }
    .submit-btn:active { transform: translateY(0); }

    /* ── Support ── */
    .support {
      margin-top: 1.5rem;
      text-align: center;
      font-size: .85rem;
      color: #7a92a3;
    }
    .support a {
      color: var(--teal);
      font-weight: 500;
      text-decoration: none;
      border-bottom: 1px solid transparent;
      transition: border-color .2s;
    }
    .support a:hover { border-color: var(--teal); }

    /* ── Footer ── */
    .footer {
      margin-top: 1.75rem;
      padding-top: 1.25rem;
      border-top: 1px solid #e8f0f5;
      text-align: center;
      font-size: .72rem;
      color: #a8b8c4;
      line-height: 1.7;
    }
    .footer a { color: #a8b8c4; text-decoration: none; }
    .footer a:hover { color: var(--teal); }

    /* ── Error toast ── */
    .toast {
      position: fixed; top: 1.5rem; left: 50%; transform: translateX(-50%);
      z-index: 999;
      background: #fee2e2; border: 1px solid #fca5a5; color: #991b1b;
      padding: .75rem 1.5rem; border-radius: 10px;
      font-size: .88rem; font-weight: 500;
      box-shadow: 0 4px 20px rgba(0,0,0,.15);
      animation: toast-in .35s cubic-bezier(.22,1,.36,1) both;
      max-width: 90vw; text-align: center;
    }
    @keyframes toast-in {
      from { opacity: 0; transform: translateX(-50%) translateY(-12px); }
      to   { opacity: 1; transform: translateX(-50%) translateY(0); }
    }
  </style>
</head>

<?php if (isset($_SESSION['login_error'])): ?>
  <div class="toast" id="error-toast">
    ⚠️ <?= htmlspecialchars($_SESSION['login_error']); ?>
  </div>
  <script>setTimeout(() => { const t = document.getElementById('error-toast'); if(t) t.style.opacity='0'; t.style.transition='opacity .4s'; setTimeout(()=>t.remove(),400); }, 4000);</script>
  <?php unset($_SESSION['login_error']); ?>
<?php endif; ?>

<body>

  <div class="bg-canvas"></div>
  <div class="grid-lines"></div>

  <div class="ornaments" aria-hidden="true">
    <span class="cross">✚</span>
    <span class="cross">✚</span>
    <span class="cross">✚</span>
    <span class="cross">✚</span>
    <span class="cross">✚</span>
  </div>

  <div class="card" role="main">

    <!-- Header -->
    <div class="header"> 
      <div class="clinic-name">Obeso Medical Clinic</div>
      <div class="clinic-sub">Management System</div>
    </div>

    <!-- Divider -->
    <div class="divider"><span>Sign in to continue</span></div>

    <!-- Form -->
    <form method="POST" action="./Obeso-Clinic-Management-System/Public/login_register.php" autocomplete="off" class="form">

      <!-- Username -->
      <div class="field">
        <label class="field-label" for="username">Username or Email</label>
        <input type="text" id="username" name="username" placeholder="Enter your username" required />
        <span class="field-icon" aria-hidden="true">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <circle cx="12" cy="8" r="4"/><path d="M4 20c0-4 3.6-7 8-7s8 3 8 7"/>
          </svg>
        </span>
      </div>

      <!-- Password -->
      <div class="field">
        <label class="field-label" for="login-password">Password</label>
        <input type="password" id="login-password" name="password" placeholder="Enter your password" required />
        <span class="field-icon" aria-hidden="true">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>
          </svg>
        </span>
        <button type="button" class="eye-btn" onclick="togglePassword()" aria-label="Toggle password visibility" id="eye-btn">
          <!-- Eye open -->
          <svg id="eye-open" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>
          </svg>
          <!-- Eye closed (hidden by default) -->
          <svg id="eye-closed" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:none">
            <path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94"/><path d="M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19"/><line x1="1" y1="1" x2="23" y2="23"/>
          </svg>
        </button>
      </div>

      <!-- Submit -->
      <button type="submit" name="login" class="submit-btn">
        Sign In
      </button>

    </form>

    <!-- Support -->
    <p class="support">
      Need help? <a href="#">Contact IT Support</a>
    </p>

    <!-- Footer -->
    <footer class="footer">
      © Obeso's Clinic &nbsp;·&nbsp; All rights reserved &nbsp;·&nbsp;
      <a href="#">Privacy Policy</a><br />
      Poog, Toledo City
    </footer>

  </div>

  <script>
    function togglePassword() {
      const input   = document.getElementById('login-password');
      const eyeOpen = document.getElementById('eye-open');
      const eyeClosed = document.getElementById('eye-closed');
      const isHidden  = input.type === 'password';
      input.type = isHidden ? 'text' : 'password';
      eyeOpen.style.display   = isHidden ? 'none'  : '';
      eyeClosed.style.display = isHidden ? ''      : 'none';
    }
  </script>

</body>
</html>