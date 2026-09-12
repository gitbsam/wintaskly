<?php
/**
 * Wintaskly — cron/tasks/shortlink_cleanup.php
 *
 * Ferme les parcours et les tentatives laissés ouverts.
 *
 * Depuis la 9.62.0, l'ouverture d'un nouveau parcours périme déjà les
 * précédents : cette tâche ne corrige donc plus une faille, elle tient
 * la base propre.
 *
 * Sans elle, deux effets s'accumulent. Les lignes « en cours » de
 * joueurs partis depuis des semaines gonflent la table, et surtout
 * elles faussent tout comptage : impossible de savoir combien de
 * parcours sont réellement actifs si les abandons y figurent.
 */
declare(strict_types=1);

/**
 * Fenêtre au-delà de laquelle une tentative en attente est abandonnée.
 *
 * 24 heures et non quelques minutes : un joueur peut légitimement
 * ouvrir un lien, être interrompu, et revenir le terminer plus tard
 * dans la journée. Fermer trop tôt lui ferait perdre une tâche qu'il
 * avait commencée de bonne foi.
 */
const WT_SL_ATTEMPT_TTL_HOURS = 24;

wt_cron_register('shortlink_cleanup', static function (): string {
    $parts = [];

    /* 1) Parcours du raccourcisseur maison. Ils portent leur propre
          date d'expiration, réglée par campagne : on s'y fie plutôt que
          d'imposer un délai uniforme. */
    try {
        $st = db()->prepare(
            "UPDATE shortlink_local_runs
                SET status = 'expire', completed_at = UTC_TIMESTAMP()
              WHERE status = 'en_cours' AND expires_at < UTC_TIMESTAMP()"
        );
        $st->execute();
        $n = $st->affected_rows;
        $st->close();
        $parts[] = $n . ' parcours local/locaux';
    } catch (Throwable $e) {
        /* Table absente sur une installation qui n'utilise pas le
           raccourcisseur maison : ce n'est pas une erreur. */
        $parts[] = 'parcours locaux : ' . $e->getMessage();
    }

    /* 2) Tentatives vers les prestataires externes. Elles n'ont pas de
          date d'expiration en base, d'où la fenêtre fixe. */
    try {
        $ttl = WT_SL_ATTEMPT_TTL_HOURS;
        $st  = db()->prepare(
            "UPDATE shortlink_attempts
                SET status = 'expire', completed_at = UTC_TIMESTAMP()
              WHERE status = 'en_attente'
                AND started_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL ? HOUR)"
        );
        $st->bind_param('i', $ttl);
        $st->execute();
        $n = $st->affected_rows;
        $st->close();
        $parts[] = $n . ' tentative(s)';
    } catch (Throwable $e) {
        $parts[] = 'tentatives : ' . $e->getMessage();
    }

    /* 3) Purge des parcours anciens. On ne garde pas indéfiniment des
          lignes terminées : au-delà de 90 jours elles ne servent plus ni
          à justifier un paiement, ni à repérer une fraude.
          Les codes eux-mêmes restent hors d'atteinte d'une
          réattribution : le générateur en tire 10 caractères au hasard
          sur 62, la collision avec un code purgé est négligeable. */
    try {
        $st = db()->prepare(
            "DELETE FROM shortlink_local_runs
              WHERE status <> 'en_cours'
                AND completed_at IS NOT NULL
                AND completed_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 90 DAY)"
        );
        $st->execute();
        $n = $st->affected_rows;
        $st->close();
        if ($n > 0) { $parts[] = $n . ' ancien(s) purge(s)'; }
    } catch (Throwable $e) {
        error_log('[Wintaskly sl_cleanup] ' . $e->getMessage());
    }

    return implode(', ', $parts);
}, 3600);
