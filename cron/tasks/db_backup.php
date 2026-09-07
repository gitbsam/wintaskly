<?php
/**
 * Wintaskly — cron/tasks/db_backup.php
 *
 * Sauvegarde quotidienne de la base, en PHP pur.
 *
 * Pas de mysqldump : sur un hébergement mutualisé, l'exécution de
 * commandes système est souvent désactivée, et le binaire n'est pas
 * toujours présent. On génère donc le fichier nous-mêmes.
 *
 * Le résultat est écrit dans /backups/, compressé, avec une rotation
 * qui ne conserve que les dernières copies. Sans rotation, un espace
 * disque mutualisé se remplit en quelques semaines et c'est le site
 * entier qui tombe — la sauvegarde deviendrait la panne.
 *
 * IMPORTANT : /backups/ doit être inaccessible depuis le web. La règle
 * correspondante est dans .htaccess. Un dump accessible publiquement
 * contient tous vos comptes utilisateurs et leurs empreintes de mot de
 * passe : ce serait pire que pas de sauvegarde du tout.
 */
declare(strict_types=1);

/** Nombre de copies conservées. */
const WT_BACKUP_KEEP = 7;

wt_cron_register('db_backup', static function (): string {
    $dir = dirname(__DIR__, 2) . '/backups';

    if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
        return 'ECHEC : impossible de creer ' . $dir;
    }
    if (!is_writable($dir)) {
        return 'ECHEC : ' . $dir . ' n\'est pas inscriptible';
    }

    /* Ceinture et bretelles : si la règle .htaccess venait à disparaître,
       ce fichier bloque quand même l'accès au dossier. */
    $guard = $dir . '/.htaccess';
    if (!is_file($guard)) {
        @file_put_contents($guard, "Require all denied\nDeny from all\n");
    }

    $db   = db();
    $name = 'wintaskly-' . gmdate('Y-m-d-His') . '.sql.gz';
    $path = $dir . '/' . $name;

    /* Écriture au fil de l'eau plutôt qu'en mémoire : une base de
       plusieurs dizaines de mégaoctets dépasserait la limite mémoire
       de PHP si on assemblait tout avant d'écrire. */
    $fh = gzopen($path, 'wb9');
    if (!$fh) { return 'ECHEC : ouverture de ' . $name; }

    $put = static function (string $s) use ($fh): void { gzwrite($fh, $s); };

    $put("-- Wintaskly — sauvegarde du " . gmdate('Y-m-d H:i:s') . " UTC\n");
    $put("-- Version " . WT_VERSION . "\n");
    $put("SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n");

    $tables = [];
    $res = $db->query('SHOW TABLES');
    while ($r = $res->fetch_row()) { $tables[] = (string) $r[0]; }
    $res->free();

    $rows = 0;
    foreach ($tables as $t) {
        $create = $db->query('SHOW CREATE TABLE `' . $t . '`')->fetch_row()[1] ?? '';
        $put("DROP TABLE IF EXISTS `$t`;\n$create;\n\n");

        /* Lecture non bufferisée : les lignes arrivent une par une au
           lieu d'être toutes chargées côté PHP. Indispensable sur les
           grosses tables. */
        $q = $db->query('SELECT * FROM `' . $t . '`', MYSQLI_USE_RESULT);
        if (!$q) { continue; }
        $batch = [];
        while ($row = $q->fetch_assoc()) {
            $vals = [];
            foreach ($row as $v) {
                $vals[] = $v === null ? 'NULL' : "'" . $db->real_escape_string((string) $v) . "'";
            }
            $batch[] = '(' . implode(',', $vals) . ')';
            $rows++;
            /* Par paquets de 200 : un INSERT géant serait rejeté à
               l'import par max_allowed_packet. */
            if (count($batch) >= 200) {
                $put("INSERT INTO `$t` VALUES\n" . implode(",\n", $batch) . ";\n");
                $batch = [];
            }
        }
        $q->free();
        if ($batch) {
            $put("INSERT INTO `$t` VALUES\n" . implode(",\n", $batch) . ";\n");
        }
        $put("\n");
    }

    $put("SET FOREIGN_KEY_CHECKS=1;\n");
    gzclose($fh);

    $size = (int) @filesize($path);
    if ($size < 1024) {
        @unlink($path);
        return 'ECHEC : fichier vide, sauvegarde supprimee';
    }

    /* Rotation. On ne garde que les plus récentes. */
    $old = glob($dir . '/wintaskly-*.sql.gz') ?: [];
    rsort($old);
    $removed = 0;
    foreach (array_slice($old, WT_BACKUP_KEEP) as $f) {
        if (@unlink($f)) { $removed++; }
    }

    return sprintf(
        '%s — %d table(s), %d ligne(s), %s Ko%s',
        $name, count($tables), $rows, number_format($size / 1024, 0, ',', ' '),
        $removed ? sprintf(', %d ancienne(s) supprimee(s)', $removed) : ''
    );
}, 86400);   // une fois par jour
