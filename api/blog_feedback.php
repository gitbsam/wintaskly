<?php
/**
 * Wintaskly — POST /api/blog_feedback.php
 *
 * Enregistre un avis "cet article vous a-t-il aidé ?" (oui/non) sur un
 * article de blog. Le blog est public et sans login — accessible aux
 * visiteurs anonymes. Déduplication par (post_id, IP hachée) plutôt que
 * par compte utilisateur, via la contrainte UNIQUE de blog_post_feedback.
 * L'IP n'est jamais stockée en clair : seul un hash salé (app_secret) est
 * conservé, suffisant pour la dédup sans retenir une donnée personnelle
 * directement identifiante.
 */
declare(strict_types=1);
require __DIR__ . '/../includes/init.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') wt_json(['ok' => false, 'error' => 'method'], 405);
if (!csrf_check($_POST['_csrf'] ?? null)) wt_json(['ok' => false, 'error' => 'csrf'], 403);

$postId = (int) ($_POST['post_id'] ?? 0);
$raw    = (string) ($_POST['helpful'] ?? '');
$helpful = $raw === '1' ? 1 : ($raw === '0' ? 0 : null);

if ($postId <= 0 || $helpful === null) {
    wt_json(['ok' => false, 'error' => 'params']);
}

$db = db();

// L'article doit exister et être publié
$check = $db->prepare("SELECT id FROM blog_posts WHERE id = ? AND status = 'published' LIMIT 1");
$check->bind_param('i', $postId);
$check->execute();
$exists = $check->get_result()->fetch_assoc();
$check->close();
if (!$exists) wt_json(['ok' => false, 'error' => 'not_found'], 404);

$u      = current_user();
$userId = $u ? (int) $u['id'] : null;

$ip     = $_SERVER['REMOTE_ADDR'] ?? '';
$secret = (string) ($GLOBALS['WT_CONFIG']['app_secret'] ?? '');
$ipHash = hash('sha256', $ip . $secret);

// Une seule contribution par (article, IP) — la contrainte UNIQUE tranche.
// Si l'insertion échoue (doublon ou autre), on répond succès quand même :
// pas d'info exposée sur l'état exact d'un vote précédent (même logique
// que ptc_heartbeat / ptc_cancel : réponse générique côté client).
$ins = $db->prepare(
    "INSERT INTO blog_post_feedback (post_id, user_id, ip_hash, is_helpful)
     VALUES (?, ?, ?, ?)"
);
$ins->bind_param('iisi', $postId, $userId, $ipHash, $helpful);
$inserted = $ins->execute();
$ins->close();

if ($inserted) {
    if ($helpful) {
        $upd = $db->prepare("UPDATE blog_posts SET helpful_yes = helpful_yes + 1 WHERE id = ?");
    } else {
        $upd = $db->prepare("UPDATE blog_posts SET helpful_no = helpful_no + 1 WHERE id = ?");
    }
    $upd->bind_param('i', $postId);
    $upd->execute();
    $upd->close();
}

wt_json(['ok' => true]);
