# url_shortener

A self-hosted URL shortener built with raw PHP and SQLite. No framework, no Composer, no build step.

## Stack

- PHP 8.1+
- SQLite (via PDO)
- TailwindCSS (CDN)
- JetBrains Mono

## Project Structure

```
url_shortener/
├── public/
│   └── index.php        # Entry point — point your web server here
├── src/
│   ├── Database.php     # PDO/SQLite wrapper
│   ├── Link.php         # Link model (create, resolve, find, delete)
│   └── Router.php       # Minimal request router
├── db/
│   └── .gitkeep         # SQLite file is created here at runtime (gitignored)
├── thoughts/            # Architecture decision logs
├── config.php           # Default configuration
└── config.local.php     # Local overrides (gitignored, create manually)
```

## Setup

**Requirements:** PHP 8.1+, the `pdo_sqlite` extension.

```bash
# Clone and enter the project
git clone https://github.com/velynox/url_shortener
cd url_shortener

# Optional: create a local config override
cp config.php config.local.php
# Edit config.local.php as needed

# Run the dev server (document root is public/)
php -S localhost:8080 -t public/
```

Open `http://localhost:8080` in your browser.

The SQLite database is created automatically at `db/links.sqlite` on first run.

## Configuration

All config lives in `config.php`. Override any value in `config.local.php` (gitignored).

| Key               | Default                    | Description                                      |
|-------------------|----------------------------|--------------------------------------------------|
| `base_url`        | `null` (dynamic)           | Force a fixed domain, e.g. `https://link.velynox.de` |
| `db_path`         | `__DIR__ . /db/links.sqlite` | Path to the SQLite database file               |
| `code_length`     | `6`                        | Short code length for web UI links               |
| `api_code_length` | `7`                        | Short code length for API-created links          |
| `github_url`      | `https://github.com/velynox` | GitHub link shown in the footer               |

## API

Base URL: `http://localhost:8080` (or your configured domain)

All responses are `application/json`. No authentication required.

### POST /api/shorten

Create a short link. API codes are 7 characters (one longer than UI codes).

```bash
curl -X POST http://localhost:8080/api/shorten \
  -H 'Content-Type: application/json' \
  -d '{"url": "https://example.com/very/long/path"}'
```

```json
{
  "code": "aBcDeFg",
  "short": "http://localhost:8080/aBcDeFg",
  "url": "https://example.com/very/long/path",
  "clicks": 0,
  "created_at": "2026-03-26 12:00:00"
}
```

### GET /api/links

List all links.

```bash
curl http://localhost:8080/api/links
```

### GET /api/links/{code}

Get a single link by code. Returns `404` if not found.

```bash
curl http://localhost:8080/api/links/aBcDeFg
```

### DELETE /api/links/{code}

Delete a link. Returns `204 No Content` on success, `404` if not found.

```bash
curl -X DELETE http://localhost:8080/api/links/aBcDeFg
```

### Error format

```json
{ "error": "url is required." }
```

## Web server (production)

Point your document root to the `public/` directory. Example nginx config:

```nginx
server {
    listen 443 ssl;
    server_name link.velynox.de;

    root /var/www/url_shortener/public;
    index index.php;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }
}
```

Set `base_url` in `config.local.php` to your production domain.

## License

MIT
