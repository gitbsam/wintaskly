<?php
/**
 * Wintaskly — POST /api/ptc_heartbeat.php
 *
 * Preuve de présence côté serveur pendant une session PTC active.
 * Le JS (watcher déjà existant, qui vérifie win.closed toutes les 500ms)
 * envoie un battement toutes les ~5s TANT QUE la fenêtre partenaire est
 * encore ouverte ET que l'onglet Wintaskly est visible.
 *
 * Pourquoi : la validation (ptc_validate.php) ne vérifiait jusqu'ici que
 * le temps serveur écoulé — un script pouvait appeler ptc_start puis
 * attendre puis ptc_validate SANS JAMAIS ouvrir la fenêtre partenaire.
 * Le compteur heartbeat_count donne une preuve positive répétée que le
 * JS actif (donc une fenêtre réellement ouverte) tournait pendant la
 * durée, sans dépendre uniquement d'un signal d'annulation optionnel.
 */
declare(strict_types=1);
require __DIR__ . '/../includes/init.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') wt_json(['ok' => false, 'error' => 'method'], 405);
if (!csrf_check($_POST['_csrf'] ?? null)) wt_json(['ok' => false, 'error' => 'csrf'], 403);

$u = current_user();
if (!$u) wt_json(['ok' => false, 'error' => 'auth'], 401);

$token = trim((string) ($_POST['token'] ?? ''));
if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
    wt_json(['ok' => false, 'error' => 'token']);
}

$db = db();
// Incrémente seulement si la session est active, appartient à l'utilisateur,
// et n'est pas expirée — pas d'insertion, pas de fuite d'info sur un token
// invalide (réponse ok générique quoi qu'il arrive, comme ptc_cancel).
$stmt = $db->prepare(
    "UPDATE ptc_sessions
        SET heartbeat_count = LEAST(heartbeat_count + 1, 999),
            last_heartbeat_at = UTC_TIMESTAMP()
      WHERE token = ? AND user_id = ? AND status = 'active'
        AND expires_at > UTC_TIMESTAMP()"
);
$stmt->bind_param('si', $token, $u['id']);
$stmt->execute();
$stmt->close();

wt_json(['ok' => true]);
