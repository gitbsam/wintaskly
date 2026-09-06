<?php
/**
 * Wintaskly — includes/payout_addresses.php
 * ---------------------------------------------------------------------------
 * Gestion des adresses de paiement de confiance.
 *
 * Une adresse suit deux états :
 *   • enregistrée mais NON confirmée — inutilisable pour un retrait ;
 *   • confirmée — utilisable, après vérification renforcée.
 *
 * La confirmation est délibérément séparée de l'enregistrement. Sans cette
 * séparation, un attaquant ayant une session ouverte ajouterait son adresse
 * et retirerait dans la foulée : la barrière n'existerait que dans
 * l'interface.
 */
declare(strict_types=1);

if (!function_exists('wt_payout_addresses')) {

    /**
     * Adresses d'un utilisateur, éventuellement filtrées sur une méthode.
     *
     * @param int      $userId
     * @param int|null $methodId Limiter à une méthode de retrait
     * @param bool     $onlyConfirmed Ne renvoyer que les adresses utilisables
     */
    function wt_payout_addresses(int $userId, ?int $methodId = null, bool $onlyConfirmed = false): array
    {
        if ($userId <= 0) { return []; }
        $sql = "SELECT a.id, a.method_id, a.label, a.address, a.confirmed_at, a.last_used_at,
                       m.label AS method_label, m.currency
                  FROM user_payout_addresses a
                  JOIN withdrawal_methods m ON m.id = a.method_id
                 WHERE a.user_id = ?";
        $types = 'i';
        $args  = [$userId];
        if ($methodId !== null) { $sql .= " AND a.method_id = ?"; $types .= 'i'; $args[] = $methodId; }
        if ($onlyConfirmed)     { $sql .= " AND a.confirmed_at IS NOT NULL"; }
        $sql .= " ORDER BY a.confirmed_at IS NULL, a.last_used_at DESC, a.id DESC";

        try {
            $stmt = db()->prepare($sql);
            $stmt->bind_param($types, ...$args);
            $stmt->execute();
            $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
            return $rows;
        } catch (Throwable $e) {
            error_log('[Wintaskly payout] ' . $e->getMessage()
                      . ' — appliquez sql/migration_payout_addresses.sql');
            return [];
        }
    }

    /**
     * Enregistre une adresse, à l'état NON confirmé.
     *
     * @return array ['ok' => bool, 'id' => int|null, 'error' => string|null]
     */
    function wt_payout_address_add(int $userId, int $methodId, string $address, string $label = ''): array
    {
        $address = trim($address);
        $label   = trim($label);

        if ($userId <= 0 || $methodId <= 0 || $address === '') {
            return ['ok' => false, 'id' => null, 'error' => 'invalid'];
        }
        if (mb_strlen($address) > 255) {
            return ['ok' => false, 'id' => null, 'error' => 'too_long'];
        }

        /* La méthode doit exister et être active : sinon on enregistrerait
           une adresse rattachée à un moyen de paiement retiré. */
        $m = db_one("SELECT id FROM withdrawal_methods WHERE id = ? AND active = 1 LIMIT 1",
                    [$methodId], 'i');
        if (!$m) { return ['ok' => false, 'id' => null, 'error' => 'method']; }

        /* Plafond volontaire. Une liste d'adresses n'a pas vocation à être
           longue, et un nombre illimité faciliterait l'usage de la table
           comme espace de stockage arbitraire. */
        $cnt = db_one("SELECT COUNT(*) c FROM user_payout_addresses WHERE user_id = ?", [$userId], 'i');
        if ((int) ($cnt['c'] ?? 0) >= 10) {
            return ['ok' => false, 'id' => null, 'error' => 'limit'];
        }

        try {
            $stmt = db()->prepare(
                "INSERT INTO user_payout_addresses (user_id, method_id, label, address)
                 VALUES (?, ?, ?, ?)"
            );
            $stmt->bind_param('iiss', $userId, $methodId, $label, $address);
            $stmt->execute();
            $id = (int) db()->insert_id;
            $stmt->close();
            return ['ok' => true, 'id' => $id, 'error' => null];
        } catch (Throwable $e) {
            /* Doublon : l'adresse existe déjà pour cette méthode. Ce n'est
               pas une erreur pour l'utilisateur, juste une redite. */
            if (str_contains($e->getMessage(), 'uniq_user_method_addr')) {
                return ['ok' => false, 'id' => null, 'error' => 'duplicate'];
            }
            error_log('[Wintaskly payout] ' . $e->getMessage());
            return ['ok' => false, 'id' => null, 'error' => 'db'];
        }
    }

    /**
     * Où cette adresse est-elle déjà enregistrée, chez cet utilisateur ?
     *
     * Sert à prévenir avant d'ajouter, pas à interdire : réutiliser le même
     * e-mail FaucetPay pour BTC et pour TRX est un usage normal. On veut
     * seulement que l'utilisateur relise l'adresse une fois de plus, parce
     * qu'une faute de frappe recopiée reste une faute de frappe.
     *
     * @return array Lignes avec method_id, method_label et confirmed_at
     */
    function wt_payout_address_siblings(int $userId, string $address): array
    {
        $address = trim($address);
        if ($userId <= 0 || $address === '') { return []; }
        try {
            $stmt = db()->prepare(
                "SELECT a.id, a.method_id, a.confirmed_at, m.label AS method_label
                   FROM user_payout_addresses a
                   JOIN withdrawal_methods m ON m.id = a.method_id
                  WHERE a.user_id = ? AND a.address = ?
                  ORDER BY m.sort_order, m.id"
            );
            $stmt->bind_param('is', $userId, $address);
            $stmt->execute();
            $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
            return $rows;
        } catch (Throwable $e) {
            error_log('[Wintaskly payout] ' . $e->getMessage());
            return [];
        }
    }

    /** Marque une adresse comme confirmée (après vérification renforcée). */
    function wt_payout_address_confirm(int $userId, int $addressId): bool
    {
        if ($userId <= 0 || $addressId <= 0) { return false; }
        try {
            $stmt = db()->prepare(
                "UPDATE user_payout_addresses
                    SET confirmed_at = UTC_TIMESTAMP()
                  WHERE id = ? AND user_id = ? AND confirmed_at IS NULL"
            );
            $stmt->bind_param('ii', $addressId, $userId);
            $stmt->execute();
            $ok = $stmt->affected_rows > 0;
            $stmt->close();

            if ($ok && function_exists('wt_notify')) {
                /* Notification systématique : si l'utilisateur n'est pas à
                   l'origine de l'ajout, c'est le signal qui lui permet de
                   réagir avant qu'un retrait ne parte. */
                wt_notify($userId, 'security',
                    (string) t('payout.notif_added_title'),
                    (string) t('payout.notif_added_body'));
            }
            return $ok;
        } catch (Throwable $e) {
            error_log('[Wintaskly payout] ' . $e->getMessage());
            return false;
        }
    }

    /** Supprime une adresse. Sans effet sur les retraits déjà passés. */
    function wt_payout_address_delete(int $userId, int $addressId): bool
    {
        if ($userId <= 0 || $addressId <= 0) { return false; }
        try {
            $stmt = db()->prepare(
                "DELETE FROM user_payout_addresses WHERE id = ? AND user_id = ?"
            );
            $stmt->bind_param('ii', $addressId, $userId);
            $stmt->execute();
            $ok = $stmt->affected_rows > 0;
            $stmt->close();
            return $ok;
        } catch (Throwable $e) {
            error_log('[Wintaskly payout] ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Résout une adresse confirmée appartenant à l'utilisateur.
     *
     * C'est LE contrôle serveur : le formulaire n'envoie qu'un identifiant,
     * et cette fonction vérifie que l'adresse existe, appartient bien à
     * l'utilisateur, correspond à la méthode demandée et est confirmée.
     * Sans cela, il suffirait de modifier la valeur envoyée pour retirer
     * vers n'importe quoi.
     *
     * @return string|null L'adresse, ou null si le contrôle échoue
     */
    function wt_payout_address_resolve(int $userId, int $addressId, int $methodId): ?string
    {
        if ($userId <= 0 || $addressId <= 0) { return null; }
        $row = db_one(
            "SELECT address FROM user_payout_addresses
              WHERE id = ? AND user_id = ? AND method_id = ?
                AND confirmed_at IS NOT NULL LIMIT 1",
            [$addressId, $userId, $methodId], 'iii'
        );
        if (!$row) { return null; }

        /* Trace de dernier usage : sert à trier la liste, et donne un repère
           en cas de contestation. */
        try {
            $stmt = db()->prepare(
                "UPDATE user_payout_addresses SET last_used_at = UTC_TIMESTAMP() WHERE id = ?"
            );
            $stmt->bind_param('i', $addressId);
            $stmt->execute();
            $stmt->close();
        } catch (Throwable $e) { /* non bloquant */ }

        return (string) $row['address'];
    }

    /** Le mode « adresse enregistrée obligatoire » est-il actif ? */
    function wt_payout_requires_saved(): bool
    {
        return (string) cfg('payout.require_saved_address', '1') === '1';
    }
}
