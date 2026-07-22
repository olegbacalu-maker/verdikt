# Verdikt

Classifies German customer replies about delivery appointments — the daily reality of a
dispatch desk: hundreds of emails saying "the date works", "the date does not work",
"I'm on vacation", or asking a question. Verdikt reads a reply and returns a verdict:

`TERMIN_OK` · `TERMIN_PASST_NICHT` · `FRAGE` · `ABWESEND` · `PRUEFEN`

The point is not the classifier itself — it is the **comparison of two engines**:

| Engine | How | Cost | Latency |
|---|---|---|---|
| **rules** | Cascade of regex rules, ported from a production PowerShell tool | free | ~0 ms |
| **llm** | Anthropic API (Claude Haiku), structured output | ~cents | ~1 s |

The `/eval` page runs both engines over a fixture set of synthetic German replies and
shows where they agree and where they diverge — measuring LLM quality instead of
assuming it.

> **Origin.** The rules cascade is a PHP port of a tool I built and use daily to sort
> real customer replies for a DHL service partner's dispatch desk. All fixtures here
> are **synthetic** (typical phrasings, no names / addresses / tracking numbers).

## API

```
GET  /api/health                              → {"status":"ok", ...}
POST /api/verdict {text, engine: rules|llm|both} → verdict + explanation
GET  /eval                                    → rules vs LLM on all fixtures
```

Example:

```bash
curl -s -X POST http://localhost:8080/api/verdict \
  -H "Content-Type: application/json" \
  -d '{"text": "Der Termin passt mir leider nicht.", "engine": "rules"}'
```

```json
{
  "cleaned_text": "Der Termin passt mir leider nicht.",
  "results": [
    {
      "engine": "rules",
      "verdict": "TERMIN_PASST_NICHT",
      "rule": "nein_stark",
      "matched": "passt mir leider nicht",
      "explanation": "rule 'nein_stark' matched \"passt mir leider nicht\"",
      "duration_ms": 0.761
    }
  ]
}
```

`engine=both` is **all-or-nothing by design**: it exists for the engine
comparison, and a partial comparison is not a comparison — if one engine is
unavailable the response is `501`, not a silently degraded rules-only `200`.

Pasted email threads are fine: a `ReplyCleaner` (also ported from the production
tool) strips quoted history, header blocks and `>` lines before classifying —
otherwise a "bestätigt" inside the *quoted* mail would flip the verdict. If
cleaning leaves nothing (the paste was pure quoted history), the verdict
fails safe to `PRUEFEN` — same direction as the original.

### The LLM engine

`AnthropicClient` is a deliberately hand-rolled Guzzle client (the official
PHP SDK exists — but auth headers, retry policy and error taxonomy are exactly
the parts this showcase should not hide):

- **Structured output** via a forced tool call: `tool_choice` pins the model to
  the `verdict` tool, `strict: true` guarantees schema-valid input — the
  verdict arrives as a clean enum plus a one-sentence German justification,
  no free-text parsing.
- **Retries**: 429/5xx/529 and transport errors get exponential backoff
  honoring `Retry-After` (capped, so a web request stays bounded); other 4xx
  fail fast. Upstream failure maps to an honest `502`, never a half-empty
  comparison.
- **Cost**: Claude Haiku 4.5 at $1/$5 per MTok ≈ **$0.0016 per classification**
  (~1.2k input + ~90 output tokens). Token usage is reported in `meta`.
- All engine tests run against a Guzzle `MockHandler` — no network, no key,
  no cost in CI.

### How the rules engine is verified

The cascade is a 1:1 port, and that claim is **tested, not asserted**:

- A differential harness runs the original PowerShell `Get-Verdikt` and this
  PHP port over the full corpus (fixtures + unit cases + adversarial extras,
  54 texts) — **0 mismatches**.
- Known disagreements between the rules engine and human ground truth are
  pinned explicitly in `tests/FixturesTest.php` (`KNOWN_RULES_DIVERGENCES`),
  so the legacy engine's error budget is documented instead of hidden.
- Unicode parity with .NET is handled, not assumed: input is NFC-normalized
  (decomposed umlauts from macOS Mail pastes otherwise break the negation
  guards on PCRE2 < 10.43 and flip refusals into agreements), Turkish `İ`
  case-folding is aligned with .NET `ToLower`, and invalid UTF-8 is rejected
  with a 400 instead of being silently misclassified. Each case is pinned by
  a test.
- Two intentional `ReplyCleaner` divergences from production are documented
  in its docblock (client-specific signature anchor, tool-specific `(?)` date
  placeholder) — relevant only if real production pastes were replayed here.
- Static analysis: PHPStan level 8, clean (`composer stan`).

## Stack

PHP 8.3 · Slim 4 · Guzzle · vlucas/phpdotenv · PHPUnit · SQLite (request journal) ·
vanilla JS front-end.

## Run locally

```bash
composer install
cp .env.example .env        # add ANTHROPIC_API_KEY for the llm engine
composer serve              # http://localhost:8080
composer test
```

The rules engine and `/api/health` work without any API key.

## Status

- [x] Day 1 — skeleton: Slim 4, `/api/health`, PHPUnit wired
- [x] Day 2 — rules engine port (differentially tested vs the original) + 22 synthetic fixtures + tests
- [x] Day 3 — Anthropic client (Guzzle, forced tool call + strict schema, retries), `engine=llm|both` live
- [ ] Day 4 — web UI
- [ ] Day 5 — `/eval` + SQLite journal
- [ ] Day 6 — deploy
