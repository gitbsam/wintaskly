<?php
/**
 * Wintaskly — POST /api/testimonial_submit.php
 */
declare(strict_types=1);
require __DIR__ . '/../includes/init.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') wt_json(['ok' => false, 'error' => 'method'], 405);
if (!csrf_check($_POST['_csrf'] ?? null))   wt_json(['ok' => false, 'error' => t('common.error')], 403);

$u = current_user();
if (!$u || ($u['status'] ?? '') !== 'active') {
    wt_json(['ok' => false, 'error' => t('testi.must_active')], 401);
}

$rating = (int)($_POST['rating'] ?? 0);
$title  = trim((string)($_POST['title'] ?? ''));
$body   = trim((string)($_POST['body']  ?? ''));

if ($rating < 1 || $rating > 5)         wt_json(['ok' => false, 'error' => t('testi.invalid_rating')]);
if ($title === ''  || wt_strlen($title) > 120)  wt_json(['ok' => false, 'error' => t('testi.invalid_title')]);
if ($body  === ''  || wt_strlen($body) > 2000)  wt_json(['ok' => false, 'error' => t('testi.invalid_body')]);

// Un seul témoignage par utilisateur en attente OU approuvé (anti-spam)
$db = db();
$stmt = $db->prepare(
    "SELECT id FROM testimonials
      WHERE user_id = ? AND status IN ('pending','approved')
      LIMIT 1"
);
$stmt->bind_param('i', $u['id']);
$stmt->execute();
$existing = $stmt->get_result()->fetch_assoc();
$stmt->close();
if ($existing) {
    wt_json(['ok' => false, 'error' => t('testi.already_submitted')]);
}

$stmt = $db->prepare(
    "INSERT INTO testimonials (user_id, rating, title, body)
     VALUES (?, ?, ?, ?)"
);
$stmt->bind_param('iiss', $u['id'], $rating, $title, $body);
$stmt->execute();
$stmt->close();

wt_json(['ok' => true, 'message' => (string) t('testi.received')]);
