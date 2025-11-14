# Structure Refactor

## Problems Fixed

### 1. public/ was empty
`index.php` was sitting at the project root, which means the entire project
directory would be the web root — exposing `src/`, `db/`, `config.php`, etc.
Moved `index.php` into `public/` where it belongs. The web server document
root should point to `public/` only.

### 2. Hardcoded domain
Reverted the hardcoded `https://link.velynox.de`. Base URL is now derived
dynamically from `$_SERVER['HTTP_HOST']` and the HTTPS flag. To override
(e.g. for production), set `base_url` in `config.local.php`.

### 3. config.php added
A simple array-returning config file at the project root. Values:
- `base_url`: null (dynamic) or a string to force a fixed domain
- `db_path`: path to the SQLite file
- `code_length`: 6 (web UI)
- `api_code_length`: 7 (API)

Local overrides go in `config.local.php` (gitignored).

### 4. Short URL path simplified
Was: `/{base}/s/{code}` — the `/s/` prefix was a leftover from when the
app lived at a subdirectory. Now that the domain is the shortener, links
are just `/{code}`. Cleaner.

### 5. links.sqlite untracked
The database file was accidentally committed. Removed from git tracking.
`.gitignore` now correctly excludes `db/*.sqlite`.

## Final Structure

```
url_shortener/
├── public/
│   └── index.php        ← web root (point your server here)
├── src/
│   ├── Database.php
│   ├── Link.php
│   └── Router.php
├── config.php           ← default config
├── config.local.php     ← local overrides (gitignored)
├── db/
│   └── .gitkeep
├── thoughts/
└── .gitignore
```
