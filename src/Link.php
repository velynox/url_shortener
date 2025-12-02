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
     * @param int $length Code length. Web UI uses 6, API uses 7.
     * @throws \InvalidArgumentException if the URL is not valid.
     */
    public function create(string $url, int $length = 6): string
    {
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            throw new \InvalidArgumentException('Invalid URL provided.');
        }

        // Check if this URL was already shortened at the same length — reuse it.
        $existing = $this->db->queryOne(
            'SELECT code FROM links WHERE url = :url AND LENGTH(code) = :len LIMIT 1',
            [':url' => $url, ':len' => $length]
        );

        if ($existing !== null) {
            return $existing['code'];
        }

        $code = $this->generateCode($length);

        $this->db->execute(
            'INSERT INTO links (code, url) VALUES (:code, :url)',
            [':code' => $code, ':url' => $url]
        );

        return $code;
    }

    /**
     * Find a single link row by code. Returns null if not found.
     *
     * @return array<string, mixed>|null
     */
    public function find(string $code): ?array
    {
        return $this->db->queryOne(
            'SELECT id, code, url, clicks, created_at FROM links WHERE code = :code LIMIT 1',
            [':code' => $code]
        );
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
     * Delete a link by code. Returns true if a row was deleted, false if not found.
     */
    public function delete(string $code): bool
    {
        $existing = $this->db->queryOne(
            'SELECT 1 FROM links WHERE code = :code',
            [':code' => $code]
        );

        if ($existing === null) {
            return false;
        }

        $this->db->execute(
            'DELETE FROM links WHERE code = :code',
            [':code' => $code]
        );

        return true;
    }

    /**
     * Generate a unique alphanumeric short code of the given length.
     */
    private function generateCode(int $length): string
    {
        $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        $max   = strlen($chars) - 1;

        do {
            $code = '';
            for ($i = 0; $i < $length; $i++) {
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
