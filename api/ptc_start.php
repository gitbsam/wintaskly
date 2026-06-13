<?php
/**
 * Wintaskly — POST /api/ptc_start.php
 *
 * Démarre une session PTC :
 *   - exige un utilisateur authentifié + CSRF,
 *   - acquiert le verrou « une session active max » côté serveur (SELECT … FOR UPDATE),
 *   - vérifie le cooldown utilisateur et le plafond quotidien,
 *   - génère un mini-captcha (3 icônes parmi celles du Faucet),
 *   - retourne le token, l'URL partenaire et le payload captcha.
 *
 * Réponse JSON :
 *   { ok: true, token, url, duration_seconds, captcha: { target, icons:[{slug,label,svg}] } }
 *   { ok: false, error: '…' }
 */
declare(strict_types=1);
require __DIR__ . '/../includes/init.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') wt_json(['ok' => false, 'error' => 'method'], 405);
if (!csrf_check($_POST['_csrf'] ?? null)) wt_json(['ok' => false, 'error' => 'csrf'], 403);

$u = current_user();
if (!$u) wt_json(['ok' => false, 'error' => 'auth'], 401);

$ptcId = (int) ($_POST['ptc_id'] ?? 0);
if ($ptcId <= 0) wt_json(['ok' => false, 'error' => 'ptc_id']);

$db = db();
$db->begin_transaction();

try {
    /* 1) Verrou applicatif : refus si déjà une session active pour cet user */
    $stmt = $db->prepare(
        "SELECT id FROM ptc_sessions
          WHERE user_id = ? AND status = 'active'
            AND expires_at > UTC_TIMESTAMP()
          FOR UPDATE"
    );
    $stmt->bind_param('i', $u['id']);
    $stmt->execute();
    $busy = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($busy) {
        $db->rollback();
        wt_json(['ok' => false, 'error' => t('ptc.running')]);
    }

    /* 2) Chargement de l'annonce */
    $stmt = $db->prepare(
        "SELECT id, title, url, reward_coins, reward_xp, duration_seconds,
                daily_view_limit, cooldown_hours, active
           FROM ptc_ads
          WHERE id = ?
          LIMIT 1"
    );
    $stmt->bind_param('i', $ptcId);
    $stmt->execute();
    $ad = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$ad || (int) $ad['active'] !== 1) {
        $db->rollback();
        wt_json(['ok' => false, 'error' => 'not_found'], 404);
    }

    /* 3) Cooldown utilisateur */
    $stmt = $db->prepare(
        "SELECT next_view_at FROM ptc_views
          WHERE user_id = ? AND ptc_id = ?
          ORDER BY id DESC LIMIT 1"
    );
    $stmt->bind_param('ii', $u['id'], $ad['id']);
    $stmt->execute();
    $last = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($last && strtotime($last['next_view_at'] . ' UTC') > time()) {
        $db->rollback();
        wt_json(['ok' => false, 'error' => t('ptc.locked')]);
    }

    /* 4) Plafond quotidien (toutes vues confondues) */
    $stmt = $db->prepare(
        "SELECT COUNT(*) c FROM ptc_views
          WHERE ptc_id = ?
            AND viewed_at >= UTC_TIMESTAMP() - INTERVAL 1 DAY"
    );
    $stmt->bind_param('i', $ad['id']);
    $stmt->execute();
    $cnt = (int) ($stmt->get_result()->fetch_assoc()['c'] ?? 0);
    $stmt->close();
    if ($cnt >= (int) $ad['daily_view_limit']) {
        $db->rollback();
        wt_json(['ok' => false, 'error' => t('ptc.daily_limit')]);
    }

    /* 5) Génération mini-captcha (3 icônes parmi celles du Faucet) */
    $icons = [];
    // La table captcha_icons a les colonnes : id, name, slug, svg, active
    // (PAS 'label' qui était l'ancien nom et causait un HTTP 500 silencieux).
    // On alias `name AS label` pour rester compatible avec le JS frontend
    // qui attend des objets {slug, label, svg}.
    $res = $db->query(
        "SELECT slug, name AS label, svg
           FROM captcha_icons
          WHERE active = 1
          ORDER BY RAND()
          LIMIT 3"
    );
    $icons = $res->fetch_all(MYSQLI_ASSOC);
    $res->free();
    if (count($icons) < 3) { $db->rollback(); wt_json(['ok' => false, 'error' => 'captcha_pool']); }

    $target = $icons[random_int(0, 2)]['slug'];
    $order  = json_encode(array_column($icons, 'slug'));

    /* 6) Création de la session */
    $token   = bin2hex(random_bytes(32));
    $ttl     = (int) $ad['duration_seconds'] + 60; // marge captcha
    $ipBin   = wt_ip_bin();
    $ua      = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);

    $stmt = $db->prepare(
        "INSERT INTO ptc_sessions
            (user_id, ptc_id, token, captcha_target, captcha_order,
             expires_at, ip, user_agent)
         VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP() + INTERVAL ? SECOND, ?, ?)"
    );
    $stmt->bind_param(
        'iisssiss',
        $u['id'], $ad['id'], $token, $target, $order, $ttl, $ipBin, $ua
    );
    $stmt->execute();
    $stmt->close();

    $db->commit();

    wt_json([
        'ok'               => true,
        'token'            => $token,
        'url'              => $ad['url'],
        'duration_seconds' => (int) $ad['duration_seconds'],
        'captcha'          => [
            'target' => $target,
            'icons'  => $icons,
        ],
    ]);

} catch (Throwable $e) {
    $db->rollback();
    // Log détaillé avec fichier+ligne pour faciliter le diagnostic en prod
    error_log('[Wintaskly ptc_start] ' . $e->getMessage()
            . ' @ ' . basename($e->getFile()) . ':' . $e->getLine()
            . ' (user=' . ($u['id'] ?? '?') . ', ptc_id=' . $ptcId . ')');
    wt_json(['ok' => false, 'error' => 'server'], 500);
}
