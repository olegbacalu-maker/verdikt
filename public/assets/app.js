/* Verdikt UI — vanilla JS, no build step. */
'use strict';

const $ = (sel) => document.querySelector(sel);

const VERDICTS = {
  TERMIN_OK:          { label: 'TERMIN OK',          cls: 'ok' },
  TERMIN_PASST_NICHT: { label: 'TERMIN PASST NICHT', cls: 'nein' },
  FRAGE:              { label: 'FRAGE',              cls: 'frage' },
  ABWESEND:           { label: 'ABWESEND',           cls: 'abwesend' },
  PRUEFEN:            { label: 'PRÜFEN',             cls: 'pruefen' },
};

const EXAMPLES = [
  { label: 'Zusage',        text: 'Ja, der Termin passt uns gut. Vielen Dank!' },
  { label: 'Absage',        text: 'Der Termin passt mir leider nicht, bitte um einen neuen Termin.' },
  { label: 'Frage',         text: 'Wann genau kommen Sie am Dienstag?' },
  { label: 'Verstecktes Ja', text: 'Falls wir nicht da sind stellen Sie es bitte in die Garage' },
  { label: 'Mail mit Zitat', text: 'Passt leider nicht.\nVon: Zustellservice <service@example.com>\nGesendet: Montag\n> Ihr Liefertermin ist bestätigt' },
];

/* the API contract stays English; known notes get a German face client-side */
const NOTES_DE = {
  quoted_history_only: 'Die Antwort bestand nur aus zitiertem Verlauf – das Ergebnis fällt zur Kontrolle auf PRÜFEN zurück.',
};

function el(tag, className, text) {
  const node = document.createElement(tag);
  if (className) node.className = className;
  if (text !== undefined) node.textContent = text;
  return node;
}

/* ---- health: enable llm/both only when the server has the engine ---- */

async function initHealth() {
  const health = $('#health');
  try {
    const res = await fetch('/api/health');
    if (!res.ok) throw new Error('HTTP ' + res.status);
    const data = await res.json();

    const hasLlm = data.engines.includes('llm');
    if (hasLlm) {
      for (const input of document.querySelectorAll('#engines input')) input.disabled = false;
      $('#engines input[value="both"]').checked = true;
    }

    health.replaceChildren(
      el('span', 'dot dot-ok'),
      document.createTextNode('online · engines: ' + data.engines.join(', ') + ' · php ' + data.php),
    );
  } catch (e) {
    health.replaceChildren(
      el('span', 'dot dot-err'),
      document.createTextNode('API nicht erreichbar (' + e.message + ')'),
    );
  }
}

/* ---- examples ---- */

function initExamples() {
  const box = $('#examples');
  for (const example of EXAMPLES) {
    const btn = el('button', null, example.label);
    btn.type = 'button';
    btn.addEventListener('click', () => {
      $('#text').value = example.text;
      $('#text').focus();
    });
    box.append(btn);
  }
}

/* ---- classify ---- */

function verdictBadge(verdict) {
  const meta = VERDICTS[verdict] || { label: verdict, cls: 'pruefen' };
  return el('span', 'badge ' + meta.cls, meta.label);
}

function resultCard(result) {
  const card = el('article', 'card');

  const head = el('div', 'card-head');
  head.append(el('span', 'engine-name', result.engine === 'rules' ? 'ENGINE: REGELN' : 'ENGINE: LLM'));
  head.append(verdictBadge(result.verdict));
  card.append(head);

  const body = el('div', 'card-body');

  if (result.engine === 'rules') {
    const p = el('p', 'kv');
    p.append('Regel: ');
    p.append(el('b', null, result.rule ?? '—'));
    if (result.matched) {
      p.append('   Treffer: ');
      p.append(el('span', 'matched', '„' + result.matched + '“'));
    }
    body.append(p);
  } else {
    body.append(el('p', null, result.explanation));
  }

  const meta = el('p', 'kv');
  const bits = [result.duration_ms + ' ms'];
  if (result.meta) {
    if (result.meta.model) bits.push(result.meta.model);
    if (result.meta.input_tokens != null) {
      bits.push(result.meta.input_tokens + '→' + result.meta.output_tokens + ' tokens');
    }
  }
  meta.textContent = bits.join(' · ');
  body.append(meta);

  card.append(body);
  return card;
}

function render(data, originalText) {
  const out = $('#output');
  out.replaceChildren();

  if (data.results.length === 2) {
    const [a, b] = data.results;
    const agree = a.verdict === b.verdict;
    out.append(el(
      'div',
      'agreement ' + (agree ? 'agree' : 'disagree'),
      agree ? '✓ Engines einig: ' + (VERDICTS[a.verdict]?.label ?? a.verdict)
            : '✗ Engines uneinig – genau dafür gibt es diese Seite',
    ));
  }

  if (data.note) {
    out.append(el('div', 'note', 'ℹ ' + (NOTES_DE[data.note_code] ?? data.note)));
  }

  for (const result of data.results) out.append(resultCard(result));

  if (data.cleaned_text !== undefined && data.cleaned_text !== originalText.trim()) {
    const cleaned = el('div', 'card');
    cleaned.append(el('span', 'engine-name', 'BEREINIGTER TEXT'));
    cleaned.append(el('div', 'cleaned', data.cleaned_text === '' ? '(leer – nur zitierter Verlauf)' : data.cleaned_text));
    out.append(cleaned);
  }
}

async function classify() {
  const text = $('#text').value;
  const engine = document.querySelector('#engines input:checked').value;
  const out = $('#output');
  const button = $('#go');

  if (button.disabled) return; // request in flight (guards the Ctrl+Enter path too)

  if (text.trim() === '') {
    out.replaceChildren(el('div', 'error', 'Bitte zuerst eine Kundenantwort eingeben.'));
    return;
  }

  button.disabled = true;
  button.textContent = 'Wird klassifiziert…';

  try {
    const res = await fetch('/api/verdict', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ text, engine }),
    });

    let data;
    try {
      data = await res.json();
    } catch {
      throw new Error('HTTP ' + res.status + ' — keine JSON-Antwort');
    }

    if (!res.ok) {
      throw new Error('HTTP ' + res.status + ' — ' + (data.error ?? 'unbekannter Fehler'));
    }

    render(data, text);
  } catch (e) {
    out.replaceChildren(el('div', 'error', '✗ ' + e.message));
  } finally {
    button.disabled = false;
    button.textContent = 'Klassifizieren';
  }
}

/* ---- shareable links: /?beispiel=4&engine=both&auto=1 ---- */

function initFromUrl() {
  const params = new URLSearchParams(location.search);

  const beispiel = parseInt(params.get('beispiel') ?? '', 10);
  if (beispiel >= 1 && beispiel <= EXAMPLES.length) {
    $('#text').value = EXAMPLES[beispiel - 1].text;
  }

  const engine = params.get('engine');
  if (['rules', 'llm', 'both'].includes(engine)) {
    const input = document.querySelector('#engines input[value="' + engine + '"]');
    if (input && !input.disabled) input.checked = true;
  }

  if (params.get('auto') === '1' && $('#text').value.trim() !== '') classify();
}

/* ---- boot ---- */

$('#go').addEventListener('click', classify);
$('#text').addEventListener('keydown', (event) => {
  if ((event.ctrlKey || event.metaKey) && event.key === 'Enter') classify();
});

initExamples();
initHealth().then(initFromUrl); // llm/both radios unlock only after health
