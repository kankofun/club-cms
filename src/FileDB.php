<?php
// src/FileDB.php

class FileDB {
    private $baseDir;

    public function __construct($baseDir = __DIR__ . '/../data/') {
        $this->baseDir = rtrim($baseDir, '/') . '/';
        if (!is_dir($this->baseDir)) {
            @mkdir($this->baseDir, 0777, true);
        }
    }

    public function read($filename, $default = []) {
        $path = $this->baseDir . ltrim($filename, '/');
        if (!file_exists($path)) {
            return $default;
        }
        $fp = @fopen($path, 'r');
        if (!$fp) return $default;
        
        // 読み込み中も LOCK_SH（共有ロック）をかけ、他者が書き込み中の不完全なデータを読み込まないようにする
        flock($fp, LOCK_SH);
        $size = filesize($path);
        $json = $size > 0 ? fread($fp, $size) : '';
        flock($fp, LOCK_UN);
        fclose($fp);

        if (empty($json)) return $default;
        $data = json_decode($json, true);
        return $data !== null ? $data : $default;
    }

    public function write($filename, $data) {
        $path = $this->baseDir . ltrim($filename, '/');
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
        
        // 一度 temp ファイルを作成して書き込み（アトミック書き込みの準備）
        $tempPath = $path . '.tmp.' . uniqid();
        $fp = @fopen($tempPath, 'w');
        if (!$fp) return false;

        // 書き込み中は LOCK_EX（排他ロック）をかける
        flock($fp, LOCK_EX);
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        fwrite($fp, $json);
        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);

        // Windows環境では上書き rename が失敗することがあるため unlink を挟む
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            if (file_exists($path)) @unlink($path);
        }
        
        // rename で一瞬で置換し「ファイルが空になる」瞬間をなくす
        if (!@rename($tempPath, $path)) {
            // 万が一 rename に失敗した場合のフォールバック
            copy($tempPath, $path);
            unlink($tempPath);
        }
        
        return true;
    }

    public function delete($filename) {
        $path = $this->baseDir . ltrim($filename, '/');
        if (file_exists($path)) {
            return unlink($path);
        }
        return true;
    }
}