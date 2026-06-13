<?php
/**
 * Wintaskly — Compatibilité : /auth/register.php → /auth/signup.php
 * Préserve les paramètres (ex: ?ref=CODE).
 */
declare(strict_types=1);
require __DIR__ . '/../includes/init.php';

$qs = $_SERVER['QUERY_STRING'] ?? '';
$loc = wt_url('/auth/signup.php') . ($qs !== '' ? '?' . $qs : '');
header('Location: ' . $loc, true, 301);
exit;
