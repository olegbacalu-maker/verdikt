<?php

declare(strict_types=1);

/** @var bool $journalAvailable */
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Verdikt — Auswertung</title>
<link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 16'%3E%3Crect width='16' height='16' rx='3' fill='%230d0d0b'/%3E%3Crect x='5' y='3' width='6' height='10' fill='%231D9E75'/%3E%3C/svg%3E">
<link rel="stylesheet" href="/assets/style.css">
</head>
<body>

<header class="site-header">
  <div class="wrap">
    <div class="brand"><a class="brand-link" href="/"><span class="brand-name">VERDIKT</span></a><span class="cursor" aria-hidden="true">▮</span></div>
    <p class="tagline">Auswertung – Regeln vs. LLM über alle Fixtures</p>
  </div>
</header>

<main class="wrap">
  <section class="panel">
    <?php if ($journalAvailable) { ?>
      <p>Noch kein Auswertungslauf gespeichert.</p>
      <p class="kv">Einen Lauf starten (22 LLM-Aufrufe, ca. $0.04): <code>composer eval</code></p>
    <?php } else { ?>
      <p>Journal nicht verfügbar – das SQLite-Verzeichnis <code>var/</code> ist nicht beschreibbar.</p>
    <?php } ?>
  </section>
</main>

<footer class="site-footer">
  <div class="wrap">
    <nav><a href="/">Demo</a> · <a href="/api/health">/api/health</a></nav>
  </div>
</footer>

</body>
</html>
