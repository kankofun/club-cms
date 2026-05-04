<?php
// src/Content.php

class Content {
    private $db;
    private const DIR = 'contents/';
    private const CAT_FILE = 'categories.json';

    public function __construct(FileDB $db) {
        $this->db = $db;
        $dir = DATA_DIR . '/' . self::DIR;
        if (!is_dir($dir)) mkdir($dir, 0777, true);
    }

    public function getAll() {
        $dir = DATA_DIR . '/' . self::DIR;
        $contents = [];
        foreach (glob($dir . '*.json') as $file) {
            $filename = self::DIR . basename($file);
            $content = $this->db->read($filename, null);
            if ($content) $contents[] = $content;
        }
        usort($contents, function($a, $b) {
            return strtotime($b['updated_at']) < strtotime($a['updated_at']) ? 1 : -1;
        });
        return $contents;
    }

    public function getById($id) {
        return $this->db->read(self::DIR . $id . '.json', null);
    }

    public function save($data, $base_version) {
        $id = $data['id'] ?? '';
        if (empty($id)) {
            $data['id'] = uniqid('post_');
            $data['version'] = 1;
            $data['created_at'] = date('Y-m-d H:i:s');
            $data['updated_at'] = date('Y-m-d H:i:s');
            $this->db->write(self::DIR . $data['id'] . '.json', $data);
            return ['success' => true];
        } else {
            $current = $this->getById($id);
            if (!$current) return ['success' => false, 'error' => 'not_found'];
            if ((int)$current['version'] !== (int)$base_version) return ['success' => false, 'error' => 'conflict', 'current_data' => $current];
            $data['version'] = (int)$current['version'] + 1;
            $data['created_at'] = $current['created_at'] ?? $current['updated_at'];
            $data['updated_at'] = date('Y-m-d H:i:s');
            $data['author_id'] = $current['author_id']; 
            $this->db->write(self::DIR . $id . '.json', $data);
            return ['success' => true];
        }
    }

    public function getBySlug($slug, $type = null) {
        foreach ($this->getAll() as $c) {
            if (($c['slug'] ?? '') === $slug) {
                if ($type && $c['type'] !== $type) continue;
                return $c;
            }
        }
        return null;
    }

    public function delete($id) {
        return $this->db->delete(self::DIR . $id . '.json');
    }

    // ==========================================
    // カテゴリ管理機能
    // ==========================================
    public function getCategories() {
        return $this->db->read(self::CAT_FILE, []);
    }

    public function getCategoryById($id) {
        foreach ($this->getCategories() as $c) if ($c['id'] === $id) return $c;
        return null;
    }

    public function saveCategory($data) {
        $cats = $this->getCategories();
        if (empty($data['id'])) {
            $data['id'] = uniqid('cat_');
            $cats[] = $data;
        } else {
            foreach ($cats as $k => $c) {
                if ($c['id'] === $data['id']) {
                    $cats[$k] = array_merge($c, $data);
                    break;
                }
            }
        }
        return $this->db->write(self::CAT_FILE, $cats);
    }

    public function deleteCategory($id) {
        $cats = array_filter($this->getCategories(), function($c) use ($id) { return $c['id'] !== $id; });
        return $this->db->write(self::CAT_FILE, array_values($cats));
    }

    // ==========================================
    // タグ抽出機能
    // ==========================================
    public function getAllTags() {
        $tags = [];
        foreach ($this->getAll() as $c) {
            if ($c['type'] === 'blog' && !empty($c['tags']) && is_array($c['tags'])) {
                foreach ($c['tags'] as $t) {
                    $t = trim($t);
                    if ($t !== '' && !in_array($t, $tags)) $tags[] = $t;
                }
            }
        }
        sort($tags);
        return $tags;
    }
}