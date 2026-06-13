<?php
/**
 * Wintaskly — Déconnexion.
 * Détruit la session puis redirige vers l'accueil.
 */
require __DIR__ . '/../includes/init.php';

if (current_user()) {
    $uid = (int)($_SESSION['uid'] ?? 0);
    auth_remember_clear($uid > 0 ? $uid : null);

    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(), '',
            time() - 42000,
            $params['path'],
            $params['domain'],
            $params['secure'],
            $params['httponly']
        );
    }
    session_destroy();
} else {
    // Cookie remember-me orphelin éventuellement présent
    auth_remember_clear();
}

header('Location: ' . wt_url('/'));
exit;
