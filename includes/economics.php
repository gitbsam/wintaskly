<?php
/**
 * Wintaskly — includes/economics.php
 *
 * Projection économique d'une tâche.
 *
 * Toutes les tâches du site distribuent des coins financés par la
 * publicité. La question est toujours la même : combien d'affichages
 * publicitaires une action déclenche-t-elle, et combien de coins
 * verse-t-on en face ?
 *
 * Ce module répond à cette question une fois pour toutes, avec la même
 * hypothèse de recette pour tout le monde. Le jour où le registre des
 * recettes donnera la valeur réelle, un seul réglage est à corriger et
 * toutes les projections suivent.
 *
 * Base commune du site : 10 000 coins valent 1,00 €.
 */
declare(strict_types=1);

/** Coins par euro. Cette constante gouverne tout le calcul. */
const WT_COINS_PER_EUR = 10000.0;

if (!function_exists('wt_econ_rpv')) {
    /**
     * Recette estimée d'un affichage publicitaire, en euros.
     *
     * Volontairement prudente par défaut : surestimer la recette conduit
     * à annoncer des gains que la publicité ne financera pas, et il est
     * beaucoup plus difficile de baisser une récompense que de
     * l'augmenter.
     */
    function wt_econ_rpv(): float
    {
        $v = (float) cfg('econ.rpv_estimate', 0.005);
        return $v > 0 ? $v : 0.005;
    }
}

if (!function_exists('wt_econ_project')) {
    /**
     * Projette la rentabilité d'un cycle d'une tâche.
     *
     * @param int   $adViews Affichages publicitaires générés par le cycle
     * @param float $payout  Coins versés au joueur sur ce cycle
     * @param float $rpv     Recette par affichage. wt_econ_rpv() si null.
     *
     * @return array{views:int, rpv:float, revenue_eur:float,
     *               revenue_coins:float, payout_coins:float,
     *               payout_eur:float, margin_coins:float,
     *               margin_eur:float, margin_pct:float,
     *               breakeven_coins:float, verdict:string}
     */
    function wt_econ_project(int $adViews, float $payout, ?float $rpv = null): array
    {
        $rpv     = $rpv ?? wt_econ_rpv();
        $adViews = max(0, $adViews);
        $payout  = max(0.0, $payout);

        $revEur   = $adViews * $rpv;
        $revCoins = $revEur * WT_COINS_PER_EUR;
        $marge    = $revCoins - $payout;

        /* Le pourcentage n'a de sens que s'il y a une recette. Sans
           publicité, toute distribution est une perte sèche : on le dit
           plutôt que d'afficher une division par zéro. */
        $pct = $revCoins > 0 ? ($marge / $revCoins * 100) : -100.0;

        /* Trois verdicts plutôt qu'un chiffre brut : un administrateur
           qui règle une récompense veut savoir s'il peut valider, pas
           faire le calcul lui-même.
           Le seuil de 20 % n'est pas arbitraire : en dessous, une
           semaine de mauvaise recette publicitaire fait basculer la
           tâche en perte. */
        if ($revCoins <= 0)      { $verdict = 'none'; }
        elseif ($marge < 0)      { $verdict = 'loss'; }
        elseif ($pct < 20)       { $verdict = 'thin'; }
        else                     { $verdict = 'ok'; }

        return [
            'views'           => $adViews,
            'rpv'             => $rpv,
            'revenue_eur'     => $revEur,
            'revenue_coins'   => $revCoins,
            'payout_coins'    => $payout,
            'payout_eur'      => $payout / WT_COINS_PER_EUR,
            'margin_coins'    => $marge,
            'margin_eur'      => $marge / WT_COINS_PER_EUR,
            'margin_pct'      => $pct,
            /* Le versement qui annulerait exactement la recette. C'est
               la seule valeur qu'un administrateur ne doit jamais
               dépasser. */
            'breakeven_coins' => $revCoins,
            'verdict'         => $verdict,
        ];
    }
}

if (!function_exists('wt_econ_render')) {
    /**
     * Rend le tableau de projection, pour une page d'administration.
     *
     * @param array  $cycles Libellé => [affichages, coins versés]
     * @param string $titre  Intitulé du bloc
     */
    function wt_econ_render(array $cycles, string $titre = ''): string
    {
        $rpv = wt_econ_rpv();
        $h   = '<div class="wt-econ">';
        if ($titre !== '') { $h .= '<h3 class="wt-econ__title">' . e($titre) . '</h3>'; }

        $h .= '<p class="wt-econ__base">'
            . e(t('econ.base', [
                'c'   => number_format(WT_COINS_PER_EUR, 0, ',', ' '),
                'rpv' => number_format($rpv, 4, ',', ' '),
            ]))
            . '</p>';

        $h .= '<table class="wt-econ__table"><thead><tr>'
            . '<th>' . e(t('econ.c_cycle')) . '</th>'
            . '<th>' . e(t('econ.c_views')) . '</th>'
            . '<th>' . e(t('econ.c_revenue')) . '</th>'
            . '<th>' . e(t('econ.c_payout')) . '</th>'
            . '<th>' . e(t('econ.c_margin')) . '</th>'
            . '</tr></thead><tbody>';

        foreach ($cycles as $label => $p) {
            $r = wt_econ_project((int) ($p[0] ?? 0), (float) ($p[1] ?? 0));
            $cls = $r['verdict'] === 'ok' ? 'ok' : ($r['verdict'] === 'thin' ? 'thin' : 'loss');

            $h .= '<tr>'
                . '<td>' . e((string) $label) . '</td>'
                . '<td>' . (int) $r['views'] . '</td>'
                . '<td>' . e(number_format($r['revenue_coins'], 0, ',', ' ')) . ' c<br>'
                . '<small>' . e(number_format($r['revenue_eur'], 4, ',', ' ')) . ' €</small></td>'
                . '<td>' . e(number_format($r['payout_coins'], 0, ',', ' ')) . ' c<br>'
                . '<small>' . e(number_format($r['payout_eur'], 4, ',', ' ')) . ' €</small></td>'
                . '<td class="wt-econ--' . $cls . '">'
                . e(number_format($r['margin_coins'], 0, ',', ' ')) . ' c<br>'
                . '<small>' . e(number_format($r['margin_pct'], 0, ',', ' ')) . ' %</small></td>'
                . '</tr>';

            if ($r['verdict'] === 'loss') {
                $h .= '<tr><td colspan="5" class="wt-econ__warn">'
                    . e(t('econ.w_loss', ['n' => number_format($r['breakeven_coins'], 0, ',', ' ')]))
                    . '</td></tr>';
            } elseif ($r['verdict'] === 'thin') {
                $h .= '<tr><td colspan="5" class="wt-econ__warn">'
                    . e(t('econ.w_thin')) . '</td></tr>';
            }
        }

        $h .= '</tbody></table>';
        $h .= '<p class="wt-econ__note">' . e(t('econ.note')) . '</p>';
        $h .= '</div>';
        return $h;
    }
}
