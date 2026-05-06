<?php
// src/Content.php

class Content {
    private $db;
    private const DIR = 'contents/';
    private const REV_DIR = 'contents/revisions/';
    private const INDEX_FILE = 'contents/_index.json';
    private const CAT_FILE = 'categories.json';

    public function __construct(FileDB $db) {
        $this->db = $db;
        $dir = DATA_DIR . '/' . self::DIR;
        if (!is_dir($dir)) @mkdir($dir, 0777, true);
        $revDir = DATA_DIR . '/' . self::REV_DIR;
        if (!is_dir($revDir)) @mkdir($revDir, 0777, true);
    }

    private function getIndex() {
        $index = $this->db->read(self::INDEX_FILE, null);
        if ($index === null) {
            $index = [];
            $dir = DATA_DIR . '/' . self::DIR;
            foreach (glob($dir . '*.json') as $file) {
                $filename = self::DIR . basename($file);
                if ($filename === self::INDEX_FILE) continue;
                $content = $this->db->read($filename, null);
                if ($content) {
                    $index[] = [
                        'id' => $content['id'],
                        'title' => $content['title'],
                        'slug' => $content['slug'] ?? '',
                        'type' => $content['type'],
                        'created_at' => $content['created_at'] ?? '',
                        'updated_at' => $content['updated_at'] ?? '',
                        'author_id' => $content['author_id'] ?? null,
                        'category_id' => $content['category_id'] ?? null,
                        'tags' => $content['tags'] ?? []
                    ];
                }
            }
            $this->db->write(self::INDEX_FILE, $index);
        }
        return $index;
    }

    public function getAll() {
        return $this->getIndex();
    }

    public function getAllFull() {
        $index = $this->getIndex();
        $contents = [];
        foreach ($index as $item) {
            $content = $this->getById($item['id']);
            if ($content) $contents[] = $content;
        }
        return $contents;
    }

    public function getById($id) {
        return $this->db->read(self::DIR . $id . '.json', null);
    }

    // ★ リビジョンの一覧取得
    public function getRevisions($id) {
        $revisions = [];
        $revDir = DATA_DIR . '/' . self::REV_DIR;
        foreach (glob($revDir . $id . '_v*.json') as $file) {
            $content = $this->db->read(self::REV_DIR . basename($file), null);
            if ($content) {
                $revisions[] = $content;
            }
        }
        usort($revisions, function($a, $b) {
            return (int)$b['version'] <=> (int)$a['version'];
        });
        return $revisions;
    }

    // ★ 特定のリビジョンの取得
    public function getRevision($id, $version) {
        return $this->db->read(self::REV_DIR . $id . '_v' . $version . '.json', null);
    }

    public function save($data, $base_version) {
        $id = $data['id'] ?? '';
        $isNew = empty($id);
        
        if ($isNew) {
            $id = uniqid('post_');
            $data['id'] = $id;
            $data['version'] = 1;
            $data['created_at'] = date('Y-m-d H:i:s');
            $data['updated_at'] = date('Y-m-d H:i:s');
        } else {
            $current = $this->getById($id);
            if (!$current) return ['success' => false, 'error' => 'not_found'];
            
            if ((int)$current['version'] !== (int)$base_version) {
                return ['success' => false, 'error' => 'conflict', 'current_data' => $current];
            }
            
            $this->db->write(self::REV_DIR . $id . '_v' . $current['version'] . '.json', $current);

            $data['version'] = (int)$current['version'] + 1;
            $data['created_at'] = $current['created_at'] ?? date('Y-m-d H:i:s');
            $data['updated_at'] = date('Y-m-d H:i:s');
        }

        $this->db->write(self::DIR . $id . '.json', $data);
        $this->updateIndex($data);

        return ['success' => true];
    }

    private function updateIndex($data) {
        $index = $this->getIndex();
        $indexData = [
            'id' => $data['id'],
            'title' => $data['title'],
            'slug' => $data['slug'],
            'type' => $data['type'],
            'created_at' => $data['created_at'],
            'updated_at' => $data['updated_at'],
            'author_id' => $data['author_id'] ?? null,
            'category_id' => $data['category_id'] ?? null,
            'tags' => $data['tags'] ?? []
        ];

        $found = false;
        foreach ($index as $key => $item) {
            if ($item['id'] === $data['id']) {
                $index[$key] = $indexData;
                $found = true;
                break;
            }
        }
        if (!$found) $index[] = $indexData;
        
        usort($index, function($a, $b) {
            return strtotime($b['updated_at']) <=> strtotime($a['updated_at']);
        });
        
        $this->db->write(self::INDEX_FILE, $index);
    }

    private function removeFromIndex($id) {
        $index = $this->getIndex();
        $index = array_values(array_filter($index, function($item) use ($id) {
            return $item['id'] !== $id;
        }));
        $this->db->write(self::INDEX_FILE, $index);
    }

    public function getBySlug($slug, $type = null) {
        foreach ($this->getIndex() as $item) {
            if (($item['slug'] ?? '') === $slug) {
                if ($type && $item['type'] !== $type) continue;
                return $this->getById($item['id']);
            }
        }
        return null;
    }

    public function delete($id) {
        $this->db->delete(self::DIR . $id . '.json');
        $this->removeFromIndex($id);
        return true;
    }

    public function getCategories() { return $this->db->read(self::CAT_FILE, []); }

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

    public function getAllTags() {
        $tags = [];
        foreach ($this->getIndex() as $c) {
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
