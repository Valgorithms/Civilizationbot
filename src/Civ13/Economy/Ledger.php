<?php

declare(strict_types=1);

namespace Civ13\Economy;

use PDO;
use RuntimeException;
use InvalidArgumentException;

/** Offline foundation. Call only from a trusted, authenticated server adapter. */
final class Ledger
{
    private PDO $db;

    public function __construct(string $path)
    {
        $this->db = new PDO('sqlite:'.$path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->db->exec('PRAGMA busy_timeout = 5000');
        $this->db->exec('PRAGMA journal_mode = WAL');
        $this->db->exec('PRAGMA synchronous = FULL');
        $this->db->exec('CREATE TABLE IF NOT EXISTS economy_entries (
            id INTEGER PRIMARY KEY, realm TEXT NOT NULL, event_id TEXT NOT NULL,
            account TEXT NOT NULL, delta INTEGER NOT NULL, balance INTEGER NOT NULL,
            reason TEXT NOT NULL, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(realm, event_id))');
    }

    public function balance(string $realm, string $account): int
    {
        $query = $this->db->prepare('SELECT balance FROM economy_entries WHERE realm = ? AND account = ? ORDER BY id DESC LIMIT 1');
        $query->execute([$realm, $account]);
        return (int) ($query->fetchColumn() ?: 0);
    }

    /** Returns the original receipt on an identical replay; rejects conflicting reuse. */
    public function apply(string $realm, string $eventId, string $account, int $delta, string $reason): array
    {
        foreach ([$realm, $eventId, $account, $reason] as $value) {
            if ($value === '' || strlen($value) > 256) {
                throw new InvalidArgumentException('Ledger identifiers and reason must contain 1-256 bytes.');
            }
        }
        if ($delta === 0) {
            throw new InvalidArgumentException('A ledger entry must change the balance.');
        }
        $this->db->exec('BEGIN IMMEDIATE');
        try {
            $query = $this->db->prepare('SELECT * FROM economy_entries WHERE realm = ? AND event_id = ?');
            $query->execute([$realm, $eventId]);
            if ($receipt = $query->fetch(PDO::FETCH_ASSOC)) {
                if ($receipt['account'] !== $account || (int) $receipt['delta'] !== $delta || $receipt['reason'] !== $reason) {
                    throw new RuntimeException('Event ID reused with a different payload.');
                }
            } else {
                $balance = $this->balance($realm, $account);
                if ($delta < -$balance) {
                    throw new RuntimeException('Insufficient balance.');
                }
                if ($delta > 0 && $balance > PHP_INT_MAX - $delta) {
                    throw new RuntimeException('Balance overflow.');
                }
                $query = $this->db->prepare('INSERT INTO economy_entries (realm, event_id, account, delta, balance, reason) VALUES (?, ?, ?, ?, ?, ?)');
                $query->execute([$realm, $eventId, $account, $delta, $balance + $delta, $reason]);
                $query = $this->db->prepare('SELECT * FROM economy_entries WHERE id = ?');
                $query->execute([$this->db->lastInsertId()]);
                $receipt = $query->fetch(PDO::FETCH_ASSOC);
            }
            $this->db->exec('COMMIT');
            return $receipt;
        } catch (\Throwable $error) {
            $this->db->exec('ROLLBACK');
            throw $error;
        }
    }

    /** Newest first, bounded for a future private Discord response. */
    public function history(string $realm, string $account, int $limit = 20): array
    {
        if ($limit < 1 || $limit > 100) {
            throw new InvalidArgumentException('History limit must be 1-100.');
        }
        $query = $this->db->prepare('SELECT * FROM economy_entries WHERE realm = ? AND account = ? ORDER BY id DESC LIMIT ?');
        $query->bindValue(1, $realm);
        $query->bindValue(2, $account);
        $query->bindValue(3, $limit, PDO::PARAM_INT);
        $query->execute();
        return $query->fetchAll(PDO::FETCH_ASSOC);
    }
}
