<?php
/**
 * Wintaskly — GET /api/home_feed.php
 *
 * Endpoint public (sans auth) pour le flux d'activité de la home.
 * Retourne les 6 dernières récompenses validées au format JSON.
 *
 * Cache HTTP : 15s (refresh client 30s → moitié des hits sortent du cache).
 *
 * Privacy : username masqué côté serveur (jamais le pseudo brut).
 *
 * Réponse :
 *   { "ok": true, "items": [
 *       { "name": "Saïd K.", "initials": "SK",
 *         "type": "faucet", "verb": "a réclamé",
 *         "amount": "0.50", "at": "2026-05-22T01:42:11Z" },
 *       ...
 *   ]}
 */
declare(strict_types=1);
require __DIR__ . '/../includes/init.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') wt_json(['ok' => false, 'error' => 'method'], 405);

$db = db();

header('Cache-Control: public, max-age=15, s-maxage=15');
header('Content-Type: application/json; charset=utf-8');

$verbs = [
    'faucet'    => (string) t('home.feed.verb.faucet'),
    'shortlink' => (string) t('home.feed.verb.shortlink'),
    'ptc'       => (string) t('home.feed.verb.ptc'),
    'offerwall' => (string) t('home.feed.verb.offerwall'),
    'referral'  => (string) t('home.feed.verb.referral'),
    'bonus'     => (string) t('home.feed.verb.bonus'),
];

$items = [];
$sql = "SELECT t.type, t.coins, t.created_at, u.username
          FROM transactions t
          JOIN users u ON u.id = t.user_id
         WHERE t.type IN ('faucet','shortlink','ptc','offerwall','referral','bonus')
           AND t.coins > 0
         ORDER BY t.id DESC
         LIMIT 6";

if ($res = $db->query($sql)) {
    while ($row = $res->fetch_assoc()) {
        $name  = (string) $row['username'];
        $parts = preg_split('/[\s._-]+/', $name) ?: [$name];

        $first    = $parts[0];
        $lastInit = isset($parts[1]) ? mb_strtoupper(mb_substr($parts[1], 0, 1)) . '.' : '';
        $masked   = mb_strlen($first) > 12 ? mb_substr($first, 0, 10) . '…' : $first;
        $masked   = trim($masked . ($lastInit ? ' ' . $lastInit : ''));

        $a = mb_strtoupper(mb_substr($parts[0] ?? '?', 0, 1));
        $b = mb_strtoupper(mb_substr($parts[1] ?? '', 0, 1));

        $items[] = [
            'name'     => $masked,
            'rawName'  => $name,  // utilisé côté client pour le hash couleur
            'initials' => ($a . $b) ?: '?',
            'type'     => (string) $row['type'],
            'verb'     => $verbs[$row['type']] ?? (string) $row['type'],
            'amount'   => (string) rtrim(rtrim(number_format((float)$row['coins'], 2, '.', ''), '0'), '.'),
            'at'       => (string) $row['created_at'],
        ];
    }
    $res->free();
}

echo json_encode(['ok' => true, 'items' => $items], JSON_UNESCAPED_UNICODE);
