<?php
/**
 * Wintaskly — cron/tasks/blog_queue_alert.php
 *
 * Prévient par courriel quand la réserve d'articles programmés arrive à
 * son terme.
 *
 * Le blog publie un article par jour ouvré à partir d'articles chargés à
 * l'avance avec une date future. Quand le dernier paraît, rien ne se
 * casse : le blog cesse simplement de se mettre à jour, sans erreur et
 * sans message. C'est le genre de panne qu'on découvre des semaines plus
 * tard, quand le mal est fait pour le référencement.
 *
 * Deux alertes seulement, et une seule fois chacune :
 *   - à 5 jours ouvrés restants : il est temps de préparer le lot suivant
 *   - à 0 : le blog ne publiera plus rien demain
 *
 * L'état est mémorisé dans `config` pour ne pas renvoyer le même message
 * toutes les heures. Une alerte répétée devient un bruit qu'on ignore, et
 * l'ignorer une fois suffit à passer à côté de la suivante.
 */
declare(strict_types=1);

wt_cron_register('blog_queue_alert', static function (): string {
    if (!function_exists('wt_blog_queue_status')) {
        return 'wt_blog_queue_status indisponible';
    }

    $q    = wt_blog_queue_status();
    $left = (int) $q['workdays_left'];

    /* Palier atteint, ou aucun. On ne notifie qu'au franchissement. */
    $stage = $left <= 0 ? 'empty' : ($left <= 5 ? 'low' : '');

    /* Lecture de l'état précédent. */
    $prev = '';
    try {
        $row  = db_one("SELECT v FROM config WHERE k = 'blog_queue_alert_stage'");
        $prev = $row ? (string) $row['v'] : '';
    } catch (Throwable $e) {
        error_log('[Wintaskly blog queue] ' . $e->getMessage());
    }

    /* La réserve est repassée au-dessus du seuil : on réarme, sans quoi
       le prochain épuisement passerait sous silence. */
    if ($stage === '' ) {
        if ($prev !== '') { wt_blog_queue_remember(''); }
        return sprintf('%d article(s) en attente, %d jour(s) ouvre(s) devant', (int) $q['remaining'], $left);
    }

    if ($stage === $prev) {
        return sprintf('palier %s deja signale (%d jour(s) ouvre(s))', $stage, $left);
    }

    /* Destinataires : les comptes administrateurs actifs. */
    $sent = 0;
    try {
        $res = db()->query(
            "SELECT email, username FROM users
              WHERE role = 'admin' AND status = 'active' AND email <> ''"
        );
        while ($u = $res->fetch_assoc()) {
            $body = $stage === 'empty'
                ? "La reserve d'articles programmes est epuisee. Le blog ne publiera plus rien tant qu'aucun nouvel article n'aura ete charge avec une date future.\n\n"
                  . "Pour reprendre : generez le calendrier avec scripts/blog_schedule.php, redigez le lot suivant, puis appliquez le fichier SQL depuis /admin/migrations.php."
                : sprintf(
                    "Il reste %d jour(s) ouvre(s) de publication, soit %d article(s) programmes. Le dernier est prevu le %s.\n\n"
                  . "C'est le moment de preparer le lot suivant : generez les dates avec scripts/blog_schedule.php, puis chargez les articles avec une date future.",
                    $left, (int) $q['remaining'],
                    wt_format_datetime((string) $q['last_date'], 'd/m/Y')
                );

            wt_mail((string) $u['email'], 'security_alert', [
                'username' => (string) ($u['username'] ?? 'admin'),
                'event'    => $stage === 'empty'
                            ? 'Blog : plus aucun article programme'
                            : 'Blog : la reserve d\'articles s\'epuise',
                'body'     => $body,
                'link'     => wt_url('/admin/'),
            ]);
            $sent++;
        }
        $res->free();
    } catch (Throwable $e) {
        return 'ECHEC envoi : ' . $e->getMessage();
    }

    /* Aucun destinataire joint : on n'enregistre pas le palier.
       Le marquer reviendrait à considérer l'alerte comme faite alors que
       personne ne l'a reçue — et le jour où un compte administrateur
       existe, plus rien ne se déclencherait. */
    if ($sent === 0) {
        return sprintf('palier %s atteint mais AUCUN administrateur joignable', $stage);
    }

    wt_blog_queue_remember($stage);
    wt_admin_log('blog_queue_alert', ['stage' => $stage, 'workdays_left' => $left]);

    return sprintf('ALERTE %s envoyee a %d administrateur(s) — %d jour(s) ouvre(s)', $stage, $sent, $left);
}, 21600);   // 4 fois par jour suffit : la file bouge d'un article par jour

if (!function_exists('wt_blog_queue_remember')) {
    /** Mémorise le palier déjà signalé, pour ne pas répéter l'alerte. */
    function wt_blog_queue_remember(string $stage): void
    {
        try {
            $st = db()->prepare(
                "INSERT INTO config (k, v) VALUES ('blog_queue_alert_stage', ?)
                 ON DUPLICATE KEY UPDATE v = VALUES(v)"
            );
            $st->bind_param('s', $stage);
            $st->execute();
            $st->close();
        } catch (Throwable $e) {
            error_log('[Wintaskly blog queue] ' . $e->getMessage());
        }
    }
}
