# API Support + Domain Change

## Domain Decision

Chose `link.velynox.de` over `links.velynox.de` and `url.velynox.de`.
- Singular is cleaner.
- Shorter than `links`.
- `url` feels too generic — `link` is more intentional.

The base URL is now hardcoded as `https://link.velynox.de` rather than derived
from `$_SERVER['HTTP_HOST']`. This avoids host-header injection and makes the
displayed short links always correct regardless of how the server is accessed
locally during development.

## API Design

REST JSON API, no auth for now (can add a bearer token later if needed).

### Endpoints

| Method | Path          | Description                        |
|--------|---------------|------------------------------------|
| POST   | /api/shorten  | Create a short link                |
| GET    | /api/links    | List all links                     |
| GET    | /api/links/{code} | Get a single link by code      |

### POST /api/shorten

Request body (JSON):
```json
{ "url": "https://example.com/very/long/url" }
```

Response 201:
```json
{
  "code": "aBcDeFg",
  "short": "https://link.velynox.de/aBcDeFg",
  "url": "https://example.com/very/long/url",
  "clicks": 0,
  "created_at": "2026-03-26 12:00:00"
}
```

### Code Length Difference

- Web UI: 6-character codes (existing behavior, unchanged)
- API:    7-character codes (one character longer, as specified)

This is a deliberate namespace separation. API-created links are visually
distinguishable from UI-created ones by their length.

## Router Change

Added JSON response helper and content-type enforcement for /api/* routes.
The router itself is unchanged — just new routes registered in index.php.
