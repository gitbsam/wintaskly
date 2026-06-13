<?php
/**
 * Wintaskly — GET /api/home_live_withdrawals.php
 *
 * Endpoint public (sans auth) pour le panneau "Derniers retraits live"
 * du hero de la page d'accueil. Retourne les 10 derniers retraits
 * validés au format JSON, avec username tronqué (privacy-friendly).
 *
 * Cache HTTP : 20 secondes — le panneau auto-refresh côté client toutes
 * les 30s, donc la majorité des hits sortent du cache navigateur.
 *
 * Réponse :
 *   {
 *     "ok": true,
 *     "items": [
 *       { "name": "Saïd K.", "initials": "SK", "method": "PayPal",
 *         "amount": "12.50", "at": "2026-05-22T01:42:11Z" },
 *       ...
 *     ]
 *   }
 */
declare(strict_types=1);
require __DIR__ . '/../includes/init.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') wt_json(['ok' => false, 'error' => 'method'], 405);

$db = db();

/* Cache court : 20s sur navigateur + CDN éventuel.
 * Le rafraîchissement réel est piloté par le JS toutes les 30s. */
header('Cache-Control: public, max-age=20, s-maxage=20');
header('Content-Type: application/json; charset=utf-8');

$items = [];
$sql = "SELECT w.payout_amount, w.payout_currency,
               w.created_at, w.processed_at,
               u.username,
               m.label AS method_label
          FROM withdrawals w
          JOIN users u  ON u.id = w.user_id
          JOIN withdrawal_methods m ON m.id = w.method_id
         WHERE w.status = 'completed'
         ORDER BY w.processed_at DESC, w.id DESC
         LIMIT 10";

if ($res = $db->query($sql)) {
    while ($row = $res->fetch_assoc()) {
        $name     = (string) $row['username'];
        $parts    = preg_split('/[\s._-]+/', $name) ?: [$name];
        $first    = $parts[0];
        $lastInit = isset($parts[1]) ? mb_strtoupper(mb_substr($parts[1], 0, 1)) . '.' : '';
        $masked   = mb_strlen($first) > 12
                    ? mb_substr($first, 0, 10) . '…'
                    : $first;
        $masked   = trim($masked . ($lastInit ? ' ' . $lastInit : ''));

        $a = mb_strtoupper(mb_substr($parts[0] ?? '?', 0, 1));
        $b = mb_strtoupper(mb_substr($parts[1] ?? '', 0, 1));

        $items[] = [
            'name'     => $masked,
            'initials' => ($a . $b) ?: '?',
            'method'   => (string) $row['method_label'],
            'amount'   => (string) rtrim(rtrim(number_format((float)$row['payout_amount'], 2, '.', ''), '0'), '.'),
            'currency' => (string) ($row['payout_currency'] ?? ''),
            'at'       => (string) ($row['processed_at'] ?: $row['created_at']),
        ];
    }
    $res->free();
}

echo json_encode(['ok' => true, 'items' => $items], JSON_UNESCAPED_UNICODE);
