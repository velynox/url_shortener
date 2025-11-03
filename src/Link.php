<?php

declare(strict_types=1);

namespace App;

/**
 * Link model.
 * Handles creation, lookup, and click tracking for shortened URLs.
 */
class Link
{
    public function __construct(private Database $db) {}

    /**
     * Create a new short link for the given URL.
     * Returns the generated short code.
     *
     * @throws \InvalidArgumentException if the URL is not valid.
     */
    public function create(string $url): string
    {
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            throw new \InvalidArgumentException('Invalid URL provided.');
        }

        // Check if this URL was already shortened — reuse the existing code.
        $existing = $this->db->queryOne(
            'SELECT code FROM links WHERE url = :url LIMIT 1',
            [':url' => $url]
        );

        if ($existing !== null) {
            return $existing['code'];
        }

        $code = $this->generateCode();

        $this->db->execute(
            'INSERT INTO links (code, url) VALUES (:code, :url)',
            [':code' => $code, ':url' => $url]
        );

        return $code;
    }

    /**
     * Resolve a short code to its original URL and increment the click counter.
     * Returns null if the code does not exist.
     */
    public function resolve(string $code): ?string
    {
        $link = $this->db->queryOne(
            'SELECT url FROM links WHERE code = :code LIMIT 1',
            [':code' => $code]
        );

        if ($link === null) {
            return null;
        }

        $this->db->execute(
            'UPDATE links SET clicks = clicks + 1 WHERE code = :code',
            [':code' => $code]
        );

        return $link['url'];
    }

    /**
     * Return all links ordered by creation date descending.
     *
     * @return array<int, array<string, mixed>>
     */
    public function all(): array
    {
        return $this->db->query(
            'SELECT id, code, url, clicks, created_at FROM links ORDER BY created_at DESC'
        );
    }

    /**
     * Generate a unique 6-character alphanumeric short code.
     */
    private function generateCode(): string
    {
        $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        $max   = strlen($chars) - 1;

        do {
            $code = '';
            for ($i = 0; $i < 6; $i++) {
                $code .= $chars[random_int(0, $max)];
            }
            $exists = $this->db->queryOne(
                'SELECT 1 FROM links WHERE code = :code',
                [':code' => $code]
            );
        } while ($exists !== null);

        return $code;
    }
}
