<?php
/**
 * Wintaskly — Dispatcher de paiements automatiques.
 *
 * Centralise la logique d'envoi automatique des retraits via les API
 * des providers (FaucetPay, Payeer, Binance, etc.).
 *
 * Architecture :
 *   - `wt_payout_dispatch()` : point d'entrée principal (appelé depuis
 *     admin/withdrawals.php au moment du `complete`). Choisit le bon
 *     handler selon la clé `k` de la méthode et délègue.
 *   - `wt_payout_faucetpay()` : handler FaucetPay (1ère implémentation).
 *   - `wt_payout_log()` : log centralisé (BDD via transactions + meta).
 *
 * Conventions de retour :
 *   ['ok' => true,  'txid' => 'fp_xxx', 'message' => 'Envoyé']
 *   ['ok' => false, 'message' => "Solde insuffisant côté provider", 'retry' => true|false]
 *
 * Gestion d'erreur :
 *   - Timeout réseau → retry possible
 *   - Solde insuffisant côté provider → admin doit recharger son compte
 *   - Adresse invalide → l'admin doit refuser le retrait
 *   - Clé API invalide → admin doit mettre à jour les credentials
 *
 * Sécurité :
 *   - Les credentials API sont chargées juste à temps depuis withdrawal_methods
 *   - JAMAIS logguées en clair
 *   - HTTPS forcé (CURLOPT_SSL_VERIFYPEER)
 *   - Timeout court (15s) pour éviter les requêtes pendantes
 */

declare(strict_types=1);

/**
 * Point d'entrée principal du dispatcher.
 *
 * @param array $withdrawal Ligne de la table `withdrawals` (au minimum :
 *                          id, user_id, coins_amount, payout_amount,
 *                          payout_currency, payout_address, method_id).
 * @return array ['ok' => bool, 'message' => string, 'txid' => ?string,
 *                'manual_required' => bool, 'retry' => bool]
 */
function wt_payout_dispatch(array $withdrawal): array
{
    $db = db();

    /* Charge la méthode + ses credentials */
    $stmt = $db->prepare(
        "SELECT k, label, currency, api_credentials, auto_payout
           FROM withdrawal_methods WHERE id = ?"
    );
    $methodId = (int) $withdrawal['method_id'];
    $stmt->bind_param('i', $methodId);
    $stmt->execute();
    $method = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$method) {
        return [
            'ok' => false,
            'message' => 'Méthode de retrait introuvable',
            'manual_required' => true,
            'retry' => false,
        ];
    }

    /* Si paiement auto désactivé OU pas de credentials → paiement manuel */
    if ((int) $method['auto_payout'] !== 1 || empty($method['api_credentials'])) {
        return [
            'ok' => true,                 // ok = la validation BDD se fait quand même
            'message' => 'Paiement manuel — à effectuer dans l\'interface du provider',
            'manual_required' => true,    // mais l'admin doit agir hors plateforme
            'retry' => false,
        ];
    }

    /* Decode credentials JSON (déchiffrement rétrocompatible) */
    $rawCreds = function_exists('wt_decrypt')
        ? wt_decrypt((string) $method['api_credentials'])
        : (string) $method['api_credentials'];
    $creds = json_decode($rawCreds, true);
    if (!is_array($creds) || empty($creds)) {
        return [
            'ok' => false,
            'message' => 'Credentials API corrompues',
            'manual_required' => true,
            'retry' => false,
        ];
    }

    /* Routing par provider : on cherche le mot-clé dans le slug `k`
     * pour accepter des nommages variés comme :
     *   faucetpay, faucetpay-usd, fp-btc, faucet-usd
     *   payeer, payeer-usd
     *   binance, binance-usdt, binance-pay
     */
    $k = strtolower((string) $method['k']);

    if (str_contains($k, 'faucetpay') || str_starts_with($k, 'fp-') || str_starts_with($k, 'fp_')) {
        return wt_payout_faucetpay($withdrawal, $method, $creds);
    }
    if (str_contains($k, 'payeer')) {
        return wt_payout_payeer($withdrawal, $method, $creds);
    }
    if (str_contains($k, 'binance')) {
        return wt_payout_binance($withdrawal, $method, $creds);
    }

    /* Provider inconnu → paiement manuel */
    return [
        'ok' => true,
        'message' => 'Provider non automatisé — paiement manuel',
        'manual_required' => true,
        'retry' => false,
    ];
}

/**
 * Handler FaucetPay.
 *
 * Doc API : https://faucetpay.io/api
 * Endpoint utilisé : POST https://faucetpay.io/api/v1/send
 *
 * Paramètres requis : api_key, currency, amount, to (adresse)
 */
function wt_payout_faucetpay(array $withdrawal, array $method, array $creds): array
{
    if (empty($creds['api_key'])) {
        return [
            'ok' => false,
            'message' => 'Clé API FaucetPay manquante',
            'manual_required' => true,
            'retry' => false,
        ];
    }

    $payload = [
        'api_key'  => (string) $creds['api_key'],
        'amount'   => (string) $withdrawal['payout_amount'],
        'to'       => (string) $withdrawal['payout_address'],
        'currency' => strtoupper((string) $withdrawal['payout_currency']),
    ];

    $response = wt_payout_http_post(
        'https://faucetpay.io/api/v1/send',
        $payload,
        15  // timeout 15s
    );

    if (!$response['ok']) {
        return [
            'ok' => false,
            'message' => 'Erreur réseau FaucetPay : ' . $response['error'],
            'manual_required' => false,
            'retry' => true,
        ];
    }

    $data = $response['data'] ?? [];

    /* FaucetPay renvoie : {"status": 200, "message": "OK", "payout_id": "xxx", ...}
     * Statuts d'erreur courants :
     *   456 = solde insuffisant
     *   457 = adresse invalide
     *   458 = montant trop bas
     *   459 = clé API invalide
     */
    $status = (int) ($data['status'] ?? 0);

    if ($status === 200) {
        return [
            'ok' => true,
            'message' => 'Paiement envoyé via FaucetPay',
            'txid' => isset($data['payout_id']) ? (string) $data['payout_id'] : null,
            'manual_required' => false,
            'retry' => false,
        ];
    }

    /* Erreurs métier (pas de retry) */
    $errMap = [
        456 => 'Solde FaucetPay insuffisant — rechargez votre compte',
        457 => 'Adresse de destination invalide',
        458 => 'Montant inférieur au minimum FaucetPay',
        459 => 'Clé API FaucetPay invalide',
    ];
    $msg = $errMap[$status] ?? ('Erreur FaucetPay #' . $status . ' : ' . ($data['message'] ?? 'inconnue'));

    return [
        'ok' => false,
        'message' => $msg,
        'manual_required' => in_array($status, [457, 458], true),
        'retry' => in_array($status, [], true),  // pas de retry sur erreurs métier
    ];
}

/**
 * Stub Payeer — à implémenter quand tu auras un compte marchand.
 * Doc API : https://payeer.com/en/for-business/main/api-merchant
 */
function wt_payout_payeer(array $withdrawal, array $method, array $creds): array
{
    return [
        'ok' => true,
        'message' => 'Payeer auto-payout non encore implémenté — paiement manuel',
        'manual_required' => true,
        'retry' => false,
    ];
}

/**
 * Stub Binance Pay — à implémenter.
 * Doc API : https://developers.binance.com/docs/binance-pay/api-payout
 */
function wt_payout_binance(array $withdrawal, array $method, array $creds): array
{
    return [
        'ok' => true,
        'message' => 'Binance Pay auto-payout non encore implémenté — paiement manuel',
        'manual_required' => true,
        'retry' => false,
    ];
}

/**
 * Helper interne : POST HTTP avec curl, retour décodé JSON.
 *
 * @return array ['ok' => bool, 'data' => array|null, 'error' => string]
 */
function wt_payout_http_post(string $url, array $payload, int $timeoutSec = 15): array
{
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'error' => 'cURL non disponible sur ce serveur'];
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query($payload),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $timeoutSec,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_USERAGENT      => 'Wintaskly/1.0 (+payout-dispatcher)',
        CURLOPT_HTTPHEADER     => ['Accept: application/json'],
    ]);

    $body = curl_exec($ch);
    $errno = curl_errno($ch);
    $err   = curl_error($ch);
    curl_close($ch);

    if ($errno !== 0) {
        return ['ok' => false, 'error' => $err ?: 'curl error #' . $errno];
    }

    $data = json_decode((string) $body, true);
    if (!is_array($data)) {
        return ['ok' => false, 'error' => 'Réponse non-JSON du provider'];
    }

    return ['ok' => true, 'data' => $data, 'error' => ''];
}
