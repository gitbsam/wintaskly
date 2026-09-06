<?php
/**
 * Wintaskly — cron/tasks/rates_refresh.php
 *
 * Entretient includes/rates_cache.json, lu par /dashboard/withdraw.php et
 * par /api/withdraw_submit.php pour convertir les coins en devise de retrait.
 *
 * Pourquoi une tâche dédiée : jusqu'ici le cache n'était régénéré que
 * lorsqu'un administrateur ouvrait /admin/payment_methods.php. Sur une
 * installation neuve, ou après plusieurs jours sans visite admin, le
 * fichier était absent ou périmé — et api/withdraw_submit.php refuse alors
 * toute demande en crypto (taux <= 0). Les retraits s'arrêtaient sans
 * qu'aucun message ne l'explique.
 *
 * Fréquence : 30 minutes. get_cached_rates() a son propre cache de
 * 10 minutes et ne réinterroge Binance que si le fichier a vieilli, donc
 * un passage plus fréquent ne coûterait rien mais n'apporterait rien.
 */
declare(strict_types=1);

wt_cron_register('rates_refresh', static function (): string {
    if (!function_exists('get_cached_rates')) {
        return 'get_cached_rates indisponible';
    }

    $rates = get_cached_rates();
    if (!is_array($rates) || !$rates) {
        return 'ECHEC : aucun taux recupere';
    }

    /* Un taux à 0 signifie que Binance n'a pas répondu pour cette devise.
       Ce n'est pas une erreur fatale — le repli à 0 est volontaire et fait
       rejeter proprement les retraits concernés — mais si une méthode de
       retrait active l'utilise, les demandes échoueront. On le signale
       dans le journal du cron pour que ce soit visible avant la
       réclamation d'un utilisateur. */
    $zero = [];
    foreach ($rates as $cur => $val) {
        if ((float) $val <= 0) { $zero[] = (string) $cur; }
    }

    $blocked = [];
    if ($zero) {
        try {
            $in   = implode(',', array_fill(0, count($zero), '?'));
            $stmt = db()->prepare(
                "SELECT DISTINCT currency FROM withdrawal_methods
                  WHERE active = 1 AND currency IN ($in)"
            );
            $stmt->bind_param(str_repeat('s', count($zero)), ...$zero);
            $stmt->execute();
            $blocked = array_column($stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'currency');
            $stmt->close();
        } catch (Throwable $e) {
            error_log('[Wintaskly rates] ' . $e->getMessage());
        }
    }

    $msg = sprintf('%d taux en cache', count($rates));
    if ($zero)    { $msg .= ' — sans cours : ' . implode(', ', $zero); }
    if ($blocked) { $msg .= ' — RETRAITS BLOQUES : ' . implode(', ', $blocked); }

    return $msg;
}, 1800);
