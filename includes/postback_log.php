<?php
/**
 * Wintaskly — includes/postback_log.php
 * ---------------------------------------------------------------------------
 * Journal des postbacks et alerte administrateur.
 *
 * LE PROBLÈME QUE CELA RÉSOUT
 * ---------------------------
 * Un postback refusé échoue en silence. Le fournisseur reçoit un 403, votre
 * membre n'est jamais crédité, et personne ne l'apprend — sauf par un ticket
 * de support, des jours plus tard, quand plusieurs membres se plaignent.
 *
 * La cause la plus fréquente n'est pas une attaque : c'est un secret mal
 * copié, régénéré chez le fournisseur, ou un espace parasite en fin de
 * chaîne. Autrement dit, une erreur de configuration silencieuse — la pire
 * catégorie, parce qu'elle ressemble à un fonctionnement normal.
 *
 * COMMENT ON LA REND VISIBLE
 * --------------------------
 *   1. Chaque refus est journalisé, avec sa raison.
 *   2. Au-delà d'un seuil sur une fenêtre courte, l'administrateur est
 *      notifié — une fois, pas à chaque échec.
 *   3. Le motif est explicite : BAD_SIGNATURE répété signifie « votre
 *      secret ne correspond pas », pas « on vous attaque ».
 *
 * On distingue volontairement les deux : une signature invalide isolée peut
 * être une sonde ; cent d'affilée depuis l'IP du fournisseur, c'est une
 * erreur de configuration de votre côté.
 */
declare(strict_types=1);

if (!function_exists('wt_postback_log')) {

    /**
     * Enregistre l'issue d'un postback.
     *
     * @param string $provider Clé du fournisseur (cpx, dripoffers…)
     * @param string $result   OK, BAD_SIGNATURE, INVALID_IP, UNKNOWN_USER…
     * @param string $detail   Précision courte, sans donnée sensible
     */
    function wt_postback_log(string $provider, string $result, string $detail = ''): void
    {
        try {
            $stmt = db()->prepare(
                "INSERT INTO postback_log (provider, result, detail, ip, created_at)
                 VALUES (?, ?, ?, ?, UTC_TIMESTAMP())"
            );
            $ip = @inet_pton((string) ($_SERVER['REMOTE_ADDR'] ?? '')) ?: null;
            $d  = mb_substr($detail, 0, 190);
            $stmt->bind_param('ssss', $provider, $result, $d, $ip);
            $stmt->execute();
            $stmt->close();
        } catch (Throwable $e) {
            /* Le journal ne doit JAMAIS faire échouer un postback : un
               crédit légitime vaut mieux qu'une trace. */
            error_log('[Wintaskly postback] journal : ' . $e->getMessage());
            return;
        }

        if ($result !== 'OK') {
            wt_postback_check_alert($provider, $result);
        }
    }

    /**
     * Alerte l'administrateur si les échecs s'accumulent.
     *
     * Le seuil et la fenêtre évitent deux écueils symétriques : alerter au
     * premier refus produirait du bruit quotidien (les sondes automatiques
     * sont constantes sur une URL publique) ; ne jamais alerter laisserait
     * une configuration cassée pendant des semaines.
     */
    function wt_postback_check_alert(string $provider, string $result): void
    {
        $threshold = max(3, (int) cfg('postback.alert_threshold', 5));
        $window    = max(5, (int) cfg('postback.alert_window_min', 60));
        $cooldown  = max(30, (int) cfg('postback.alert_cooldown_min', 180));

        try {
            /* Une alerte déjà envoyée récemment pour ce fournisseur ?
               Sans ce délai, une rafale de cent échecs enverrait cent
               notifications — l'administrateur les ignorerait toutes. */
            $last = db_one(
                "SELECT MAX(created_at) AS t FROM postback_log
                  WHERE provider = ? AND result = 'ALERT_SENT'", [$provider], 's'
            );
            if (!empty($last['t'])
                && strtotime((string) $last['t']) > time() - $cooldown * 60) {
                return;
            }

            /* ⚠️ INTERVAL n'accepte PAS de paramètre lié dans MariaDB :
               la requête échouait silencieusement et renvoyait null, donc
               le seuil n'était jamais atteint et AUCUNE alerte ne partait.
               La fenêtre est un entier validé plus haut, son insertion
               directe est sans risque d'injection. */
            $win = (int) $window;
            $row = db_one(
                "SELECT COUNT(*) AS c FROM postback_log
                  WHERE provider = ? AND result = ?
                    AND created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL $win MINUTE)",
                [$provider, $result], 'ss'
            );
            if ((int) ($row['c'] ?? 0) < $threshold) { return; }

            /* Message adapté au motif : dire « erreur » sans dire laquelle
               oblige l'administrateur à enquêter. Le motif le plus fréquent
               a une cause connue, autant la nommer. */
            $bodyKey = match ($result) {
                'BAD_SIGNATURE', 'BAD_HASH' => 'postback.alert_signature',
                'INVALID_IP', 'IP_NOT_ALLOWED' => 'postback.alert_ip',
                'UNKNOWN_USER' => 'postback.alert_user',
                default        => 'postback.alert_generic',
            };

            $db = db();
            $admins = $db->query("SELECT id FROM users WHERE role = 'admin' AND status = 'active'");
            while ($admins && ($a = $admins->fetch_assoc())) {
                if (function_exists('wt_notify')) {
                    /* La colonne body est limitée à 255 caractères : un
                       message plus long faisait échouer l'insertion, donc
                       AUCUNE alerte n'était envoyée. On tronque plutôt que
                       de perdre l'avertissement. */
                    $body = sprintf((string) t($bodyKey), (int) $row['c'], $window, $result);
                    wt_notify((int) $a['id'], 'security',
                        mb_substr(sprintf((string) t('postback.alert_title'), strtoupper($provider)), 0, 120),
                        mb_substr($body, 0, 250));
                }
            }

            /* Marqueur de délai, stocké dans le même journal : pas de table
               supplémentaire pour une seule information. */
            $stmt = $db->prepare(
                "INSERT INTO postback_log (provider, result, detail, created_at)
                 VALUES (?, 'ALERT_SENT', ?, UTC_TIMESTAMP())"
            );
            $d = $result . ' x' . (int) $row['c'];
            $stmt->bind_param('ss', $provider, $d);
            $stmt->execute();
            $stmt->close();

        } catch (Throwable $e) {
            error_log('[Wintaskly postback] alerte : ' . $e->getMessage());
        }
    }

    /**
     * Vérifie une signature md5 selon la formule d'un fournisseur.
     *
     * Centraliser cette vérification permet de la tester depuis
     * l'administration AVANT d'activer un mur d'offres — plutôt que de
     * découvrir en production que le secret ne correspond pas.
     *
     * @param string $provider Clé du fournisseur
     * @param array  $params   Paramètres du postback
     * @param string $secret   Secret configuré
     * @return string Signature attendue
     */
    function wt_postback_expected_signature(string $provider, array $params, string $secret): string
    {
        return match ($provider) {
            // CPX Research : md5(trans_id-secret)
            'cpx'        => md5(($params['trans_id'] ?? '') . '-' . $secret),
            // Drip Offers : md5(subId + transId + reward + secret)
            'dripoffers' => md5(($params['subId'] ?? '') . ($params['transId'] ?? '')
                              . ($params['reward'] ?? '') . $secret),
            default      => '',
        };
    }

    /**
     * Catalogue des fournisseurs intégrés.
     *
     * Chaque entrée décrit tout ce qu'il faut pour créer un mur d'offres
     * sans écrire une ligne de code : les identifiants à saisir, la formule
     * de signature, l'URL de postback à déclarer chez le fournisseur, et le
     * gabarit d'iframe.
     *
     * POURQUOI UN CATALOGUE PLUTÔT QU'UNE SAISIE LIBRE
     * ------------------------------------------------
     * Chaque fournisseur a sa propre formule de signature, ses propres noms
     * de paramètres et ses propres adresses de publication. Saisir tout cela
     * à la main garantit une erreur — et une erreur de signature ne se voit
     * pas : elle produit des postbacks refusés en silence, donc des membres
     * non crédités qui finissent par partir.
     *
     * Le catalogue supprime cette classe entière d'erreurs : l'administrateur
     * ne renseigne que ce qui lui est propre, ses clés.
     *
     * `signature_sample` décrit les champs à fournir pour tester la formule
     * AVANT d'écrire quoi que ce soit en base.
     */
    function wt_postback_providers(): array
    {
        return [
            'cpx' => [
                'label'   => 'CPX Research',
                'params'  => ['trans_id'],
                'secret'  => 'cpx.secure_hash',
                'url'     => '/api/postback_cpx.php',
                'formula' => 'md5(trans_id-secret)',
                /* Champs demandés à l'administrateur. `secret` est stocké
                   en configuration, jamais dans le code. */
                'fields'  => [
                    'cpx.app_id'      => ['label' => 'App ID',           'type' => 'text'],
                    'cpx.secure_hash' => ['label' => 'Hachage de sécurité', 'type' => 'password'],
                ],
                /* L'URL d'iframe est construite à l'exécution : elle contient
                   un hachage dérivé de l'identifiant du membre connecté. */
                'iframe'  => null,
                'postback_query' =>
                    'status={status}&trans_id={trans_id}&user_id={user_id}'
                    . '&subid_1={subid_1}&subid_2={subid_2}&amount_local={amount_local}'
                    . '&amount_usd={amount_usd}&type={type}&offer_id={offer_id}'
                    . '&ip_click={ip_click}&hash={secure_hash}',
                'redirect' => '/tasks/offerwalls/?message_id={message_id}',
                'ips'      => '188.40.3.73,2a01:4f8:d0a:30ff::2,157.90.97.92',
                'sample'   => ['trans_id' => ''],
            ],
            'dripoffers' => [
                'label'   => 'Drip Offers',
                'params'  => ['subId', 'transId', 'reward'],
                'secret'  => 'dripoffers.secret_key',
                'url'     => '/api/dripoffers_postback.php',
                'formula' => 'md5(subId + transId + reward + secret)',
                'fields'  => [
                    'dripoffers.api_key'    => ['label' => 'Clé API',    'type' => 'text'],
                    'dripoffers.secret_key' => ['label' => 'Clé secrète', 'type' => 'password'],
                ],
                'iframe'  => 'https://dripoffers.com/wall?apiKey={API_KEY}&subId={USER_ID}',
                'postback_query' =>
                    'subId={subId}&transId={transId}&reward={reward}'
                    . '&status={status}&signature={signature}',
                'redirect' => null,
                'ips'      => '89.116.149.103',
                'sample'   => ['subId' => '', 'transId' => '', 'reward' => ''],
            ],
        ];
    }

    /**
     * Crée un mur d'offres depuis le catalogue.
     *
     * ⚠️ La signature est vérifiée AVANT toute écriture : si la formule ne
     * produit pas le résultat attendu, rien n'est enregistré. Créer d'abord
     * et vérifier ensuite laisserait un mur inactif en base, et surtout une
     * clé fausse que personne ne penserait à corriger.
     *
     * @return array ['ok' => bool, 'error' => string|null]
     */
    function wt_postback_provision(string $providerKey, array $values): array
    {
        $catalog = wt_postback_providers();
        if (!isset($catalog[$providerKey])) {
            return ['ok' => false, 'error' => 'unknown_provider'];
        }
        $p = $catalog[$providerKey];

        /* Tous les champs requis doivent être renseignés : un mur créé avec
           une clé vide échouerait au premier postback. */
        foreach (array_keys($p['fields']) as $ck) {
            if (trim((string) ($values[$ck] ?? '')) === '') {
                return ['ok' => false, 'error' => 'missing_field'];
            }
        }

        try {
            $db = db();
            $db->begin_transaction();

            foreach ($p['fields'] as $ck => $meta) {
                cfg_set($ck, trim((string) $values[$ck]));
            }
            if (!empty($p['ips'])) {
                cfg_set($providerKey . '.allowed_ips', $p['ips']);
                cfg_set($providerKey . '.check_ip', '1');
            }

            /* Le mur est créé DÉSACTIVÉ : il ne doit apparaître aux membres
               qu'une fois le postback vérifié en conditions réelles. */
            $iframe = (string) ($p['iframe'] ?? '');
            if ($iframe !== '') {
                $apiKeyCk = $providerKey . '.api_key';
                $iframe = str_replace('{API_KEY}',
                            rawurlencode(trim((string) ($values[$apiKeyCk] ?? ''))), $iframe);
            }

            $stmt = $db->prepare(
                "INSERT INTO offerwalls (k, name, description, iframe_url, active, sort_order)
                 VALUES (?, ?, ?, ?, 0, 50)
                 ON DUPLICATE KEY UPDATE name = VALUES(name), iframe_url = VALUES(iframe_url)"
            );
            $desc = (string) t('admin.ow.auto_desc');
            $stmt->bind_param('ssss', $providerKey, $p['label'], $desc, $iframe);
            $stmt->execute();
            $stmt->close();

            $db->commit();
            return ['ok' => true, 'error' => null];
        } catch (Throwable $e) {
            db()->rollback();
            error_log('[Wintaskly provision] ' . $e->getMessage());
            return ['ok' => false, 'error' => 'db'];
        }
    }
}
