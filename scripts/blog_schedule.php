<?php
/**
 * Wintaskly — scripts/blog_schedule.php
 *
 * Calcule les dates de publication du blog : un article par jour
 * ouvré, hors week-ends et jours fériés.
 *
 * Le calendrier démarre au lendemain du dernier article en base,
 * qu'il soit publié ou en attente — programmer une date déjà passée
 * ferait apparaître dix articles d'un coup.
 *
 * Usage : php scripts/blog_schedule.php [nombre]
 */
declare(strict_types=1);

/**
 * Jours fériés applicables à La Réunion : les onze fériés nationaux
 * plus le 20 décembre, abolition de l'esclavage — férié local, souvent
 * oublié des bibliothèques métropolitaines.
 *
 * Pâques est calculée, pas codée en dur : easter_date() suit la règle
 * grégorienne, et les fêtes mobiles s'en déduisent.
 */
function wt_holidays(int $year): array
{
    $easter = new DateTimeImmutable('@' . easter_date($year));
    $easter = $easter->setTimezone(new DateTimeZone('UTC'));

    $days = [
        $year . '-01-01',                                  // Jour de l'An
        $easter->modify('+1 day')->format('Y-m-d'),        // Lundi de Pâques
        $year . '-05-01',                                  // Fête du Travail
        $year . '-05-08',                                  // Victoire 1945
        $easter->modify('+39 days')->format('Y-m-d'),      // Ascension
        $easter->modify('+50 days')->format('Y-m-d'),      // Lundi de Pentecôte
        $year . '-07-14',                                  // Fête nationale
        $year . '-08-15',                                  // Assomption
        $year . '-11-01',                                  // Toussaint
        $year . '-11-11',                                  // Armistice
        $year . '-12-20',                                  // Abolition (Réunion)
        $year . '-12-25',                                  // Noël
    ];
    return array_flip($days);
}

/** Prochain jour ouvré non férié, à partir d'une date incluse. */
function wt_next_workday(DateTimeImmutable $d, array &$cache): DateTimeImmutable
{
    while (true) {
        $y = (int) $d->format('Y');
        if (!isset($cache[$y])) { $cache[$y] = wt_holidays($y); }
        $isWeekend = in_array((int) $d->format('N'), [6, 7], true);
        $isHoliday = isset($cache[$y][$d->format('Y-m-d')]);
        if (!$isWeekend && !$isHoliday) { return $d; }
        $d = $d->modify('+1 day');
    }
}

$count = (int) ($argv[1] ?? 100);
$count = max(1, min(500, $count));

/* Point de départ : le lendemain du dernier article connu.
 *
 * ATTENTION en générant plusieurs lots. Le calendrier repart après le
 * dernier article EN BASE. Si un lot précédent y est déjà importé, la
 * série suivante commence après lui — et indexer cette série comme si
 * elle partait du début décale toutes les dates.
 *
 * Pour préparer plusieurs lots d'affilée, générez-les depuis une base
 * qui reflète la production, avant tout import de test. L'option
 * --no-db permet aussi de partir de demain sans consulter la base. */
$start = new DateTimeImmutable('tomorrow', new DateTimeZone('UTC'));
if (file_exists(__DIR__ . '/../includes/init.php') && !in_array('--no-db', $argv, true)) {
    $_SERVER['REQUEST_URI'] = '/'; $_SERVER['HTTP_HOST'] = 'localhost';
    $_SERVER['REQUEST_METHOD'] = 'GET';
    require __DIR__ . '/../includes/init.php';
    $row = db_one("SELECT MAX(published_at) m FROM blog_posts");
    if ($row && $row['m']) {
        $last = new DateTimeImmutable((string) $row['m'], new DateTimeZone('UTC'));
        $cand = $last->modify('+1 day');
        if ($cand > $start) { $start = $cand; }
    }
}

$cache = [];
$d     = wt_next_workday($start->setTime(7, 0), $cache);
$out   = [];
for ($i = 0; $i < $count; $i++) {
    $out[] = $d->format('Y-m-d H:i:s');
    $d = wt_next_workday($d->modify('+1 day'), $cache);
}

if (in_array('--list', $argv, true)) {
    foreach ($out as $i => $ts) { printf("%3d  %s  %s\n", $i + 1, $ts, (new DateTimeImmutable($ts))->format('D')); }
} else {
    printf("Depart      : %s\n", $out[0]);
    printf("Fin         : %s\n", $out[count($out) - 1]);
    printf("Articles    : %d\n", count($out));
    $j = (new DateTimeImmutable($out[0]))->diff(new DateTimeImmutable($out[count($out)-1]))->days;
    printf("Duree       : %d jours calendaires\n", $j);
    echo "\nJours ecartes sur la periode :\n";
    $y1 = (int) substr($out[0],0,4); $y2 = (int) substr($out[count($out)-1],0,4);
    for ($y = $y1; $y <= $y2; $y++) {
        foreach (array_keys(wt_holidays($y)) as $h) {
            if ($h >= substr($out[0],0,10) && $h <= substr($out[count($out)-1],0,10)) {
                $wd = (new DateTimeImmutable($h))->format('D');
                if (!in_array($wd, ['Sat','Sun'], true)) { echo "  $h ($wd)\n"; }
            }
        }
    }
}
