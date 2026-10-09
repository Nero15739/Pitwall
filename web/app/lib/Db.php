<?php
/* PDO wrapper for SQLite (default, zero setup) or MySQL/MariaDB (Hostinger's hPanel databases).
   The schema is created on first use. Public pages never touch the database: they read the
   compiled JSON, so the database only works when you upload or sign in. */
declare(strict_types=1);

namespace PitWall;

use PDO;

final class Db
{
    private const SCHEMA = 1;
    private static ?Db $inst = null;

    private function __construct(public readonly PDO $pdo, public readonly string $driver) {}

    public static function get(): Db
    {
        if (self::$inst) return self::$inst;
        $cfg = Config::get('db', []);
        $driver = $cfg['driver'] ?? 'sqlite';
        $opts = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false];
        if ($driver === 'mysql') {
            $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $cfg['host'] ?? 'localhost', $cfg['port'] ?? 3306, $cfg['name'] ?? '');
            $pdo = new PDO($dsn, $cfg['user'] ?? '', $cfg['pass'] ?? '', $opts);
            $pdo->exec("SET time_zone = '+00:00'");
        } else {
            $path = $cfg['path'] ?? Paths::storage('pitwall.sqlite');
            $pdo = new PDO('sqlite:' . $path, null, null, $opts);
            $pdo->exec('PRAGMA journal_mode = WAL');
            $pdo->exec('PRAGMA busy_timeout = 5000');
            $pdo->exec('PRAGMA foreign_keys = ON');
        }
        $db = new Db($pdo, $driver === 'mysql' ? 'mysql' : 'sqlite');
        $db->migrate();
        return self::$inst = $db;
    }

    public function all(string $sql, array $p = []): array
    {
        $st = $this->pdo->prepare($sql);
        $st->execute($p);
        return $st->fetchAll();
    }

    public function one(string $sql, array $p = []): ?array
    {
        $st = $this->pdo->prepare($sql);
        $st->execute($p);
        $r = $st->fetch();
        return $r === false ? null : $r;
    }

    public function val(string $sql, array $p = []): mixed
    {
        $st = $this->pdo->prepare($sql);
        $st->execute($p);
        $v = $st->fetchColumn();
        return $v === false ? null : $v;
    }

    public function run(string $sql, array $p = []): int
    {
        $st = $this->pdo->prepare($sql);
        $st->execute($p);
        return $st->rowCount();
    }

    public function insert(string $table, array $row): int
    {
        $cols = array_keys($row);
        $sql = sprintf('INSERT INTO %s (%s) VALUES (%s)', $table, implode(', ', $cols), implode(', ', array_map(fn($c) => ':' . $c, $cols)));
        $this->run($sql, $row);
        return (int) $this->pdo->lastInsertId();
    }

    public function update(string $table, array $row, string $where, array $p = []): int
    {
        $set = implode(', ', array_map(fn($c) => "{$c} = :{$c}", array_keys($row)));
        return $this->run("UPDATE {$table} SET {$set} WHERE {$where}", $row + $p);
    }

    public function tx(callable $fn): mixed
    {
        $this->pdo->beginTransaction();
        try {
            $r = $fn($this);
            $this->pdo->commit();
            return $r;
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }
    }

    private function migrate(): void
    {
        $marker = Paths::storage(".schema-{$this->driver}-" . self::SCHEMA);
        if (is_file($marker)) return;
        $my = $this->driver === 'mysql';
        $id = $my ? 'INT AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        $big = $my ? 'MEDIUMTEXT' : 'TEXT';
        $tail = $my ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';
        $stmts = [
            "CREATE TABLE IF NOT EXISTS seasons (
                id {$id},
                name VARCHAR(120) NOT NULL,
                slug VARCHAR(150) NOT NULL,
                created_at VARCHAR(25) NOT NULL,
                UNIQUE (name), UNIQUE (slug))",
            "CREATE TABLE IF NOT EXISTS races (
                subsession BIGINT NOT NULL PRIMARY KEY,
                season_id INT NOT NULL,
                league_season_id BIGINT NULL,
                track_id INT NOT NULL,
                track VARCHAR(200) NOT NULL,
                start_time VARCHAR(30) NOT NULL,
                filename VARCHAR(255) NOT NULL,
                sha1 CHAR(40) NOT NULL,
                bytes INT NOT NULL,
                raw {$big} NOT NULL,
                processed {$big} NOT NULL,
                process_version INT NOT NULL,
                uploaded_at VARCHAR(25) NOT NULL,
                uploaded_by VARCHAR(120) NOT NULL)",
            "CREATE TABLE IF NOT EXISTS harvests (
                subsession BIGINT NOT NULL PRIMARY KEY,
                track_id INT NULL,
                track VARCHAR(200) NULL,
                harvested_at VARCHAR(30) NULL,
                n_incidents INT NOT NULL,
                n_laps INT NOT NULL,
                filename VARCHAR(255) NOT NULL,
                sha1 CHAR(40) NOT NULL,
                bytes INT NOT NULL,
                raw {$big} NOT NULL,
                uploaded_at VARCHAR(25) NOT NULL,
                uploaded_by VARCHAR(120) NOT NULL)",
            "CREATE TABLE IF NOT EXISTS track_maps (
                track_id INT NOT NULL PRIMARY KEY,
                map {$big} NULL,
                error TEXT NULL,
                fetched_at VARCHAR(25) NOT NULL)",
            "CREATE TABLE IF NOT EXISTS settings (
                name VARCHAR(64) NOT NULL PRIMARY KEY,
                value {$big} NOT NULL)",
            "CREATE TABLE IF NOT EXISTS users (
                id {$id},
                username VARCHAR(64) NOT NULL,
                password_hash VARCHAR(255) NOT NULL,
                created_at VARCHAR(25) NOT NULL,
                last_login_at VARCHAR(25) NULL,
                UNIQUE (username))",
            "CREATE TABLE IF NOT EXISTS api_keys (
                id {$id},
                name VARCHAR(100) NOT NULL,
                prefix VARCHAR(16) NOT NULL,
                hash CHAR(64) NOT NULL,
                created_at VARCHAR(25) NOT NULL,
                last_used_at VARCHAR(25) NULL,
                last_ip VARCHAR(64) NULL,
                uses INT NOT NULL DEFAULT 0,
                revoked_at VARCHAR(25) NULL,
                UNIQUE (hash))",
            "CREATE TABLE IF NOT EXISTS throttle (
                k VARCHAR(190) NOT NULL PRIMARY KEY,
                hits INT NOT NULL,
                reset_at BIGINT NOT NULL)",
            "CREATE TABLE IF NOT EXISTS audit_log (
                id {$id},
                at VARCHAR(25) NOT NULL,
                actor VARCHAR(120) NOT NULL,
                action VARCHAR(60) NOT NULL,
                detail TEXT NULL,
                ip VARCHAR(64) NULL)",
        ];
        foreach ($stmts as $s) $this->pdo->exec($s . $tail);
        foreach (['CREATE INDEX races_season ON races (season_id)', 'CREATE INDEX audit_at ON audit_log (at)'] as $ix) {
            try { $this->pdo->exec($ix); } catch (\PDOException) { /* already there */ }
        }
        @file_put_contents($marker, gmdate('c'));
    }
}
