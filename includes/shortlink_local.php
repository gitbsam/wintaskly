<?php
/**
 * Wintaskly — includes/shortlink_local.php
 *
 * Moteur du raccourcisseur maison : génération des codes, machine à
 * états du parcours, validation d'étape.
 *
 * Principe directeur, dont tout le reste découle : le navigateur ne
 * décide de rien. Le formulaire porte un jeton, jamais un numéro
 * d'étape. Le serveur relit la ligne, compare le jeton et l'horodatage
 * qu'il détient lui-même, puis avance d'un cran — jamais plus.
 */
declare(strict_types=1);

/* Alphabet du code court : 62 caractères, casse significative.
   Hors du bloc if : une constante ne peut pas y être déclarée. */
if (!defined('WT_SL_ALPHABET')) {
    define('WT_SL_ALPHABET', 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789');
    define('WT_SL_CODE_LEN', 10);
}

if (!function_exists('wt_sl_code_generate')) {
    /**
     * Tire un code de 10 caractères.
     *
     * random_int() et non rand() : le second est prévisible, et un code
     * devinable permettrait de reprendre le parcours d'autrui.
     */
    function wt_sl_code_generate(): string
    {
        $out = '';
        $max = strlen(WT_SL_ALPHABET) - 1;
        for ($i = 0; $i < WT_SL_CODE_LEN; $i++) {
            $out .= WT_SL_ALPHABET[random_int(0, $max)];
        }
        return $out;
    }
}

if (!function_exists('wt_sl_local_by_key')) {
    /** Retrouve une campagne par sa clé API. Null si absente ou désactivée. */
    function wt_sl_local_by_key(string $apiKey): ?array
    {
        $apiKey = trim($apiKey);
        if ($apiKey === '') { return null; }
        try {
            $st = db()->prepare(
                "SELECT * FROM shortlinks_local WHERE api_key = ? AND api_active = 1 LIMIT 1"
            );
            $st->bind_param('s', $apiKey);
            $st->execute();
            $row = $st->get_result()->fetch_assoc();
            $st->close();
            return $row ?: null;
        } catch (Throwable $e) {
            error_log('[Wintaskly sl_local] ' . $e->getMessage());
            return null;
        }
    }
}

if (!function_exists('wt_sl_run_create')) {
    /**
     * Ouvre un parcours et retourne son URL courte.
     *
     * @param  array  $local       Ligne de shortlinks_local
     * @param  string $destination URL de rappel, stockée sans jamais être émise
     * @return string|null         URL courte complète, ou null en cas d'échec
     */
    function wt_sl_run_create(array $local, string $destination): ?string
    {
        $destination = trim($destination);
        if ($destination === '' || !filter_var($destination, FILTER_VALIDATE_URL)) {
            error_log('[Wintaskly sl_local] destination invalide');
            return null;
        }

        $localId = (int) $local['id'];
        $minutes = max(1, (int) ($local['run_minutes'] ?? 30));
        $ip      = wt_ip_bin();

        /* Reprise sur collision. À 62^10 combinaisons elle n'arrivera
           probablement jamais, mais une collision silencieuse créditerait
           le mauvais utilisateur : cinq tentatives coûtent moins cher que
           ce risque. */
        for ($try = 0; $try < 5; $try++) {
            $code  = wt_sl_code_generate();
            $token = bin2hex(random_bytes(16));
            try {
                $st = db()->prepare(
                    "INSERT INTO shortlink_local_runs
                        (local_id, code, destination, step, step_token,
                         step_started_at, expires_at, status, ip)
                     VALUES (?, ?, ?, 0, ?, UTC_TIMESTAMP(),
                             DATE_ADD(UTC_TIMESTAMP(), INTERVAL ? MINUTE), 'en_cours', ?)"
                );
                $st->bind_param('isssis', $localId, $code, $destination, $token, $minutes, $ip);
                $st->execute();
                $st->close();
                return wt_url('/' . $code);
            } catch (mysqli_sql_exception $e) {
                /* 1062 = doublon sur uniq_code : on retire un code. Toute
                   autre erreur est réelle et doit remonter. */
                if ((int) $e->getCode() !== 1062) {
                    error_log('[Wintaskly sl_local] ' . $e->getMessage());
                    return null;
                }
            }
        }
        error_log('[Wintaskly sl_local] 5 collisions de code consecutives');
        return null;
    }
}

if (!function_exists('wt_sl_run_by_code')) {
    /** Charge un parcours et sa campagne. Null si le code est inconnu. */
    function wt_sl_run_by_code(string $code): ?array
    {
        if ($code === '' || !preg_match('/^[A-Za-z0-9]{9,10}$/', $code)) { return null; }
        try {
            /* BINARY sur la comparaison : sans lui, MySQL confondrait
               aB3xY9kL2p et Ab3Xy9Kl2P selon la collation de la table. */
            $st = db()->prepare(
                "SELECT r.*, l.steps_count, l.step_seconds, l.final_seconds, l.title,
                        l.content_type, l.content_ref
                   FROM shortlink_local_runs r
                   JOIN shortlinks_local l ON l.id = r.local_id
                  WHERE r.code = BINARY ? LIMIT 1"
            );
            $st->bind_param('s', $code);
            $st->execute();
            $row = $st->get_result()->fetch_assoc();
            $st->close();
            return $row ?: null;
        } catch (Throwable $e) {
            error_log('[Wintaskly sl_local] ' . $e->getMessage());
            return null;
        }
    }
}

if (!function_exists('wt_sl_run_mark')) {
    /** Change le statut d'un parcours. */
    function wt_sl_run_mark(int $runId, string $status): void
    {
        try {
            $st = db()->prepare(
                "UPDATE shortlink_local_runs
                    SET status = ?, completed_at = UTC_TIMESTAMP()
                  WHERE id = ? AND status = 'en_cours'"
            );
            $st->bind_param('si', $status, $runId);
            $st->execute();
            $st->close();
        } catch (Throwable $e) {
            error_log('[Wintaskly sl_local] ' . $e->getMessage());
        }
    }
}

if (!function_exists('wt_sl_run_bind_user')) {
    /**
     * Rattache le parcours au premier utilisateur qui l'ouvre.
     *
     * L'API est appelée de serveur à serveur et ne connaît pas
     * l'utilisateur. Le code étant intirable au hasard, le premier
     * arrivé est légitimement son destinataire — et à partir de là il
     * est le seul admis.
     *
     * @return bool false si le parcours appartient à quelqu'un d'autre
     */
    function wt_sl_run_bind_user(array $run, int $userId): bool
    {
        $owner = $run['user_id'] === null ? 0 : (int) $run['user_id'];
        if ($owner > 0) { return $owner === $userId; }
        if ($userId <= 0) { return false; }
        try {
            $st = db()->prepare(
                "UPDATE shortlink_local_runs SET user_id = ?
                  WHERE id = ? AND user_id IS NULL"
            );
            $st->bind_param('ii', $userId, $run['id']);
            $st->execute();
            $ok = $st->affected_rows > 0;
            $st->close();
            /* Zéro ligne touchée = une autre requête a gagné la course.
               On relit pour savoir si c'est le même utilisateur. */
            if (!$ok) {
                $fresh = db_one("SELECT user_id FROM shortlink_local_runs WHERE id = " . (int) $run['id']);
                return $fresh && (int) $fresh['user_id'] === $userId;
            }
            return true;
        } catch (Throwable $e) {
            error_log('[Wintaskly sl_local] ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('wt_sl_step_validate')) {
    /**
     * Valide l'étape en cours et avance d'un cran.
     *
     * Trois vérifications, toutes sur des données que le serveur détient :
     * le jeton doit correspondre à celui en base, le temps imposé doit
     * être écoulé selon step_started_at, et le parcours ne doit pas être
     * expiré. Le numéro d'étape envoyé par le navigateur n'est jamais lu.
     *
     * @return array{ok:bool, reason:string, step:int, done:bool}
     */
    function wt_sl_step_validate(array $run, string $token): array
    {
        $fail = static fn(string $r, int $s = 0): array
            => ['ok' => false, 'reason' => $r, 'step' => $s, 'done' => false];

        if ($run['status'] !== 'en_cours') { return $fail('closed'); }

        if (strtotime((string) $run['expires_at'] . ' UTC') < time()) {
            wt_sl_run_mark((int) $run['id'], 'expire');
            return $fail('expired');
        }

        /* hash_equals et non === : la comparaison à temps constant évite
           de laisser deviner un jeton octet par octet. */
        if ($token === '' || !hash_equals((string) $run['step_token'], $token)) {
            /* Un jeton faux est soit une erreur de double soumission, soit
               une tentative de rejeu. Dans les deux cas on ferme : mieux
               vaut un utilisateur qui recommence qu'un parcours sautable. */
            wt_sl_run_mark((int) $run['id'], 'rejete');
            return $fail('bad_token');
        }

        $step     = (int) $run['step'];
        $total    = max(1, (int) $run['steps_count']);
        $isFinal  = ($step >= $total);
        $required = $isFinal ? (int) $run['final_seconds'] : (int) $run['step_seconds'];
        $started  = strtotime((string) $run['step_started_at'] . ' UTC') ?: 0;

        /* Une seconde de tolérance : entre l'affichage du chrono et
           l'arrivée du POST, la latence réseau peut faire perdre quelques
           dixièmes, et refuser un utilisateur honnête coûte plus cher que
           de laisser passer une seconde. */
        if ($started > 0 && (time() - $started) < ($required - 1)) {
            return $fail('too_fast', $step);
        }

        $newStep  = $step + 1;
        $newToken = bin2hex(random_bytes(16));

        try {
            /* La condition sur step_token rend l'avance atomique : deux
               soumissions simultanées ne peuvent pas franchir deux étapes. */
            $st = db()->prepare(
                "UPDATE shortlink_local_runs
                    SET step = ?, step_token = ?, step_started_at = UTC_TIMESTAMP()
                  WHERE id = ? AND step_token = ? AND status = 'en_cours'"
            );
            $st->bind_param('isis', $newStep, $newToken, $run['id'], $token);
            $st->execute();
            $moved = $st->affected_rows > 0;
            $st->close();
            if (!$moved) { return $fail('race', $step); }
        } catch (Throwable $e) {
            error_log('[Wintaskly sl_local] ' . $e->getMessage());
            return $fail('db', $step);
        }

        /* Après la dernière étape vient l'écran final ; c'est sa
           validation qui termine le parcours. */
        $done = ($newStep > $total);
        if ($done) { wt_sl_run_mark((int) $run['id'], 'termine'); }

        return ['ok' => true, 'reason' => '', 'step' => $newStep, 'done' => $done];
    }
}
