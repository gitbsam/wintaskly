<?php
/**
 * Wintaskly — cron/tasks/bingo_daily.php
 *
 * Fait avancer le bingo (modèle cycle de 7 jours) :
 *   - Règle une partie en fin de vie dont le minuit est passé (distribution
 *     du jackpot + ouverture d'une nouvelle partie).
 *   - Ouvre une partie s'il n'y en a pas.
 *   - Effectue le tirage du jour s'il manque.
 *   - Vérifie les conditions de fin (max_days / carton plein).
 *
 * Double le mécanisme "lazy" : le cron garantit le tirage même sans visiteur.
 * Fréquence min : 1 heure (le travail réel n'a lieu qu'au changement de jour).
 */
declare(strict_types=1);

wt_cron_register('bingo_daily', static function (): string {
    if (!function_exists('wt_bingo_enabled') || !wt_bingo_enabled()) {
        return 'bingo disabled';
    }
    $round = wt_bingo_tick();
    if (!$round) {
        return 'no round';
    }
    return sprintf(
        'round #%d status=%s days_drawn=%d/%d jackpot=%d',
        (int) $round['id'], $round['status'],
        (int) $round['days_drawn'], (int) $round['max_days'],
        (int) $round['jackpot']
    );
}, 3600);
