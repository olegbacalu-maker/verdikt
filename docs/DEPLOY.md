# Deploying Verdikt to classic shared PHP hosting (LAMP)

Tested target: All-Inkl-style shared hosting with PHP 8.3, Apache, no root.

## Steps

1. **Upload** the project (FTP/SFTP) to a directory *outside* or *above* the
   web root if possible, e.g. `/www/verdikt/`. If the host has SSH + Composer:
   `composer install --no-dev --optimize-autoloader`. If not: run that locally
   and upload the `vendor/` directory along with the code.
2. **Point the (sub)domain at `public/`** — in the hosting panel set the
   document root of e.g. `verdikt.example.com` to `/www/verdikt/public`.
   `public/.htaccess` handles the routing.
3. **Create `.env`** next to `composer.json` (never inside `public/`):

   ```
   ANTHROPIC_API_KEY=sk-ant-...
   ANTHROPIC_MODEL=claude-haiku-4-5
   APP_DEBUG=false
   ```

4. **Make `var/` writable** by the PHP user (journal + eval runs live there).
   Without it the app still classifies — journal and `/eval` degrade gracefully.
5. **Run the eval once** so `/eval` has data: with SSH, `composer eval`;
   without SSH, run it locally and upload `var/verdikt.sqlite`.
6. **Smoke test**: `/api/health` shows `"engines": ["rules", "llm"]`,
   the demo page classifies, `/eval` renders the stored run.

## Notes

- PHP ≥ 8.3 with `mbstring`, `curl`, `openssl`, `sqlite3`/`pdo_sqlite`.
  `intl` is optional — `symfony/polyfill-intl-normalizer` covers NFC
  normalization where the extension is missing (typical on shared hosting).
- `APP_DEBUG=false` keeps stack traces out of error responses; errors under
  `/api/*` still return clean JSON.
- Set a spend limit for the API key in the Anthropic console — Haiku costs
  ~$0.0016 per classification, but discipline is free.
