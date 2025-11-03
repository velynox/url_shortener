# Project Setup — URL Shortener

## Decision: Project Choice

Went with a PHP URL Shortener. Reasons:
- Fits the PHP-first stack perfectly.
- Has a real UI (form + redirect logic) so design enforcement is meaningful.
- SQLite as the database — no external dependency, self-contained, easy to ship.
- No framework. Raw PHP with clean OOP. A `Database` class, a `Link` model, and a router.

## Architecture

```
url_shortener/
├── index.php          # Entry point + router
├── src/
│   ├── Database.php   # PDO wrapper (SQLite)
│   ├── Link.php       # Link model (create, find, resolve)
│   └── Router.php     # Minimal request router
├── public/
│   └── style.css      # Tailwind output (CDN for now, no build step)
├── db/
│   └── .gitkeep       # SQLite file lives here (gitignored)
├── thoughts/          # This directory
└── .gitignore
```

## Design Decisions

- SQLite over MySQL: zero-config, single file, perfect for a self-contained tool.
- No Composer for now: keeping it dependency-free. If it grows, add it then.
- TailwindCSS via CDN + Catppuccin Mocha custom config injected inline.
- All UI icons from Lucide (inline SVG, stroke-width 1.5).
- Font: JetBrains Mono via Google Fonts CDN.
- Short codes: 6-character alphanumeric, generated with random_bytes + base_convert.

## What it does

1. User pastes a long URL into the form.
2. App generates a 6-char short code, stores it in SQLite.
3. User gets a short link: http://localhost/s/{code}
4. Visiting that link redirects to the original URL (301).
5. Click count is tracked per link.
6. A simple list of all created links is shown on the homepage.
