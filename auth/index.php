<?php
/**
 * Wintaskly — /auth/ → redirection permanente vers /auth/login.php
 */
declare(strict_types=1);
require __DIR__ . '/../includes/init.php';
header('Location: ' . wt_url('/auth/login.php'), true, 301);
exit;
