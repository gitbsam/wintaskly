<?php
/**
 * Wintaskly — Admin · Bannières publicitaires (maison)
 * ----------------------------------------------------------------------
 * Upload intelligent de bannières :
 *   1) L'admin choisit un fichier (PNG/JPG/WEBP — le GIF est refusé).
 *   2) Le serveur détecte les dimensions réelles (getimagesize, pas
 *      l'extension du nom de fichier) et les compare aux tailles IAB
 *      standard (728x90, 468x60, 300x250).
 *   3) Si l'image correspond exactement (± 2px) → acceptée directement.
 *      Si elle est PLUS GRANDE que la taille standard la plus proche
 *      (même ratio ou proche) → un recadrage est proposé (étape 2).
 *      Sinon → stockée telle quelle en size_key='other'.
 *   4) Fichier final renommé et déplacé dans
 *      media/wintaskly/img/banners/ (dossier créé si absent), puis
 *      relu pour vérifier qu'il est bien en place et valide.
 *   5) Enregistrement en base (table ad_banners) + association possible
 *      à une zone publicitaire (ad_zones.banner_id).
 *
 * IMPORTANT : tous les blocs de traitement POST (upload, crop_confirm,
 * crop_cancel, toggle, delete, assign_zone) DOIVENT rester groupés ici,
 * tout en haut du fichier, AVANT la préparation des données d'affichage
 * et AVANT include header.php — sinon $notice/$error définis tardivement
 * n'ont plus aucun effet sur un HTML déjà émis.
 */
declare(strict_types=1);
require __DIR__ . '/../includes/init.php';
require_admin();

$db      = db();
$notice  = null;
$error   = null;

const WT_BANNER_DIR   = __DIR__ . '/../media/wintaskly/img/banners/';
const WT_BANNER_MAXKB = 5120; // 5 Mo
const WT_STANDARD_SIZES = [
    '728x90'  => [728, 90],
    '468x60'  => [468, 60],
    '300x250' => [300, 250],
    '320x50'  => [320, 50],
    '320x100' => [320, 100],
    '970x250' => [970, 250],
    '160x600' => [160, 600],
    '300x600' => [300, 600],
];
const WT_TOLERANCE_PX = 2; // tolérance pour un "match exact"

/**
 * Détermine la taille standard correspondante, ou signale qu'un
 * recadrage est nécessaire, ou retourne 'other'.
 *
 * @return array{mode:string, size_key:string, target:?array}
 *   mode = 'exact' | 'crop_needed' | 'other'
 */
function wt_banner_match_size(int $w, int $h): array
{
    foreach (WT_STANDARD_SIZES as $key => [$tw, $th]) {
        if (abs($w - $tw) <= WT_TOLERANCE_PX && abs($h - $th) <= WT_TOLERANCE_PX) {
            return ['mode' => 'exact', 'size_key' => $key, 'target' => [$tw, $th]];
        }
    }
    // Cherche une taille standard de même ratio (± 35%) que l'image est
    // assez grande pour couvrir intégralement (candidate au recadrage)
    $ratio = $w / max(1, $h);
    foreach (WT_STANDARD_SIZES as $key => [$tw, $th]) {
        $targetRatio = $tw / $th;
        if ($w >= $tw && $h >= $th && abs($ratio - $targetRatio) / $targetRatio < 0.35) {
            return ['mode' => 'crop_needed', 'size_key' => $key, 'target' => [$tw, $th]];
        }
    }
    // Assez grande mais ratio très différent : propose quand même le
    // recadrage vers la première taille standard que l'image peut couvrir.
    foreach (WT_STANDARD_SIZES as $key => [$tw, $th]) {
        if ($w >= $tw && $h >= $th) {
            return ['mode' => 'crop_needed', 'size_key' => $key, 'target' => [$tw, $th]];
        }
    }
    return ['mode' => 'other', 'size_key' => 'other', 'target' => null];
}

/** Nom de fichier final sûr, unique, avec la bonne extension. */
/**
 * L'image contient-elle des pixels réellement transparents ?
 *
 * GD marque comme "truecolor avec alpha" toute image PNG, même totalement
 * opaque. On échantillonne donc les pixels pour trancher : sans
 * transparence effective, la bannière peut partir en JPEG (bien plus léger
 * pour un visuel publicitaire) sans aucune perte visible.
 *
 * Échantillonnage plutôt que parcours complet : une grille de ~10 000
 * points suffit à détecter une zone transparente, sans coût CPU notable
 * sur les grandes images.
 */
function wt_img_has_alpha(\GdImage $im, int $w, int $h): bool
{
    $stepX = max(1, (int) ($w / 100));
    $stepY = max(1, (int) ($h / 100));
    for ($x = 0; $x < $w; $x += $stepX) {
        for ($y = 0; $y < $h; $y += $stepY) {
            // Bits 24-30 = canal alpha (0 = opaque, 127 = transparent)
            if ((($c = imagecolorat($im, $x, $y)) >> 24) & 0x7F) {
                return true;
            }
        }
    }
    return false;
}

function wt_banner_filename(string $sizeKey, string $ext): string
{
    return 'banner_' . preg_replace('/[^a-z0-9]/', '', strtolower($sizeKey))
         . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
}

/* ======================================================================
 * ÉTAPE 1 — Upload initial (détection + décision exact / crop / other)
 * ==================================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'upload') {
    if (!csrf_check($_POST['_csrf'] ?? null)) {
        $error = t('common.error');
    } elseif (empty($_FILES['banner']) || ($_FILES['banner']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        $error = 'Aucun fichier reçu ou erreur d\'upload.';
    } else {
        $tmp      = $_FILES['banner']['tmp_name'];
        $size     = (int) $_FILES['banner']['size'];
        $origName = (string) $_FILES['banner']['name'];

        if ($size > WT_BANNER_MAXKB * 1024) {
            $error = 'Fichier trop lourd (max ' . round(WT_BANNER_MAXKB / 1024, 1) . ' Mo).';
        } else {
            // Validation du VRAI contenu (pas l'extension) via getimagesize
            $info = @getimagesize($tmp);
            if ($info === false) {
                $error = 'Fichier image invalide ou illisible.';
            } else {
                [$w, $h] = [$info[0], $info[1]];
                $mime = $info['mime'];
                // GIF explicitement refusé, même si déguisé en .png
                $allowed = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'];
                if ($mime === 'image/gif') {
                    $error = 'Le format GIF n\'est pas accepté pour les bannières.';
                } elseif (!isset($allowed[$mime])) {
                    $error = 'Format non supporté (PNG, JPG ou WEBP uniquement).';
                } else {
                    $ext   = $allowed[$mime];
                    $match = wt_banner_match_size($w, $h);

                    if ($match['mode'] === 'crop_needed') {
                        // Stocke le fichier en zone d'attente + les infos en session
                        // pour l'étape 2 (recadrage), sans encore l'enregistrer en base.
                        if (!is_dir(WT_BANNER_DIR) && !mkdir(WT_BANNER_DIR, 0755, true) && !is_dir(WT_BANNER_DIR)) {
                            $error = 'Impossible de créer le dossier de stockage.';
                        } else {
                            $pendingName = 'pending_' . session_id() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                            $pendingPath = WT_BANNER_DIR . $pendingName;
                            if (move_uploaded_file($tmp, $pendingPath)) {
                                $_SESSION['wt_pending_banner'] = [
                                    'file'     => $pendingName,
                                    'ext'      => $ext,
                                    'width'    => $w,
                                    'height'   => $h,
                                    'size_key' => $match['size_key'],
                                    'target'   => $match['target'],
                                    'orig'     => $origName,
                                ];
                            } else {
                                $error = 'Échec de l\'enregistrement temporaire du fichier.';
                            }
                        }
                    } else {
                        // 'exact' ou 'other' : traitement direct, pas de recadrage
                        $sizeKey = $match['size_key'];
                        $finalW  = $match['mode'] === 'exact' ? $match['target'][0] : $w;
                        $finalH  = $match['mode'] === 'exact' ? $match['target'][1] : $h;

                        if (!is_dir(WT_BANNER_DIR) && !mkdir(WT_BANNER_DIR, 0755, true) && !is_dir(WT_BANNER_DIR)) {
                            $error = 'Impossible de créer le dossier de stockage.';
                        } else {
                            $finalName = wt_banner_filename($sizeKey, $ext);
                            $finalPath = WT_BANNER_DIR . $finalName;
                            if (!move_uploaded_file($tmp, $finalPath)) {
                                $error = 'Échec de l\'enregistrement du fichier.';
                            } else {
                                // Revérification : le fichier est-il bien là et valide ?
                                clearstatcache(true, $finalPath);
                                $recheck = file_exists($finalPath) ? @getimagesize($finalPath) : false;
                                if ($recheck === false) {
                                    @unlink($finalPath);
                                    $error = 'Le fichier final n\'a pas pu être vérifié après enregistrement.';
                                } else {
                                    $stmt = $db->prepare(
                                        "INSERT INTO ad_banners (size_key, width, height, filename, original_name)
                                         VALUES (?, ?, ?, ?, ?)"
                                    );
                                    $stmt->bind_param('siiss', $sizeKey, $finalW, $finalH, $finalName, $origName);
                                    $stmt->execute();
                                    $stmt->close();
                                    $notice = 'Bannière ajoutée (' . $sizeKey . ').';
                                }
                            }
                        }
                    }
                }
            }
        }
    }
}

/* ======================================================================
 * ÉTAPE 2 — Finalisation du recadrage (coordonnées reçues du JS)
 * ==================================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'crop_confirm') {
    if (!csrf_check($_POST['_csrf'] ?? null)) {
        $error = t('common.error');
    } elseif (empty($_SESSION['wt_pending_banner'])) {
        $error = 'Aucun upload en attente de recadrage.';
    } else {
        $pending     = $_SESSION['wt_pending_banner'];
        $pendingPath = WT_BANNER_DIR . $pending['file'];

        $cropX = max(0, (int) ($_POST['crop_x'] ?? 0));
        $cropY = max(0, (int) ($_POST['crop_y'] ?? 0));
        $cropW = max(1, (int) ($_POST['crop_w'] ?? 0));
        $cropH = max(1, (int) ($_POST['crop_h'] ?? 0));
        [$targetW, $targetH] = $pending['target'];

        if (!file_exists($pendingPath)) {
            $error = 'Le fichier temporaire a expiré, réessaie l\'upload.';
            unset($_SESSION['wt_pending_banner']);
        } else {
            $ext = $pending['ext'];
            $src = match ($ext) {
                'png'   => @imagecreatefrompng($pendingPath),
                'jpg'   => @imagecreatefromjpeg($pendingPath),
                'webp'  => @imagecreatefromwebp($pendingPath),
                default => false,
            };
            if ($src === false) {
                $error = 'Image source illisible pour le recadrage.';
            } else {
                // Borne le rectangle de recadrage aux dimensions réelles de la source
                $cropX = min($cropX, $pending['width'] - 1);
                $cropY = min($cropY, $pending['height'] - 1);
                $cropW = min($cropW, $pending['width'] - $cropX);
                $cropH = min($cropH, $pending['height'] - $cropY);

                $dst = imagecreatetruecolor($targetW, $targetH);
                if ($ext === 'png' || $ext === 'webp') {
                    imagealphablending($dst, false);
                    imagesavealpha($dst, true);
                }
                imagecopyresampled(
                    $dst, $src,
                    0, 0, $cropX, $cropY,
                    $targetW, $targetH, $cropW, $cropH
                );

                $sizeKey = $pending['size_key'];

                /* ------------------------------------------------------------
                 * Optimisation du poids à l'enregistrement.
                 *
                 * Avant : imagepng(..., 6) en truecolor produisait des
                 * bannières de 150 à 175 Ko pour un simple 300x250 — un poids
                 * absurde, d'autant plus pénalisant que plusieurs bannières
                 * du même format peuvent être servies en rotation sur une
                 * même page.
                 *
                 * Deux leviers :
                 *   1. Un PNG SANS transparence réelle est réenregistré en
                 *      JPEG : les bannières publicitaires sont presque
                 *      toujours des visuels photographiques, format pour
                 *      lequel le JPEG est bien plus efficace.
                 *   2. Un PNG AVEC transparence reste en PNG, mais passe en
                 *      compression maximale (9 au lieu de 6), sans aucune
                 *      perte de qualité.
                 * ---------------------------------------------------------- */
                $outExt = $ext;

                if ($ext === 'png' && !wt_img_has_alpha($dst, $targetW, $targetH)) {
                    /* Le PNG n'a aucune transparence : on encode les DEUX
                     * formats et on garde le plus léger.
                     *
                     * Pourquoi ne pas basculer systématiquement en JPEG :
                     * sur des visuels photographiques (le cas courant pour
                     * une bannière publicitaire) le JPEG gagne massivement
                     * — mesuré 173 Ko -> 28 Ko sur des bannières réelles.
                     * Mais sur un visuel à aplats ou dégradé synthétique,
                     * c'est le PNG qui gagne largement. Comparer les deux
                     * sorties est la seule méthode fiable, et le coût est
                     * négligeable (une seule fois, à l'upload). */
                    $tmpPng = WT_BANNER_DIR . '.opt_' . bin2hex(random_bytes(4)) . '.png';
                    $tmpJpg = WT_BANNER_DIR . '.opt_' . bin2hex(random_bytes(4)) . '.jpg';

                    $flat = imagecreatetruecolor($targetW, $targetH);
                    imagefill($flat, 0, 0, imagecolorallocate($flat, 255, 255, 255));
                    imagecopy($flat, $dst, 0, 0, 0, 0, $targetW, $targetH);

                    $okPng = imagepng($dst, $tmpPng, 9);
                    $okJpg = imagejpeg($flat, $tmpJpg, 85);
                    $sizePng = $okPng && is_file($tmpPng) ? filesize($tmpPng) : PHP_INT_MAX;
                    $sizeJpg = $okJpg && is_file($tmpJpg) ? filesize($tmpJpg) : PHP_INT_MAX;

                    if ($sizeJpg < $sizePng) {
                        $outExt = 'jpg';
                        $keep = $tmpJpg; $drop = $tmpPng;
                    } else {
                        $keep = $tmpPng; $drop = $tmpJpg;
                    }
                    imagedestroy($flat);

                    $finalName = wt_banner_filename($sizeKey, $outExt);
                    $finalPath = WT_BANNER_DIR . $finalName;
                    $saved = @rename($keep, $finalPath);
                    @unlink($drop);
                } else {
                    // Transparence à préserver, ou format déjà adapté :
                    // on reste sur l'extension d'origine, en compression max.
                    $finalName = wt_banner_filename($sizeKey, $outExt);
                    $finalPath = WT_BANNER_DIR . $finalName;
                    $saved = match ($outExt) {
                        'png'   => imagepng($dst, $finalPath, 9),
                        'jpg'   => imagejpeg($dst, $finalPath, 85),
                        'webp'  => imagewebp($dst, $finalPath, 85),
                        default => false,
                    };
                }
                $ext = $outExt; // la suite du traitement utilise l'extension réelle
                imagedestroy($src);
                imagedestroy($dst);
                @unlink($pendingPath); // nettoie le fichier temporaire

                if (!$saved) {
                    $error = 'Échec de l\'enregistrement de l\'image recadrée.';
                } else {
                    clearstatcache(true, $finalPath);
                    $recheck = file_exists($finalPath) ? @getimagesize($finalPath) : false;
                    if ($recheck === false) {
                        @unlink($finalPath);
                        $error = 'Le fichier recadré n\'a pas pu être vérifié.';
                    } else {
                        $stmt = $db->prepare(
                            "INSERT INTO ad_banners (size_key, width, height, filename, original_name)
                             VALUES (?, ?, ?, ?, ?)"
                        );
                        $stmt->bind_param('siiss', $sizeKey, $targetW, $targetH, $finalName, $pending['orig']);
                        $stmt->execute();
                        $stmt->close();
                        $notice = 'Bannière recadrée et enregistrée (' . $sizeKey . ').';
                    }
                }
                unset($_SESSION['wt_pending_banner']);
            }
        }
    }
}

/* Annulation du recadrage en attente */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'crop_cancel') {
    if (csrf_check($_POST['_csrf'] ?? null) && !empty($_SESSION['wt_pending_banner'])) {
        $p = $_SESSION['wt_pending_banner'];
        @unlink(WT_BANNER_DIR . $p['file']);
        unset($_SESSION['wt_pending_banner']);
        $notice = 'Recadrage annulé.';
    }
}

/* Activer / désactiver une bannière */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'toggle') {
    if (csrf_check($_POST['_csrf'] ?? null)) {
        $id = (int) ($_POST['id'] ?? 0);
        $stmt = $db->prepare("UPDATE ad_banners SET active = 1 - active WHERE id = ?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $stmt->close();
        $notice = t('admin.saved');
    }
}

/* Suppression (fichier + ligne) */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    if (csrf_check($_POST['_csrf'] ?? null)) {
        $id = (int) ($_POST['id'] ?? 0);
        $stmt = $db->prepare("SELECT filename FROM ad_banners WHERE id = ?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($row) {
            $stmt = $db->prepare("DELETE FROM ad_banners WHERE id = ?");
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $stmt->close();
            // La FK ad_zones.banner_id passe à NULL automatiquement (ON DELETE SET NULL)
            $path = WT_BANNER_DIR . $row['filename'];
            if (is_file($path)) { @unlink($path); }
            $notice = 'Bannière supprimée.';
        }
    }
}

/* Associer une bannière et/ou un format de repli à une zone */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'assign_zone') {
    if (csrf_check($_POST['_csrf'] ?? null)) {
        $zoneId      = (int) ($_POST['zone_id'] ?? 0);
        $bannerIdRaw = (string) ($_POST['banner_id'] ?? '');
        $bannerIdVal = $bannerIdRaw === '' ? null : (int) $bannerIdRaw;
        $sizeKeyRaw  = (string) ($_POST['size_key'] ?? '');
        $sizeKeyVal  = ($sizeKeyRaw !== '' && isset(WT_STANDARD_SIZES[$sizeKeyRaw])) ? $sizeKeyRaw : null;
        $stmt = $db->prepare("UPDATE ad_zones SET banner_id = ?, size_key = ? WHERE id = ?");
        $stmt->bind_param('isi', $bannerIdVal, $sizeKeyVal, $zoneId);
        $stmt->execute();
        $stmt->close();
        $notice = 'Association mise à jour.';
    }
}

/* ======================================================================
 * Préparation des données d'affichage
 * ==================================================================== */
$banners = [];
if ($res = $db->query("SELECT * FROM ad_banners ORDER BY uploaded_at DESC")) {
    $banners = $res->fetch_all(MYSQLI_ASSOC);
}

$zones = [];
if ($res = $db->query("SELECT id, k, label, code, banner_id, size_key FROM ad_zones ORDER BY k ASC")) {
    $zones = $res->fetch_all(MYSQLI_ASSOC);
}

$pendingCrop = $_SESSION['wt_pending_banner'] ?? null;

$pageTitle   = 'Bannières publicitaires';
$adminActive = 'banners';
include __DIR__ . '/../header.php';
?>

<main class="wt-main wt-admin-v2">
  <div class="wt-admin-v2__layout">
    <?php include __DIR__ . '/_nav.php'; ?>
  <section class="wt-admin-v2__content">
  <div class="wt-admin-v2__wrap">

    <header class="wt-admin-v2__page-header">
      <div>
        <span class="wt-eyebrow">🖼️ Bannières</span>
        <h1 class="wt-admin-v2__title">Bannières publicitaires (maison)</h1>
        <p class="wt-muted">
          Upload intelligent : la taille (728×90, 468×60, 300×250, 320×50, 320×100,
          970×250, 160×600, 300×600) est détectée
          automatiquement. Si l'image est plus grande, un recadrage est proposé.
          Formats acceptés : PNG, JPG, WEBP (le GIF n'est pas accepté).
        </p>
      </div>
    </header>

    <?php if ($notice): ?><div class="wt-alert wt-alert--success"><?= e($notice) ?></div><?php endif; ?>
    <?php if ($error):  ?><div class="wt-alert wt-alert--error"><?= e($error) ?></div><?php endif; ?>

    <?php if ($pendingCrop): ?>
      <!-- ============ ÉTAPE 2 : recadrage ============ -->
      <section class="wt-card wt-card--padded" style="margin-bottom:1.5rem">
        <h2 style="margin-top:0">✂️ Recadrage nécessaire</h2>
        <p class="wt-muted" style="font-size:.9rem">
          Votre image fait <?= (int) $pendingCrop['width'] ?>×<?= (int) $pendingCrop['height'] ?>px,
          plus grande que le format cible <strong><?= e($pendingCrop['size_key']) ?></strong>
          (<?= (int) $pendingCrop['target'][0] ?>×<?= (int) $pendingCrop['target'][1] ?>px).
          Déplace et redimensionne le cadre pour choisir la zone à conserver.
        </p>

        <div data-banner-cropper
             data-target-w="<?= (int) $pendingCrop['target'][0] ?>"
             data-target-h="<?= (int) $pendingCrop['target'][1] ?>"
             data-img-w="<?= (int) $pendingCrop['width'] ?>"
             data-img-h="<?= (int) $pendingCrop['height'] ?>">
          <div class="wt-cropper__stage">
            <img src="<?= e(wt_url('/media/wintaskly/img/banners/' . $pendingCrop['file'])) ?>"
                 alt="À recadrer" data-cropper-img>
            <div class="wt-cropper__box" data-cropper-box></div>
          </div>

          <form method="post" style="margin-top:1rem;display:flex;gap:.75rem;flex-wrap:wrap;align-items:center">
            <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="crop_confirm">
            <input type="hidden" name="crop_x" data-crop-x value="0">
            <input type="hidden" name="crop_y" data-crop-y value="0">
            <input type="hidden" name="crop_w" data-crop-w value="0">
            <input type="hidden" name="crop_h" data-crop-h value="0">
            <button class="wt-btn wt-btn--primary" type="submit">✂️ Valider le recadrage</button>
          </form>
          <form method="post" style="display:inline">
            <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="crop_cancel">
            <button class="wt-btn wt-btn--ghost" type="submit">Annuler</button>
          </form>
        </div>
      </section>
    <?php else: ?>
      <!-- ============ ÉTAPE 1 : upload ============ -->
      <section class="wt-card wt-card--padded" style="margin-bottom:1.5rem">
        <h2 style="margin-top:0">📤 Uploader une bannière</h2>
        <form method="post" enctype="multipart/form-data" style="display:flex;gap:.75rem;flex-wrap:wrap;align-items:center">
          <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="action" value="upload">
          <input type="file" name="banner" accept=".png,.jpg,.jpeg,.webp,image/png,image/jpeg,image/webp" required class="wt-input">
          <button class="wt-btn wt-btn--primary" type="submit">Uploader</button>
        </form>
      </section>
    <?php endif; ?>

    <!-- ============ LISTE DES BANNIÈRES ============ -->
    <section class="wt-card wt-card--padded" style="margin-bottom:1.5rem">
      <h2 style="margin-top:0">Bannières enregistrées (<?= count($banners) ?>)</h2>
      <?php if (!$banners): ?>
        <p class="wt-muted">Aucune bannière uploadée pour le moment.</p>
      <?php else: ?>
        <div class="wt-table-wrap">
          <table class="wt-table">
            <thead>
              <tr><th>Aperçu</th><th>Taille</th><th>Fichier</th><th>Ajoutée le</th><th>Statut</th><th>Actions</th></tr>
            </thead>
            <tbody>
              <?php foreach ($banners as $b): ?>
                <tr>
                  <td>
                    <img src="<?= e(wt_url('/media/wintaskly/img/banners/' . $b['filename'])) ?>"
                         alt="" style="max-width:120px;max-height:60px;border-radius:4px">
                  </td>
                  <td><?= e($b['size_key']) ?> <span class="wt-muted">(<?= (int) $b['width'] ?>×<?= (int) $b['height'] ?>)</span></td>
                  <td style="font-size:.8rem"><?= e($b['filename']) ?></td>
                  <td data-utc="<?= e(str_replace(' ', 'T', (string) $b['uploaded_at'])) ?>" data-fmt-time></td>
                  <td>
                    <span class="wt-badge <?= (int) $b['active'] === 1 ? 'wt-badge--success' : 'wt-badge--muted' ?>">
                      <?= (int) $b['active'] === 1 ? 'Active' : 'Désactivée' ?>
                    </span>
                  </td>
                  <td style="display:flex;gap:.4rem">
                    <form method="post">
                      <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                      <input type="hidden" name="action" value="toggle">
                      <input type="hidden" name="id" value="<?= (int) $b['id'] ?>">
                      <button class="wt-btn wt-btn--xs wt-btn--ghost" type="submit">
                        <?= (int) $b['active'] === 1 ? 'Désactiver' : 'Activer' ?>
                      </button>
                    </form>
                    <form method="post" data-confirm-post="Supprimer définitivement cette bannière ?">
                      <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                      <input type="hidden" name="action" value="delete">
                      <input type="hidden" name="id" value="<?= (int) $b['id'] ?>">
                      <button class="wt-btn wt-btn--xs wt-btn--danger" type="submit">Supprimer</button>
                    </form>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </section>

    <!-- ============ ASSOCIATION AUX ZONES ============ -->
    <section class="wt-card wt-card--padded">
      <h2 style="margin-top:0">Association aux emplacements</h2>
      <p class="wt-muted" style="font-size:.9rem">
        Une bannière maison ne s'affiche sur une zone que si <strong>aucune régie</strong>
        (AdSense/Adsterra) n'y est configurée. Sinon, la régie reste toujours prioritaire.
        Si aucune bannière spécifique n'est choisie non plus, le <strong>format de repli</strong>
        déclenche une rotation automatique parmi toutes les bannières actives de ce format
        (alternance ~15-30s côté visiteur, tant qu'il reste sur la page).
      </p>
      <div class="wt-table-wrap">
        <table class="wt-table">
          <thead>
            <tr>
              <th>Zone</th><th>Régie configurée ?</th>
              <th>Bannière spécifique</th><th>Format de repli (rotation auto)</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($zones as $z):
              $stripped = trim(preg_replace('/<!--.*?-->/s', '', (string) $z['code']));
              $hasCode = $stripped !== '';
            ?>
              <tr>
                <td><?= e($z['label']) ?> <span class="wt-muted">(<?= e($z['k']) ?>)</span></td>
                <td><?= $hasCode ? '✅ oui' : '—' ?></td>
                <td>
                  <form method="post" style="display:flex;gap:.5rem;align-items:center">
                    <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="assign_zone">
                    <input type="hidden" name="zone_id" value="<?= (int) $z['id'] ?>">
                    <select name="banner_id" class="wt-input" style="max-width:200px" onchange="this.form.submit()">
                      <option value="">— Aucune —</option>
                      <?php foreach ($banners as $b): if ((int) $b['active'] !== 1) continue; ?>
                        <option value="<?= (int) $b['id'] ?>" <?= (int) $z['banner_id'] === (int) $b['id'] ? 'selected' : '' ?>>
                          #<?= (int) $b['id'] ?> — <?= e($b['size_key']) ?>
                        </option>
                      <?php endforeach; ?>
                    </select>
                    <select name="size_key" class="wt-input" style="max-width:160px" onchange="this.form.submit()">
                      <option value="">— Aucun —</option>
                      <?php foreach (array_keys(WT_STANDARD_SIZES) as $sk): ?>
                        <option value="<?= e($sk) ?>" <?= (string) $z['size_key'] === $sk ? 'selected' : '' ?>>
                          <?= e($sk) ?>
                        </option>
                      <?php endforeach; ?>
                    </select>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </section>

  </div>
  </section>
  </div>
</main>

<script src="<?= e(wt_url('/media/wintaskly/js/wt-banner-crop.js')) ?>?v=<?= e(WT_VERSION) ?>"></script>
<?php include __DIR__ . '/../footer.php'; ?>
