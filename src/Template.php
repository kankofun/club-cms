<?php
// src/Template.php

class Template {
    private $dir;

    public function __construct() {
        $this->dir = DATA_DIR . '/templates/';
        if (!is_dir($this->dir)) mkdir($this->dir, 0777, true);
        
        $defaultHeader = "<!DOCTYPE html>\n<html lang=\"ja\">\n<head>\n<meta charset=\"utf-8\">\n<title>パソコン部 公式サイト</title>\n<link rel=\"stylesheet\" href=\"{{BASE_URL}}assets/style.css\">\n</head>\n<body>\n<header>\n  <div style=\"display:flex; justify-content:space-between; align-items:center;\">\n    <h1>パソコン部 公式サイト</h1>\n    <nav><a href=\"{{BASE_URL}}\">ホーム</a> | <a href=\"{{BASE_URL}}login\">部員ログイン</a></nav>\n  </div>\n</header>\n<main>\n";
        $defaultFooter = "</main>\n<footer>\n  <p>&copy; 2026 パソコン部</p>\n</footer>\n</body>\n</html>";
        $defaultCss = "body { font-family: sans-serif; background: #f4f4f4; margin: 0; }\nmain { background: #fff; padding: 20px; margin: 20px; border-radius: 5px; min-height: 50vh; }\nheader { background: #0056b3; color: #fff; padding: 10px 20px; }\nheader a { color: #fff; text-decoration: none; }\nfooter { text-align: center; padding: 10px; background: #ddd; }";
        
        if (!file_exists($this->dir . 'header.html')) file_put_contents($this->dir . 'header.html', $defaultHeader);
        if (!file_exists($this->dir . 'footer.html')) file_put_contents($this->dir . 'footer.html', $defaultFooter);
        if (!file_exists($this->dir . 'style.css')) file_put_contents($this->dir . 'style.css', $defaultCss);
    }

    public function get($name) {
        return file_exists($this->dir . $name) ? file_get_contents($this->dir . $name) : '';
    }

    public function save($name, $content) {
        if (in_array($name, ['header.html', 'footer.html', 'style.css'])) {
            // ★ 上書き前にバックアップ
            $filepath = $this->dir . $name;
            if (file_exists($filepath)) {
                $this->backup($name);
            }
            file_put_contents($filepath, $content);
        }
    }

    // ★ 新規追加: テンプレート用バックアップ処理
    private function backup($name) {
        $filepath = $this->dir . $name;
        if (!file_exists($filepath)) return;
        
        $backupDir = DATA_DIR . '/backups/templates';
        if (!is_dir($backupDir)) @mkdir($backupDir, 0777, true);
        
        $backupFilename = date('Ymd_His') . '_' . $name;
        copy($filepath, $backupDir . '/' . $backupFilename);
        
        $files = glob($backupDir . '/*_' . $name);
        if (count($files) > 10) {
            usort($files, function($a, $b) { return filemtime($a) - filemtime($b); });
            for ($i = 0; $i < count($files) - 10; $i++) @unlink($files[$i]);
        }
    }
    
    public function renderHeader($baseUrl) {
        return str_replace('{{BASE_URL}}', $baseUrl, $this->get('header.html'));
    }
    
    public function renderFooter() {
        return $this->get('footer.html');
    }
}