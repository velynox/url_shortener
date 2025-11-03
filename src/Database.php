<?php

declare(strict_types=1);

namespace App;

use PDO;
use PDOException;

/**
 * Thin PDO wrapper for SQLite.
 * Handles connection, schema bootstrapping, and query execution.
 */
class Database
{
    private PDO $pdo;

    public function __construct(string $path)
    {
        try {
            $this->pdo = new PDO('sqlite:' . $path, options: [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
        } catch (PDOException $e) {
            throw new \RuntimeException('Database connection failed: ' . $e->getMessage());
        }

        $this->bootstrap();
    }

    /**
     * Create the links table if it does not exist.
     */
    private function bootstrap(): void
    {
        $this->pdo->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS links (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                code       TEXT    NOT NULL UNIQUE,
                url        TEXT    NOT NULL,
                clicks     INTEGER NOT NULL DEFAULT 0,
                created_at TEXT    NOT NULL DEFAULT (datetime('now'))
            )
        SQL);
    }

    /**
     * Execute a query and return all matching rows.
     *
     * @param array<string, mixed> $params
     * @return array<int, array<string, mixed>>
     */
    public function query(string $sql, array $params = []): array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Execute a query and return a single row, or null if not found.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>|null
     */
    public function queryOne(string $sql, array $params = []): ?array
    {
        $result = $this->query($sql, $params);
        return $result[0] ?? null;
    }

    /**
     * Execute a write statement (INSERT, UPDATE, DELETE).
     *
     * @param array<string, mixed> $params
     */
    public function execute(string $sql, array $params = []): void
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
    }
}
