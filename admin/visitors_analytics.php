<?php
/**
 * Wintaskly — Admin · Suivi des visiteurs en temps réel (V8.26.0)
 * ----------------------------------------------------------------------
 * Trois vues :
 *   1) En ligne maintenant (activité dans les 5 dernières minutes),
 *      avec distinction anonymes / connectés.
 *   2) Sources de trafic (referrers externes, 30 derniers jours).
 *   3) Historique des sessions récentes (durée, pages vues).
 *
 * L'IP est stockée en binaire (jamais en clair en base) et reconvertie
 * en texte lisible ici uniquement, pour l'admin authentifié.
 */
declare(strict_types=1);
require __DIR__ . '/../includes/init.php';
require_admin();

$db = db();

/* ---- 1) En ligne maintenant (5 dernières minutes) ---- */
$online = [];
if ($res = $db->query(
    "SELECT vs.session_key, vs.user_id, vs.ip_bin, vs.current_page, vs.referrer,
            vs.started_at, vs.last_activity, u.username
       FROM visitor_sessions vs
       LEFT JOIN users u ON u.id = vs.user_id
      WHERE vs.last_activity >= UTC_TIMESTAMP() - INTERVAL 5 MINUTE
      ORDER BY vs.last_activity DESC
      LIMIT 200"
)) {
    $online = $res->fetch_all(MYSQLI_ASSOC);
}
$onlineAnon    = count(array_filter($online, static fn ($r) => $r['user_id'] === null));
$onlineAuthed  = count($online) - $onlineAnon;

/* ---- 2) Sources de trafic (30 derniers jours, referrers externes) ---- */
$sources = [];
if ($res = $db->query(
    "SELECT referrer, COUNT(*) AS cnt
       FROM visitor_sessions
      WHERE referrer <> '' AND started_at >= UTC_TIMESTAMP() - INTERVAL 30 DAY
      GROUP BY referrer
      ORDER BY cnt DESC
      LIMIT 20"
)) {
    $sources = $res->fetch_all(MYSQLI_ASSOC);
}
$directCount = 0;
if ($row = $db->query(
    "SELECT COUNT(*) c FROM visitor_sessions
      WHERE referrer = '' AND started_at >= UTC_TIMESTAMP() - INTERVAL 30 DAY"
)?->fetch_assoc()) {
    $directCount = (int) $row['c'];
}

/* ---- 3) Historique récent (dernières sessions, tous statuts) ---- */
$history = [];
if ($res = $db->query(
    "SELECT vs.session_key, vs.user_id, vs.current_page, vs.referrer,
            vs.started_at, vs.last_activity, vs.page_views, u.username,
            TIMESTAMPDIFF(SECOND, vs.started_at, vs.last_activity) AS duration_sec
       FROM visitor_sessions vs
       LEFT JOIN users u ON u.id = vs.user_id
      ORDER BY vs.last_activity DESC
      LIMIT 100"
)) {
    $history = $res->fetch_all(MYSQLI_ASSOC);
}

/** Formate une IP binaire stockée en texte lisible (admin uniquement). */
function wt_admin_ip_readable(?string $bin): string
{
    if ($bin === null || $bin === '') { return '—'; }
    $ip = @inet_ntop($bin);
    return $ip !== false ? $ip : '—';
}

/** Extrait un nom de domaine lisible depuis une URL de referrer. */
function wt_admin_ref_domain(string $url): string
{
    $host = parse_url($url, PHP_URL_HOST);
    return $host !== null ? $host : $url;
}

/** Durée lisible depuis un nombre de secondes. */
function wt_admin_duration(int $sec): string
{
    if ($sec < 60) { return $sec . 's'; }
    if ($sec < 3600) { return floor($sec / 60) . 'min'; }
    return floor($sec / 3600) . 'h' . str_pad((string) (floor(($sec % 3600) / 60)), 2, '0', STR_PAD_LEFT);
}

$pageTitle   = 'Suivi des visiteurs';
$adminActive = 'visitors';
include __DIR__ . '/../header.php';
?>

<main class="wt-main wt-admin-v2">
  <div class="wt-admin-v2__layout">
    <?php include __DIR__ . '/_nav.php'; ?>
  <section class="wt-admin-v2__content">
  <div class="wt-admin-v2__wrap">

    <header class="wt-admin-v2__page-header">
      <div>
        <span class="wt-eyebrow">📡 Analytics</span>
        <h1 class="wt-admin-v2__title">Suivi des visiteurs en temps réel</h1>
        <p class="wt-muted">
          Actualise la page pour rafraîchir. "En ligne" = activité dans les 5 dernières minutes.
        </p>
      </div>
    </header>

    <!-- ============ KPIs ============ -->
    <div class="wt-admin-v2__kpis" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:1rem;margin-bottom:1.5rem">
      <div class="wt-card wt-card--padded">
        <div class="wt-muted" style="font-size:.8rem">En ligne maintenant</div>
        <div style="font-size:1.8rem;font-weight:800"><?= count($online) ?></div>
      </div>
      <div class="wt-card wt-card--padded">
        <div class="wt-muted" style="font-size:.8rem">— dont visiteurs anonymes</div>
        <div style="font-size:1.8rem;font-weight:800"><?= $onlineAnon ?></div>
      </div>
      <div class="wt-card wt-card--padded">
        <div class="wt-muted" style="font-size:.8rem">— dont connectés</div>
        <div style="font-size:1.8rem;font-weight:800"><?= $onlineAuthed ?></div>
      </div>
      <div class="wt-card wt-card--padded">
        <div class="wt-muted" style="font-size:.8rem">Direct (30j, sans referrer)</div>
        <div style="font-size:1.8rem;font-weight:800"><?= $directCount ?></div>
      </div>
    </div>

    <!-- ============ EN LIGNE MAINTENANT ============ -->
    <section class="wt-card wt-card--padded" style="margin-bottom:1.5rem">
      <h2 style="margin-top:0">🟢 En ligne maintenant (<?= count($online) ?>)</h2>
      <?php if (!$online): ?>
        <p class="wt-muted">Personne en ligne actuellement.</p>
      <?php else: ?>
        <div class="wt-table-wrap">
          <table class="wt-table">
            <thead><tr><th>Statut</th><th>Page actuelle</th><th>Source</th><th>IP</th><th>Depuis</th></tr></thead>
            <tbody>
              <?php foreach ($online as $r): ?>
                <tr>
                  <td>
                    <?php if ($r['user_id'] !== null): ?>
                      <span class="wt-badge wt-badge--success">👤 <?= e($r['username'] ?? ('#' . $r['user_id'])) ?></span>
                    <?php else: ?>
                      <span class="wt-badge wt-badge--muted">🕵️ Anonyme</span>
                    <?php endif; ?>
                  </td>
                  <td style="font-size:.85rem"><?= e($r['current_page']) ?></td>
                  <td style="font-size:.8rem"><?= $r['referrer'] !== '' ? e(wt_admin_ref_domain($r['referrer'])) : '<span class="wt-muted">Direct</span>' ?></td>
                  <td style="font-size:.8rem;font-family:monospace"><?= e(wt_admin_ip_readable($r['ip_bin'])) ?></td>
                  <td style="font-size:.8rem" data-utc="<?= e(str_replace(' ', 'T', (string) $r['started_at'])) ?>" data-fmt-time></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </section>

    <!-- ============ SOURCES DE TRAFIC ============ -->
    <section class="wt-card wt-card--padded" style="margin-bottom:1.5rem">
      <h2 style="margin-top:0">🔗 Sources de trafic (30 derniers jours)</h2>
      <?php if (!$sources): ?>
        <p class="wt-muted">Aucune source de trafic externe enregistrée pour l'instant.</p>
      <?php else: ?>
        <div class="wt-table-wrap">
          <table class="wt-table">
            <thead><tr><th>Source</th><th>Visites</th></tr></thead>
            <tbody>
              <?php foreach ($sources as $s): ?>
                <tr>
                  <td><?= e(wt_admin_ref_domain($s['referrer'])) ?></td>
                  <td><strong><?= (int) $s['cnt'] ?></strong></td>
                </tr>
              <?php endforeach; ?>
              <tr>
                <td><em>Direct (sans referrer)</em></td>
                <td><strong><?= $directCount ?></strong></td>
              </tr>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </section>

    <!-- ============ HISTORIQUE RÉCENT ============ -->
    <section class="wt-card wt-card--padded">
      <h2 style="margin-top:0">🕓 Historique récent (100 dernières sessions)</h2>
      <?php if (!$history): ?>
        <p class="wt-muted">Aucune session enregistrée pour l'instant.</p>
      <?php else: ?>
        <div class="wt-table-wrap">
          <table class="wt-table">
            <thead><tr><th>Statut</th><th>Dernière page</th><th>Source</th><th>Pages vues</th><th>Durée</th><th>Démarrée</th></tr></thead>
            <tbody>
              <?php foreach ($history as $h): ?>
                <tr>
                  <td>
                    <?php if ($h['user_id'] !== null): ?>
                      <span class="wt-badge wt-badge--success">👤 <?= e($h['username'] ?? ('#' . $h['user_id'])) ?></span>
                    <?php else: ?>
                      <span class="wt-badge wt-badge--muted">🕵️ Anonyme</span>
                    <?php endif; ?>
                  </td>
                  <td style="font-size:.8rem"><?= e($h['current_page']) ?></td>
                  <td style="font-size:.8rem"><?= $h['referrer'] !== '' ? e(wt_admin_ref_domain($h['referrer'])) : '<span class="wt-muted">Direct</span>' ?></td>
                  <td><?= (int) $h['page_views'] ?></td>
                  <td><?= e(wt_admin_duration((int) $h['duration_sec'])) ?></td>
                  <td style="font-size:.8rem" data-utc="<?= e(str_replace(' ', 'T', (string) $h['started_at'])) ?>" data-fmt-time></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </section>

  </div>
  </section>
  </div>
</main>

<?php include __DIR__ . '/../footer.php'; ?>
