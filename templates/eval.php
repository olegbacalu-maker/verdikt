<?php

declare(strict_types=1);

/**
 * @var array<string, mixed>       $run
 * @var list<array<string, mixed>> $results
 * @var float                      $costUsd
 * @var float                      $avgLlmMs
 * @var int                        $requestCount
 */

$e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

$badgeClass = static fn (string $verdict): string => match ($verdict) {
    'TERMIN_OK'          => 'ok',
    'TERMIN_PASST_NICHT' => 'nein',
    'FRAGE'              => 'frage',
    'ABWESEND'           => 'abwesend',
    default              => 'pruefen',
};

$label = static fn (string $verdict): string => str_replace('_', ' ', str_replace('PRUEFEN', 'PRÜFEN', $verdict));

$total = (int) $run['fixtures_total'];
$pct = static fn (int $n): string => $total > 0 ? sprintf('%d %%', (int) round($n / $total * 100)) : '–';
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Verdikt — Auswertung: Regeln vs. LLM</title>
<link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 16'%3E%3Crect width='16' height='16' rx='3' fill='%230d0d0b'/%3E%3Crect x='5' y='3' width='6' height='10' fill='%231D9E75'/%3E%3C/svg%3E">
<link rel="stylesheet" href="/assets/style.css">
</head>
<body>

<header class="site-header">
  <div class="wrap">
    <div class="brand"><a class="brand-link" href="/"><span class="brand-name">VERDIKT</span></a><span class="cursor" aria-hidden="true">▮</span></div>
    <p class="tagline">Auswertung – beide Engines über alle <?= $total ?> Fixtures, gegen menschliche Ground Truth</p>
    <p class="health">Lauf vom <?= $e((string) $run['created_at']) ?> · Modell: <?= $e((string) $run['model']) ?> · <?= $e((string) $requestCount) ?> Anfragen im Journal</p>
  </div>
</header>

<main class="wrap">
  <section class="stats" aria-label="Zusammenfassung">
    <div class="stat">
      <span class="stat-value hl-green"><?= (int) $run['rules_correct'] ?>/<?= $total ?></span>
      <span class="stat-label">Regeln richtig (<?= $pct((int) $run['rules_correct']) ?>)</span>
    </div>
    <div class="stat">
      <span class="stat-value hl-purple"><?= (int) $run['llm_correct'] ?>/<?= $total ?></span>
      <span class="stat-label">LLM richtig (<?= $pct((int) $run['llm_correct']) ?>)</span>
    </div>
    <div class="stat">
      <span class="stat-value"><?= (int) $run['agree_count'] ?>/<?= $total ?></span>
      <span class="stat-label">Engines einig (<?= $pct((int) $run['agree_count']) ?>)</span>
    </div>
    <div class="stat">
      <span class="stat-value"><?= number_format($avgLlmMs / 1000, 1, ',', '') ?> s</span>
      <span class="stat-label">Ø LLM-Latenz</span>
    </div>
    <div class="stat">
      <span class="stat-value">$<?= number_format($costUsd, 4) ?></span>
      <span class="stat-label"><?= number_format((int) $run['input_tokens']) ?>→<?= number_format((int) $run['output_tokens']) ?> Tokens</span>
    </div>
  </section>

  <div class="table-scroll">
    <table class="eval">
      <thead>
        <tr>
          <th>Fixture</th>
          <th>Erwartet</th>
          <th>Regeln</th>
          <th>LLM</th>
          <th>LLM-Begründung</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($results as $r) { ?>
          <?php
            $rulesOk = $r['rules_verdict'] === $r['expected'];
            $llmOk = $r['llm_verdict'] === $r['expected'];
            $disagree = $r['rules_verdict'] !== $r['llm_verdict'];
          ?>
          <tr class="<?= $disagree ? 'row-disagree' : '' ?>">
            <td class="cell-fixture">
              <span class="fixture-id"><?= $e((string) $r['fixture_id']) ?></span>
              <span class="fixture-text"><?= $e((string) $r['text']) ?></span>
            </td>
            <td><span class="badge <?= $badgeClass((string) $r['expected']) ?>"><?= $e($label((string) $r['expected'])) ?></span></td>
            <td>
              <span class="mark"><?= $rulesOk ? '✓' : '✗' ?></span>
              <span class="badge <?= $badgeClass((string) $r['rules_verdict']) ?>"><?= $e($label((string) $r['rules_verdict'])) ?></span>
              <span class="rule-name"><?= $e((string) ($r['rules_rule'] ?? '')) ?></span>
            </td>
            <td>
              <span class="mark"><?= $llmOk ? '✓' : '✗' ?></span>
              <span class="badge <?= $badgeClass((string) $r['llm_verdict']) ?>"><?= $e($label((string) $r['llm_verdict'])) ?></span>
            </td>
            <td class="cell-begruendung"><?= $e((string) $r['llm_explanation']) ?></td>
          </tr>
        <?php } ?>
      </tbody>
    </table>
  </div>

  <p class="eval-footnote">
    ✓/✗ = Übereinstimmung mit der Ground Truth. Hervorgehobene Zeilen: Engines uneinig.
    Neu bewerten: <code>composer eval</code>.
  </p>
</main>

<footer class="site-footer">
  <div class="wrap">
    <span>Kosten geschätzt zu $1/$5 je Mio. Tokens (Claude Haiku 4.5)</span>
    <nav><a href="/">Demo</a> · <a href="/api/health">/api/health</a></nav>
  </div>
</footer>

</body>
</html>
