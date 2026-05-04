<?php
// src/FileDB.php

class FileDB {
    private $baseDir;

    public function __construct() {
        $this->baseDir = DATA_DIR;
        // dataディレクトリがなければ作成
        if (!is_dir($this->baseDir)) {
            mkdir($this->baseDir, 0777, true);
        }
    }

    /**
     * JSONファイルを読み込む
     */
    public function read($filename, $default = []) {
        $filepath = $this->baseDir . '/' . $filename;
        if (!file_exists($filepath)) {
            return $default;
        }
        $json = file_get_contents($filepath);
        $data = json_decode($json, true);
        return $data !== null ? $data : $default;
    }

    /**
     * JSONファイルに書き込む（ファイルロック・自動バックアップ付き）
     */
    public function write($filename, $data) {
        $filepath = $this->baseDir . '/' . $filename;
        $dir = dirname($filepath);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        // ★ 書き込み直前に、設定された件数分だけ自動世代バックアップを作成
        if (file_exists($filepath)) {
            $settingsFile = $this->baseDir . '/settings.json';
            $settings = file_exists($settingsFile) ? (json_decode(file_get_contents($settingsFile), true) ?: []) : [];
            $retention = (int)($settings['backup_retention_count'] ?? 10);
            
            if ($retention > 0) {
                $backupDir = $this->baseDir . '/backups/' . dirname($filename);
                if (!is_dir($backupDir)) mkdir($backupDir, 0777, true);
                
                $backupFilename = date('Ymd_His') . '_' . basename($filename);
                copy($filepath, $backupDir . '/' . $backupFilename);
                
                // 設定された件数（retention）を超えた古いバックアップを削除
                $files = glob($backupDir . '/*_' . basename($filename));
                if (count($files) > $retention) {
                    usort($files, function($a, $b) { return filemtime($a) - filemtime($b); });
                    for ($i = 0; $i < count($files) - $retention; $i++) {
                        @unlink($files[$i]);
                    }
                }
            }
        }

        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        // LOCK_EXで排他ロックをかけ、同時書き込みによる破損を防ぐ
        $result = file_put_contents($filepath, $json, LOCK_EX);
        return $result !== false;
    }
}