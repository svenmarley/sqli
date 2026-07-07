<?php

declare(strict_types=1);

namespace Sqli2\Db;

use includes\my;
use PDO;
use PDOException;

/**
 * Thin PDO/MySQL wrapper. Owns the connection, executes queries into
 * QueryResult value objects, and transparently reconnects once on
 * "server has gone away" (2006) / "lost connection" (2013) per spec 5.
 */
final class Database
{
    private const RECONNECT_CODES = [2006, 2013];

    private ?PDO $pdo = null;

    public function __construct(
        private readonly string $host = 'localhost',
        private readonly string $user = '',
        private readonly string $password = '',
        private string $database = '',
        private readonly int $port = 3306,
        private readonly string $socket = '',
        private readonly bool $debug = false,
    ) {
    }

    /** @throws PDOException */
    public function connect(): void
    {
        $dsn = $this->buildDsn();
        if ($this->debug) {
            my::lineout('FILE:FILE', 'connecting dsn', $dsn);
        }
        $this->pdo = new PDO($dsn, $this->user, $this->password, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_STRINGIFY_FETCHES  => false,
        ]);
        if ($this->debug) {
            my::lineout('FILE:FILE', 'connected', $this->getConnectionLabel());
        }
    }

    public function isConnected(): bool
    {
        return $this->pdo !== null;
    }

    public function getConnectionLabel(): string
    {
        $where = $this->socket !== '' ? $this->socket : $this->host . ':' . $this->port;
        $db = $this->database !== '' ? '/' . $this->database : '';
        $who = $this->user !== '' ? $this->user . '@' : '';
        return $who . $where . $db;
    }

    public function currentDatabase(): string
    {
        return $this->database;
    }

    public function setCurrentDatabase(string $db): void
    {
        $this->database = $db;
    }

    /**
     * Execute a statement and return a QueryResult. Never throws for query
     * errors — they are captured in the result's error field.
     */
    public function query(string $sql): QueryResult
    {
        if ($this->pdo === null) {
            return QueryResult::fromError('Not connected to a database');
        }

        $start = microtime(true);
        try {
            return $this->run($sql, $start);
        } catch (PDOException $e) {
            $code = (int) ($e->errorInfo[1] ?? 0);
            if (in_array($code, self::RECONNECT_CODES, true)) {
                if ($this->debug) {
                    my::lineout('FILE:FILE', 'reconnecting on code', $code);
                }
                try {
                    $this->connect();
                    return $this->run($sql, $start);
                } catch (PDOException $retry) {
                    return QueryResult::fromError($this->formatError($retry), microtime(true) - $start);
                }
            }
            return QueryResult::fromError($this->formatError($e), microtime(true) - $start);
        }
    }

    private function run(string $sql, float $start): QueryResult
    {
        $stmt = $this->pdo->query($sql);
        $elapsed = microtime(true) - $start;

        // A result-bearing statement reports one or more columns.
        if ($stmt->columnCount() > 0) {
            $columns = [];
            for ($i = 0; $i < $stmt->columnCount(); $i++) {
                $meta = $stmt->getColumnMeta($i);
                $columns[] = $meta['name'] ?? ('col' . $i);
            }
            $rows = $stmt->fetchAll();
            return new QueryResult(
                columns: $columns,
                rows: $rows,
                elapsed: $elapsed,
                isSelect: true,
            );
        }

        return new QueryResult(
            affectedRows: $stmt->rowCount(),
            elapsed: $elapsed,
            isSelect: false,
        );
    }

    private function buildDsn(): string
    {
        $parts = ['charset=utf8mb4'];
        if ($this->socket !== '') {
            $parts[] = 'unix_socket=' . $this->socket;
        } else {
            $parts[] = 'host=' . $this->host;
            $parts[] = 'port=' . $this->port;
        }
        if ($this->database !== '') {
            $parts[] = 'dbname=' . $this->database;
        }
        return 'mysql:' . implode(';', $parts);
    }

    private function formatError(PDOException $e): string
    {
        $code = $e->errorInfo[1] ?? $e->getCode();
        $msg = $e->errorInfo[2] ?? $e->getMessage();
        if ($this->debug) {
            my::lineout('FILE:FILE', 'query error code', $code, 'msg', (string) $msg);
        }
        return trim('[' . $code . '] ' . $msg);
    }
}
