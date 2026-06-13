<?php
/**
 * /tasks/faucet/_stepper.php — Composant stepper 3 étapes
 *
 * Inclure dans une page faucet en posant $stepperHere à 1, 2 ou 3 avant
 * l'include. Le composant affiche les 3 étapes avec l'étape courante
 * mise en valeur et les précédentes marquées comme terminées.
 *
 * Usage :
 *   $stepperHere = 2;
 *   include __DIR__ . '/_stepper.php';
 */
declare(strict_types=1);

if (!isset($stepperHere)) $stepperHere = 1;

$steps = [
    1 => ['icon' => '💧', 'label' => t('faucet.step_start')],
    2 => ['icon' => '⏱️', 'label' => t('faucet.step_transition')],
    3 => ['icon' => '🎯', 'label' => t('faucet.step_verify')],
];
?>
<ol class="wt-stepper" aria-label="Progression">
  <?php foreach ($steps as $n => $s):
      $state = $n < $stepperHere ? 'done'
             : ($n === $stepperHere ? 'current' : 'todo');
  ?>
    <li class="wt-stepper__item wt-stepper__item--<?= $state ?>"
        aria-current="<?= $state === 'current' ? 'step' : 'false' ?>">
      <span class="wt-stepper__bullet" aria-hidden="true">
        <?php if ($state === 'done'): ?>
          <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round">
            <polyline points="20 6 9 17 4 12"/>
          </svg>
        <?php else: ?>
          <span class="wt-stepper__bullet-icon"><?= e($s['icon']) ?></span>
        <?php endif; ?>
      </span>
      <div class="wt-stepper__meta">
        <small><?= sprintf((string) t('faucet.step_n'), $n) ?></small>
        <strong><?= e($s['label']) ?></strong>
      </div>
    </li>
  <?php endforeach; ?>
</ol>
