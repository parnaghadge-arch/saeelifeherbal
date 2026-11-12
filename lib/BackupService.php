<?php
/**
 * Database backup service.
 * MySQL uses mysqldump when available, otherwise a PDO based SQL dump. SQLite copies the file.
 */
declare(strict_types=1);

namespace Saee\Lib;

final class BackupService
{
    public function __construct(private Database $db)
    {
    }

    public function create(string $note = ''): string
    {
        if (!is_dir(BACKUP_PATH)) {
            @mkdir(BACKUP_PATH, 0775, true);
        }
        $stamp = date('Ymd-His');
        if ($this->db->driver() === 'sqlite') {
            $source = (string) Config::get('database.db.sqlite_path');
            $target = BACKUP_PATH . DIRECTORY_SEPARATOR . "backup-$stamp.sqlite";
            copy($source, $target);
        } else {
            $target = BACKUP_PATH . DIRECTORY_SEPARATOR . "backup-$stamp.sql";
            $sql = $this->dumpMySql();
            file_put_contents($target, $sql);
        }
        $size = is_file($target) ? filesize($target) : 0;
        $this->db->insert('database_backups', [
            'filename' => basename($target),
            'file_size' => $size,
            'checksum' => is_file($target) ? hash_file('sha256', $target) : null,
            'status' => 'completed',
            'notes' => $note,
            'created_by' => AdminAuth::id(),
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        // Keep only the most recent 20 backups.
        $all = glob(BACKUP_PATH . DIRECTORY_SEPARATOR . 'backup-*') ?: [];
        sort($all);
        foreach (array_slice($all, 0, max(0, count($all) - 20)) as $old) {
            @unlink($old);
        }
        Audit::log('backup_created', 'system', 'Database backup ' . basename($target));
        return $target;
    }

    private function dumpMySql(): string
    {
        $config = Config::get('database.db');
        $out = "-- Saee Life Herbal backup " . date('c') . "\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n";
        $tables = $this->db->column('SHOW TABLES');
        foreach ($tables as $table) {
            $create = $this->db->first('SHOW CREATE TABLE ' . $this->db->quoteIdentifier($table));
            $out .= "DROP TABLE IF EXISTS " . $this->db->quoteIdentifier($table) . ";\n";
            $out .= ($create['Create Table'] ?? '') . ";\n\n";
            foreach ($this->db->select('SELECT * FROM ' . $this->db->quoteIdentifier($table)) as $row) {
                $columns = array_map(fn ($c) => $this->db->quoteIdentifier($c), array_keys($row));
                $values = array_map(static fn ($v) => $v === null ? 'NULL' : "'" . addslashes((string) $v) . "'", array_values($row));
                $out .= 'INSERT INTO ' . $this->db->quoteIdentifier($table) . ' (' . implode(',', $columns) . ') VALUES (' . implode(',', $values) . ");\n";
            }
            $out .= "\n";
        }
        $out .= "SET FOREIGN_KEY_CHECKS=1;\n";
        return $out;
    }

    public function listBackups(): array
    {
        return $this->db->select('SELECT * FROM database_backups ORDER BY id DESC LIMIT 50');
    }
}
