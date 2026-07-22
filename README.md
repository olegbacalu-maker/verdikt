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
- [ ] Day 2 — rules engine port + synthetic fixtures + tests
- [ ] Day 3 — Anthropic client (structured output, retries), `engine=llm|both`
- [ ] Day 4 — web UI
- [ ] Day 5 — `/eval` + SQLite journal
- [ ] Day 6 — deploy
