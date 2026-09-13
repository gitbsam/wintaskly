<?php
/**
 * Wintaskly — includes/quiz.php
 *
 * WintQuiz.
 *
 * Une partie s'achève à 5 bonnes réponses. Une mauvaise réponse ne fait
 * pas perdre : elle allonge la partie, et la bonne réponse est montrée
 * avant de passer à la suivante.
 *
 * Règle absolue : la bonne réponse ne quitte jamais le serveur avant
 * validation. Tout ce qui part vers le navigateur se lit dans le code
 * source, et un quiz dont on lit les réponses n'est plus un quiz.
 */
declare(strict_types=1);

if (!function_exists('wt_quiz_enabled')) {
    function wt_quiz_enabled(): bool
    {
        return (string) cfg('quiz.enabled', '0') === '1';
    }
}

if (!function_exists('wt_quiz_visible_for')) {
    /** En mode test, seuls les administrateurs voient la tâche. */
    function wt_quiz_visible_for(?array $user): bool
    {
        if (!wt_quiz_enabled()) { return false; }
        if ((string) cfg('quiz.test_mode', '1') !== '1') { return true; }
        return $user !== null && (($user['role'] ?? '') === 'admin');
    }
}

if (!function_exists('wt_quiz_next_available')) {
    /**
     * Horodatage de la prochaine partie possible, 0 si disponible.
     *
     * Le délai court depuis la FIN de la dernière partie gagnée, pas
     * depuis son début : sinon une partie longue réduirait l'attente,
     * ce qui récompenserait les mauvaises réponses.
     */
    function wt_quiz_next_available(int $userId): int
    {
        $h = max(0, (int) cfg('quiz.cooldown_hours', 3));
        if ($h === 0) { return 0; }
        try {
            $r = db_one(
                "SELECT ended_at FROM quiz_sessions
                  WHERE user_id = " . max(0, $userId) . " AND status = 'gagne'
                  ORDER BY id DESC LIMIT 1"
            );
            if (!$r || empty($r['ended_at'])) { return 0; }
            $t = (strtotime((string) $r['ended_at'] . ' UTC') ?: 0) + $h * 3600;
            return $t > time() ? $t : 0;
        } catch (Throwable $e) {
            error_log('[Wintaskly quiz] ' . $e->getMessage());
            return 0;
        }
    }
}

if (!function_exists('wt_quiz_session')) {
    /**
     * Partie en cours, créée si nécessaire.
     *
     * La cagnotte est figée à l'ouverture : si vous la modifiez pendant
     * qu'une partie est en cours, le joueur touche ce qui lui avait été
     * annoncé. Changer la règle en cours de route est le meilleur moyen
     * de perdre sa crédibilité.
     *
     * @return array|null null si le délai n'est pas écoulé
     */
    function wt_quiz_session(int $userId, bool $create = true): ?array
    {
        if ($userId <= 0) { return null; }
        try {
            $s = db_one(
                "SELECT * FROM quiz_sessions
                  WHERE user_id = " . $userId . " AND status = 'en_cours'
                  ORDER BY id DESC LIMIT 1"
            );
            if ($s) { return $s; }
            if (!$create) { return null; }
            if (wt_quiz_next_available($userId) > 0) { return null; }

            $goal   = max(1, min(20, (int) cfg('quiz.goal', 5)));
            $reward = (float) cfg('quiz.reward_coins', 125);

            $st = db()->prepare(
                "INSERT INTO quiz_sessions (user_id, goal, reward) VALUES (?, ?, ?)"
            );
            $st->bind_param('iid', $userId, $goal, $reward);
            $st->execute();
            $id = (int) $st->insert_id;
            $st->close();

            return db_one("SELECT * FROM quiz_sessions WHERE id = " . $id);
        } catch (Throwable $e) {
            error_log('[Wintaskly quiz] ' . $e->getMessage());
            return null;
        }
    }
}

if (!function_exists('wt_quiz_pick_question')) {
    /**
     * Tire la prochaine question et la mémorise dans la partie.
     *
     * Deux exclusions : les questions déjà posées dans CETTE partie, et
     * celles auxquelles ce joueur a répondu récemment. Sans la seconde,
     * un joueur assidu reverrait les mêmes questions toute la journée —
     * et il lui suffirait de les noter une fois.
     *
     * Le tableau rendu ne contient PAS la bonne réponse.
     *
     * @return array|null
     */
    function wt_quiz_pick_question(array $session): ?array
    {
        $sid  = (int) $session['id'];
        $uid  = (int) $session['user_id'];
        $lang = substr((string) (cfg('site.lang', 'fr') ?: 'fr'), 0, 2);

        try {
            /* Question déjà en attente : on la rend telle quelle. Tirer
               une nouvelle question à chaque affichage permettrait de
               recharger jusqu'à tomber sur une question facile. */
            if (!empty($session['current_qid'])) {
                $q = db_one("SELECT * FROM quiz_questions WHERE id = " . (int) $session['current_qid']);
                if ($q) { return wt_quiz_public($q); }
            }

            $deja = array_values(array_filter(array_map('intval',
                explode(',', (string) ($session['asked_ids'] ?? '')))));

            $jours = max(0, (int) cfg('quiz.repeat_after_days', 30));
            $recentes = [];
            if ($jours > 0) {
                $res = db()->query(
                    "SELECT DISTINCT a.question_id
                       FROM quiz_answers a
                       JOIN quiz_sessions s ON s.id = a.session_id
                      WHERE s.user_id = " . $uid . "
                        AND a.answered_at > DATE_SUB(UTC_TIMESTAMP(), INTERVAL " . $jours . " DAY)"
                );
                while ($r = $res->fetch_row()) { $recentes[] = (int) $r[0]; }
                $res->free();
            }

            $exclues = array_unique(array_merge($deja, $recentes));
            $clause  = $exclues ? " AND id NOT IN (" . implode(',', array_map('intval', $exclues)) . ")" : '';

            $q = db_one(
                "SELECT * FROM quiz_questions
                  WHERE active = 1 AND lang = '" . db()->real_escape_string($lang) . "'"
                  . $clause . " ORDER BY RAND() LIMIT 1"
            );

            /* Banque épuisée : on retire l'exclusion des questions
               récentes plutôt que de bloquer le joueur. Mieux vaut une
               question déjà vue qu'une page qui ne propose rien. */
            if (!$q && $recentes) {
                $clause = $deja ? " AND id NOT IN (" . implode(',', array_map('intval', $deja)) . ")" : '';
                $q = db_one(
                    "SELECT * FROM quiz_questions
                      WHERE active = 1 AND lang = '" . db()->real_escape_string($lang) . "'"
                      . $clause . " ORDER BY RAND() LIMIT 1"
                );
            }
            if (!$q) { return null; }

            $deja[] = (int) $q['id'];
            $liste  = implode(',', array_slice($deja, -200));
            $qid    = (int) $q['id'];

            $st = db()->prepare(
                "UPDATE quiz_sessions SET current_qid = ?, asked_ids = ? WHERE id = ?"
            );
            $st->bind_param('isi', $qid, $liste, $sid);
            $st->execute();
            $st->close();

            db()->query("UPDATE quiz_questions SET asked = asked + 1 WHERE id = " . $qid);

            return wt_quiz_public($q);
        } catch (Throwable $e) {
            error_log('[Wintaskly quiz] ' . $e->getMessage());
            return null;
        }
    }
}

if (!function_exists('wt_quiz_public')) {
    /**
     * Version de la question destinée au navigateur.
     *
     * Ne contient ni `correct`, ni `explain` : les deux révéleraient la
     * réponse avant validation.
     */
    function wt_quiz_public(array $q): array
    {
        return [
            'id'       => (int) $q['id'],
            'question' => (string) $q['question'],
            'a'        => (string) $q['a'],
            'b'        => (string) $q['b'],
            'c'        => (string) $q['c'],
            'd'        => (string) $q['d'],
            'category' => (string) $q['category'],
        ];
    }
}

if (!function_exists('wt_quiz_answer')) {
    /**
     * Enregistre une réponse et fait avancer la partie.
     *
     * @return array{ok:bool, error:string, correct:bool, good:string,
     *               explain:string, count:int, goal:int, finished:bool,
     *               reward:float}
     */
    function wt_quiz_answer(int $userId, string $given): array
    {
        $out = ['ok' => false, 'error' => '', 'correct' => false, 'good' => '',
                'explain' => '', 'count' => 0, 'goal' => 5, 'finished' => false,
                'reward' => 0.0, 'camp_correct' => 0, 'camp_goal' => 0,
                'camp_reached' => false, 'camp_reward' => 0.0];

        $given = strtolower(trim($given));
        if (!in_array($given, ['a', 'b', 'c', 'd'], true)) {
            $out['error'] = 'invalid';
            return $out;
        }

        $db = db();
        try {
            $db->begin_transaction();

            $st = $db->prepare(
                "SELECT * FROM quiz_sessions
                  WHERE user_id = ? AND status = 'en_cours'
                  ORDER BY id DESC LIMIT 1 FOR UPDATE"
            );
            $st->bind_param('i', $userId);
            $st->execute();
            $s = $st->get_result()->fetch_assoc();
            $st->close();

            if (!$s) { $db->rollback(); $out['error'] = 'no_session'; return $out; }
            if (empty($s['current_qid'])) { $db->rollback(); $out['error'] = 'no_question'; return $out; }

            $q = db_one("SELECT * FROM quiz_questions WHERE id = " . (int) $s['current_qid']);
            if (!$q) { $db->rollback(); $out['error'] = 'no_question'; return $out; }

            $juste = ($given === (string) $q['correct']);
            $cnt   = (int) $s['correct_cnt'] + ($juste ? 1 : 0);
            $asked = (int) $s['asked_cnt'] + 1;
            $goal  = (int) $s['goal'];
            $fini  = ($cnt >= $goal);

            $st = $db->prepare(
                "INSERT INTO quiz_answers (session_id, question_id, given, is_correct)
                 VALUES (?, ?, ?, ?)"
            );
            $j = $juste ? 1 : 0;
            $st->bind_param('iisi', $s['id'], $q['id'], $given, $j);
            $st->execute();
            $st->close();

            /* current_qid est remis à NULL : la question a servi, on ne
               doit pas pouvoir y répondre une seconde fois. */
            $st = $db->prepare(
                "UPDATE quiz_sessions
                    SET correct_cnt = ?, asked_cnt = ?, current_qid = NULL,
                        status = ?, ended_at = IF(? = 'gagne', UTC_TIMESTAMP(), ended_at)
                  WHERE id = ?"
            );
            $etat = $fini ? 'gagne' : 'en_cours';
            $st->bind_param('iissi', $cnt, $asked, $etat, $etat, $s['id']);
            $st->execute();
            $st->close();

            $db->commit();

            $campagne = ['reached' => false, 'reward' => 0.0, 'correct' => 0, 'goal' => 0];
            if ($juste) {
                $db->query("UPDATE quiz_questions SET correct_n = correct_n + 1 WHERE id = " . (int) $q['id']);
                /* La progression de campagne avance a chaque bonne
                   reponse, independamment des parties. */
                $campagne = wt_quiz_progress_add($userId);
            }

            $recompense = 0.0;
            if ($fini) {
                $recompense = (float) $s['reward'];
                if ($recompense > 0) {
                    award_user($userId, $recompense, 5, 'quiz', 'session:' . (int) $s['id']);
                }
            }

            $out['ok']       = true;
            $out['correct']  = $juste;
            /* La bonne réponse n'est révélée qu'APRÈS validation, et
               seulement si le joueur s'est trompé. */
            $out['good']     = $juste ? '' : (string) $q['correct'];
            $out['explain']  = $juste ? '' : (string) ($q['explanation'] ?? '');
            $out['count']    = $cnt;
            $out['goal']     = $goal;
            $out['finished'] = $fini;
            $out['reward']   = $recompense;
            $out['camp_correct'] = (int) $campagne['correct'];
            $out['camp_goal']    = (int) ($campagne['goal'] ?: cfg('quiz.campaign_goal', 500));
            $out['camp_reached'] = (bool) $campagne['reached'];
            $out['camp_reward']  = (float) $campagne['reward'];
        } catch (Throwable $e) {
            try { $db->rollback(); } catch (Throwable $e2) {}
            error_log('[Wintaskly quiz] ' . $e->getMessage());
            $out['error'] = 'db';
        }
        return $out;
    }
}

if (!function_exists('wt_quiz_campaign')) {
    /** Numéro de campagne en cours. */
    function wt_quiz_campaign(): int
    {
        return max(1, (int) cfg('quiz.campaign', 1));
    }
}

if (!function_exists('wt_quiz_progress')) {
    /**
     * Progression de campagne d'un joueur.
     *
     * Compte les bonnes réponses depuis le début de la campagne, toutes
     * parties confondues. Une mauvaise réponse ne fait pas reculer la
     * barre : elle ne la fait simplement pas avancer.
     *
     * @return array{correct:int, goal:int, pct:int, reward:float, claimed:bool}
     */
    function wt_quiz_progress(int $userId): array
    {
        $goal   = max(1, (int) cfg('quiz.campaign_goal', 500));
        $reward = (float) cfg('quiz.campaign_reward', 5000);
        $camp   = wt_quiz_campaign();
        $out = ['correct' => 0, 'goal' => $goal, 'pct' => 0,
                'reward' => $reward, 'claimed' => false];

        if ($userId <= 0) { return $out; }
        try {
            $r = db_one(
                "SELECT correct_cnt, claimed FROM quiz_progress
                  WHERE user_id = " . $userId . " AND campaign = " . $camp
            );
            if ($r) {
                $out['correct'] = (int) $r['correct_cnt'];
                $out['claimed'] = ((int) $r['claimed'] === 1);
            }
        } catch (Throwable $e) {
            error_log('[Wintaskly quiz] ' . $e->getMessage());
        }
        $out['pct'] = (int) round(min(100, $out['correct'] / $goal * 100));
        return $out;
    }
}

if (!function_exists('wt_quiz_progress_add')) {
    /**
     * Ajoute une bonne réponse à la progression et verse la cagnotte de
     * campagne si l'objectif est atteint.
     *
     * Le versement est protégé par la colonne `claimed` et un UPDATE
     * conditionnel : deux requêtes simultanées ne peuvent pas déclencher
     * deux versements.
     *
     * @return array{reached:bool, reward:float, correct:int, goal:int}
     */
    function wt_quiz_progress_add(int $userId): array
    {
        $goal   = max(1, (int) cfg('quiz.campaign_goal', 500));
        $reward = (float) cfg('quiz.campaign_reward', 5000);
        $camp   = wt_quiz_campaign();
        $out = ['reached' => false, 'reward' => 0.0, 'correct' => 0, 'goal' => $goal];

        if ($userId <= 0) { return $out; }
        try {
            $st = db()->prepare(
                "INSERT INTO quiz_progress (user_id, campaign, correct_cnt)
                 VALUES (?, ?, 1)
                 ON DUPLICATE KEY UPDATE correct_cnt = correct_cnt + 1"
            );
            $st->bind_param('ii', $userId, $camp);
            $st->execute();
            $st->close();

            $r = db_one(
                "SELECT correct_cnt, claimed FROM quiz_progress
                  WHERE user_id = " . $userId . " AND campaign = " . $camp
            );
            $out['correct'] = (int) ($r['correct_cnt'] ?? 0);

            if ($out['correct'] >= $goal && (int) ($r['claimed'] ?? 0) === 0 && $reward > 0) {
                /* AND claimed = 0 dans le WHERE : si une autre requête
                   est passée entre la lecture et l'écriture, celle-ci ne
                   touche aucune ligne et ne verse rien. */
                $st = db()->prepare(
                    "UPDATE quiz_progress SET claimed = 1
                      WHERE user_id = ? AND campaign = ? AND claimed = 0"
                );
                $st->bind_param('ii', $userId, $camp);
                $st->execute();
                $pris = ($st->affected_rows === 1);
                $st->close();

                if ($pris) {
                    award_user($userId, $reward, 25, 'quiz', 'campaign:' . $camp);
                    $out['reached'] = true;
                    $out['reward']  = $reward;
                }
            }
        } catch (Throwable $e) {
            error_log('[Wintaskly quiz] ' . $e->getMessage());
        }
        return $out;
    }
}
