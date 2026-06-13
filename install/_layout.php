<?php
/**
 * Wintaskly — Layout standalone pour l'installeur.
 *
 * Volontairement indépendant du système Wintaskly (pas de t(), pas de
 * header.php) car on tourne AVANT que le système soit configuré.
 *
 * Variables attendues :
 *   $pageTitle (string) : titre de la page
 *   $stepNum   (int)    : numéro de l'étape courante (1-5)
 *   $stepLabel (string) : label de l'étape
 *
 * Le contenu de l'étape est placé entre l'ouverture/fermeture du layout
 * (utiliser ob_start dans le step puis include de ce layout).
 */
$pageTitle ??= 'Installation Wintaskly';
$stepNum   ??= 1;
$stepLabel ??= 'Démarrage';
$content   ??= '';

$steps = [
    1 => ['Prérequis',     '🔍'],
    2 => ['Base de données', '🗄'],
    3 => ['Configuration site', '⚙️'],
    4 => ['Compte admin',   '👤'],
    5 => ['Installation',   '🚀'],
];
?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<title><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?></title>
<style>
  /* Reset minimal + identité Wintaskly */
  *, *::before, *::after { box-sizing: border-box; }
  :root {
    --bg: #0a0e1a;
    --bg-elev: #131829;
    --bg-card: #1a2138;
    --border: #2a3252;
    --text: #e8eaf0;
    --text-soft: #a4abc4;
    --text-mute: #6b7390;
    --accent: #ff9933;
    --accent2: #ffcc33;
    --success: #22c55e;
    --danger: #ef4444;
    --warning: #f59e0b;
    --info: #38bdf8;
  }
  html, body {
    margin: 0; padding: 0;
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, sans-serif;
    background: var(--bg);
    color: var(--text);
    min-height: 100vh;
    line-height: 1.55;
  }
  body {
    background-image:
      radial-gradient(at 20% 0%, rgba(255, 153, 51, .08) 0%, transparent 50%),
      radial-gradient(at 80% 100%, rgba(255, 204, 51, .05) 0%, transparent 50%);
  }
  a { color: var(--accent); text-decoration: none; }
  code { font-family: 'JetBrains Mono', 'Fira Code', Consolas, monospace; font-size: .88em; background: rgba(255,255,255,.05); padding: 1px 6px; border-radius: 4px; }
  pre { font-family: 'JetBrains Mono', monospace; font-size: .82rem; background: var(--bg); border: 1px solid var(--border); border-radius: 8px; padding: .85rem 1rem; overflow-x: auto; }

  .wrapper {
    max-width: 880px;
    margin: 0 auto;
    padding: 2rem 1rem 4rem;
  }

  /* Header */
  .header {
    text-align: center;
    margin-bottom: 2rem;
  }
  .logo {
    display: inline-flex; align-items: center; gap: .6rem;
    font-size: 1.6rem; font-weight: 800;
    background: linear-gradient(135deg, var(--accent) 0%, var(--accent2) 100%);
    -webkit-background-clip: text; background-clip: text;
    -webkit-text-fill-color: transparent;
  }
  .logo-icon { font-size: 1.8rem; }
  .tagline {
    color: var(--text-mute);
    font-size: .9rem;
    margin-top: .3rem;
  }

  /* Wizard steps progress */
  .steps {
    display: flex; justify-content: space-between;
    margin-bottom: 2rem;
    position: relative;
  }
  .steps::before {
    content: ''; position: absolute;
    left: 5%; right: 5%; top: 20px;
    height: 2px; background: var(--border);
    z-index: 0;
  }
  .step {
    flex: 1; display: flex; flex-direction: column; align-items: center;
    position: relative; z-index: 1;
    text-align: center;
  }
  .step-circle {
    width: 40px; height: 40px; border-radius: 50%;
    background: var(--bg-elev); border: 2px solid var(--border);
    display: flex; align-items: center; justify-content: center;
    font-size: 1.1rem;
    transition: all .25s ease;
  }
  .step.is-done .step-circle {
    background: var(--success); border-color: var(--success);
    color: white;
  }
  .step.is-active .step-circle {
    background: linear-gradient(135deg, var(--accent) 0%, var(--accent2) 100%);
    border-color: var(--accent);
    color: var(--bg);
    box-shadow: 0 0 0 4px rgba(255, 153, 51, .2);
  }
  .step-label {
    margin-top: .5rem;
    font-size: .72rem; color: var(--text-mute);
    text-transform: uppercase; letter-spacing: .05em;
  }
  .step.is-active .step-label { color: var(--text); font-weight: 600; }
  @media (max-width: 600px) {
    .step-label { display: none; }
  }

  /* Card */
  .card {
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-radius: 16px;
    padding: 2rem;
    box-shadow: 0 20px 60px rgba(0, 0, 0, .3);
  }
  .card-title {
    font-size: 1.4rem; font-weight: 700;
    margin: 0 0 .35rem;
    display: flex; align-items: center; gap: .5rem;
  }
  .card-lead {
    color: var(--text-soft);
    margin: 0 0 1.5rem;
    font-size: .95rem;
  }

  /* Form */
  .field { margin-bottom: 1.1rem; }
  .field label {
    display: block;
    font-weight: 600;
    margin-bottom: .35rem;
    font-size: .9rem;
  }
  .field input[type=text], .field input[type=email],
  .field input[type=password], .field input[type=url], .field input[type=number],
  .field select, .field textarea {
    width: 100%;
    padding: .65rem .85rem;
    background: var(--bg);
    border: 1px solid var(--border);
    border-radius: 8px;
    color: var(--text);
    font-size: .95rem;
    font-family: inherit;
    transition: border-color .15s ease;
  }
  .field input:focus, .field select:focus, .field textarea:focus {
    outline: none;
    border-color: var(--accent);
    box-shadow: 0 0 0 3px rgba(255, 153, 51, .15);
  }
  .field-hint {
    margin-top: .3rem;
    font-size: .78rem;
    color: var(--text-mute);
  }
  .field-row {
    display: grid;
    grid-template-columns: 1fr;
    gap: 1rem;
  }
  @media (min-width: 600px) {
    .field-row.cols-2 { grid-template-columns: 1fr 1fr; }
    .field-row.cols-3 { grid-template-columns: 1fr 1fr 1fr; }
  }

  /* Buttons */
  .btn {
    display: inline-flex; align-items: center; gap: .5rem;
    padding: .7rem 1.4rem;
    border-radius: 10px;
    border: 1px solid transparent;
    font-family: inherit; font-size: .95rem; font-weight: 600;
    cursor: pointer;
    text-decoration: none;
    transition: transform .12s ease, box-shadow .12s ease, opacity .12s ease;
  }
  .btn-primary {
    background: linear-gradient(135deg, var(--accent) 0%, var(--accent2) 100%);
    color: var(--bg);
  }
  .btn-primary:hover {
    transform: translateY(-1px);
    box-shadow: 0 8px 20px rgba(255, 153, 51, .3);
  }
  .btn-primary:disabled {
    opacity: .4; cursor: not-allowed;
    transform: none; box-shadow: none;
  }
  .btn-ghost {
    background: transparent;
    border-color: var(--border);
    color: var(--text-soft);
  }
  .btn-ghost:hover { background: var(--bg-elev); color: var(--text); }
  .btn-secondary {
    background: var(--bg-elev);
    border-color: var(--border);
    color: var(--text);
  }
  .btn-secondary:hover { background: var(--bg); border-color: var(--accent); }
  .form-actions {
    display: flex; justify-content: space-between;
    gap: 1rem;
    margin-top: 2rem;
    padding-top: 1.5rem;
    border-top: 1px solid var(--border);
  }

  /* Alerts */
  .alert {
    padding: .85rem 1rem;
    border-radius: 10px;
    margin-bottom: 1.25rem;
    border: 1px solid;
    display: flex; gap: .6rem; align-items: flex-start;
    font-size: .9rem;
  }
  .alert-success { background: rgba(34, 197, 94, .1); border-color: rgba(34, 197, 94, .3); color: #86efac; }
  .alert-error   { background: rgba(239, 68, 68, .1); border-color: rgba(239, 68, 68, .3); color: #fca5a5; }
  .alert-warning { background: rgba(245, 158, 11, .1); border-color: rgba(245, 158, 11, .3); color: #fcd34d; }
  .alert-info    { background: rgba(56, 189, 248, .1); border-color: rgba(56, 189, 248, .3); color: #7dd3fc; }
  .alert-icon { font-size: 1.1rem; line-height: 1.2; flex-shrink: 0; }

  /* Requirements list */
  .check-list {
    list-style: none; padding: 0; margin: 0;
  }
  .check-item {
    display: flex; align-items: flex-start; gap: .75rem;
    padding: .75rem;
    border-bottom: 1px solid var(--border);
  }
  .check-item:last-child { border-bottom: 0; }
  .check-icon { font-size: 1.2rem; flex-shrink: 0; width: 24px; }
  .check-body { flex: 1; }
  .check-body strong { display: block; font-size: .92rem; }
  .check-body small { color: var(--text-mute); font-size: .8rem; }
  .check-item.ok .check-icon { color: var(--success); }
  .check-item.fail .check-icon { color: var(--danger); }
  .check-item.warn .check-icon { color: var(--warning); }

  /* Final summary */
  .summary-box {
    background: var(--bg);
    border: 1px solid var(--border);
    border-radius: 10px;
    padding: 1.25rem;
    margin-bottom: 1.5rem;
  }
  .summary-box h3 {
    margin: 0 0 .75rem;
    font-size: 1rem; font-weight: 600;
    color: var(--accent);
  }
  .summary-box dl {
    margin: 0; display: grid;
    grid-template-columns: 1fr 2fr;
    gap: .35rem .75rem;
    font-size: .88rem;
  }
  .summary-box dt { color: var(--text-mute); }
  .summary-box dd { margin: 0; word-break: break-word; }

  /* Spinner */
  .spinner {
    display: inline-block;
    width: 16px; height: 16px;
    border: 2px solid rgba(255,255,255,.3);
    border-top-color: white;
    border-radius: 50%;
    animation: spin .8s linear infinite;
  }
  @keyframes spin { to { transform: rotate(360deg); } }

  /* Reveal animation */
  .card { animation: cardIn .35s ease-out; }
  @keyframes cardIn {
    from { opacity: 0; transform: translateY(8px); }
    to { opacity: 1; transform: translateY(0); }
  }

  .footer {
    text-align: center; margin-top: 2rem;
    color: var(--text-mute); font-size: .8rem;
  }
</style>
</head>
<body>

<div class="wrapper">

  <header class="header">
    <div class="logo">
      <span class="logo-icon">⚡</span>
      <span>Wintaskly</span>
    </div>
    <p class="tagline">Installation guidée · Étape <?= (int)$stepNum ?>/5</p>
  </header>

  <!-- Wizard progress -->
  <nav class="steps" aria-label="Progression de l'installation">
    <?php foreach ($steps as $num => [$label, $icon]):
      $cls = $num < $stepNum ? 'is-done' : ($num === $stepNum ? 'is-active' : '');
    ?>
      <div class="step <?= $cls ?>">
        <div class="step-circle">
          <?= $num < $stepNum ? '✓' : htmlspecialchars($icon, ENT_QUOTES, 'UTF-8') ?>
        </div>
        <div class="step-label"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></div>
      </div>
    <?php endforeach; ?>
  </nav>

  <main class="card">
    <?= $content ?>
  </main>

  <footer class="footer">
    Wintaskly V8 · Installation sécurisée
  </footer>

</div>

</body>
</html>
