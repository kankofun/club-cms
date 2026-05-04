<?php
// src/Router.php
require_once __DIR__ . '/TOTP.php';
require_once __DIR__ . '/Mailer.php';

class Router {
    private $auth;
    private $userModel;
    private $contentModel;
    private $templateModel;

    public function __construct(Auth $auth, User $userModel, Content $contentModel, Template $templateModel) {
        $this->auth = $auth;
        $this->userModel = $userModel;
        $this->contentModel = $contentModel;
        $this->templateModel = $templateModel;
    }

    private function writeLog($user, $action, $details = '') {
        $logFile = __DIR__ . '/../data/app.log';
        if (!file_exists(dirname($logFile))) @mkdir(dirname($logFile), 0777, true);
        $date = date('Y-m-d H:i:s');
        $userName = $user ? $user['name'] . ' (' . $user['student_id'] . ')' : 'System/Guest';
        $line = "$date\t$userName\t$action\t$details\n";
        @file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);

        $settings = $this->getSettings();
        $maxLines = (int)($settings['log_max_lines'] ?? 1000);
        if ($maxLines > 0 && file_exists($logFile)) {
            $lines = file($logFile);
            if (count($lines) > $maxLines) {
                $lines = array_slice($lines, -$maxLines);
                @file_put_contents($logFile, implode("", $lines), LOCK_EX);
            }
        }
    }

    private function getSettings() {
        $file = __DIR__ . '/../data/settings.json';
        return file_exists($file) ? json_decode(file_get_contents($file), true) ?: [] : [];
    }

    private function saveSettings($data) {
        $file = __DIR__ . '/../data/settings.json';
        $retention = (int)($data['backup_retention_count'] ?? 10);
        
        if (file_exists($file) && $retention > 0) {
            $backupDir = __DIR__ . '/../data/backups';
            if (!is_dir($backupDir)) @mkdir($backupDir, 0777, true);
            $backupFilename = date('Ymd_His') . '_settings.json';
            copy($file, $backupDir . '/' . $backupFilename);
            $files = glob($backupDir . '/*_settings.json');
            if (count($files) > $retention) {
                usort($files, function($a, $b) { return filemtime($a) - filemtime($b); });
                for ($i = 0; $i < count($files) - $retention; $i++) @unlink($files[$i]);
            }
        }
        if (!file_exists(dirname($file))) @mkdir(dirname($file), 0777, true);
        @file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    private function getUploads() {
        $file = __DIR__ . '/../data/uploads.json';
        return file_exists($file) ? json_decode(file_get_contents($file), true) ?: [] : [];
    }

    private function saveUploads($data) {
        $file = __DIR__ . '/../data/uploads.json';
        @file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    private function replaceVariables($text, $baseUrl) {
        if (!is_string($text) || $text === '') return '';
        $settings = $this->getSettings();
        $dateField = $settings['blog_date_type'] ?? 'updated_at';
        $categories = $this->contentModel->getCategories();
        
        $currentUrlPath = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
        $actionUrl = htmlspecialchars($currentUrlPath);

        $rawVars = $settings['variables'] ?? '';
        $lines = explode("\n", $rawVars);
        foreach($lines as $line) {
            $line = trim($line);
            if ($line === '' || strpos($line, '=') === false) continue;
            list($k, $v) = explode('=', $line, 2);
            $text = str_replace('{{' . trim($k) . '}}', trim($v), $text);
        }

        if (strpos($text, '{{blog_search_form}}') !== false) {
            $formHtml = "<form action='{$actionUrl}' method='GET' style='display:flex;gap:5px;margin-bottom:15px;'><input type='text' name='q' placeholder='ブログを検索...' required style='padding:5px; flex:1;'><button type='submit' style='padding:5px 10px;cursor:pointer;'>検索</button></form>";
            $text = str_replace('{{blog_search_form}}', $formHtml, $text);
        }
        if (strpos($text, '{{site_search_form}}') !== false) {
            if (!empty($settings['site_search_enabled'])) {
                $formHtml = "<form action='{$baseUrl}search' method='GET' style='display:flex;gap:5px;margin-bottom:15px;'><input type='text' name='q' placeholder='サイト内を検索...' required style='padding:5px; flex:1;'><button type='submit' style='padding:5px 10px;cursor:pointer;'>検索</button></form>";
                $text = str_replace('{{site_search_form}}', $formHtml, $text);
            } else {
                $text = str_replace('{{site_search_form}}', '', $text);
            }
        }

        if (strpos($text, '{{blog_categories}}') !== false) {
            if (!empty($settings['blog_category_enabled'])) {
                $html = "<ul class='blog-categories-list' style='list-style:none; padding:0;'>";
                foreach ($categories as $c) {
                    $html .= "<li style='margin-bottom:5px;'><a href='{$actionUrl}?category={$c['id']}'>".htmlspecialchars($c['name'])."</a></li>";
                }
                $html .= "</ul>";
                $text = str_replace('{{blog_categories}}', $html, $text);
            } else { $text = str_replace('{{blog_categories}}', '', $text); }
        }
        if (strpos($text, '{{blog_tags}}') !== false) {
            if (!empty($settings['blog_tag_enabled'])) {
                $tags = $this->contentModel->getAllTags();
                $html = "<div class='blog-tags-list' style='display:flex; flex-wrap:wrap; gap:5px;'>";
                foreach ($tags as $t) {
                    $html .= "<a href='{$actionUrl}?tag=".urlencode($t)."' style='background:#e2e3e5; padding:2px 8px; border-radius:10px; text-decoration:none; color:#333; font-size:0.9em;'>#".htmlspecialchars($t)."</a>";
                }
                $html .= "</div>";
                $text = str_replace('{{blog_tags}}', $html, $text);
            } else { $text = str_replace('{{blog_tags}}', '', $text); }
        }
        if (strpos($text, '{{blog_archives}}') !== false) {
            $allBlogs = array_filter($this->contentModel->getAll(), function($c) { return $c['type'] === 'blog'; });
            $archives = [];
            foreach ($allBlogs as $b) {
                $bDate = !empty($b[$dateField]) ? $b[$dateField] : ($b['updated_at'] ?? 'now');
                $m = date('Y-m', strtotime($bDate));
                if (!isset($archives[$m])) $archives[$m] = 0;
                $archives[$m]++;
            }
            krsort($archives);
            $html = "<ul class='blog-archives-list' style='list-style:none; padding:0;'>";
            foreach ($archives as $m => $cnt) {
                $mName = date('Y年n月', strtotime($m . '-01'));
                $html .= "<li style='margin-bottom:5px;'><a href='{$actionUrl}?month={$m}'>{$mName} ({$cnt})</a></li>";
            }
            $html .= "</ul>";
            $text = str_replace('{{blog_archives}}', $html, $text);
        }

        $text = str_replace('{{latest_blogs}}', '{{blogs limit=5}}', $text);
        $text = preg_replace('/\{\{latest_blogs_(\d+)\}\}/', '{{blogs limit=$1}}', $text);

        if (strpos($text, '{{blog_main_list}}') !== false) {
            $q = trim($_GET['q'] ?? '');
            $catId = trim($_GET['category'] ?? '');
            $tag = trim($_GET['tag'] ?? '');
            $month = trim($_GET['month'] ?? '');
            $p = max(1, (int)($_GET['p'] ?? 1));
            $sort = $_GET['sort'] ?? ($q !== '' ? 'score' : 'date');
            $order = $_GET['order'] ?? 'desc';

            $allBlogs = array_filter($this->contentModel->getAll(), function($c) { return $c['type'] === 'blog'; });
            $filteredBlogs = [];
            foreach ($allBlogs as $b) {
                $bDate = !empty($b[$dateField]) ? $b[$dateField] : ($b['updated_at'] ?? 'now');
                if ($month && date('Y-m', strtotime($bDate)) !== $month) continue;
                if ($catId && ($b['category_id'] ?? '') !== $catId) continue;
                if ($tag && (!isset($b['tags']) || !is_array($b['tags']) || !in_array($tag, $b['tags']))) continue;
                
                $b['score'] = 0;
                if ($q !== '') {
                    $searchStrTitle = mb_strtolower($b['title']);
                    $searchStrBody = mb_strtolower($b['body']);
                    $qLower = mb_strtolower($q);
                    
                    $countTitle = mb_substr_count($searchStrTitle, $qLower);
                    $countBody = mb_substr_count($searchStrBody, $qLower);
                    if ($countTitle === 0 && $countBody === 0) continue;
                    $b['score'] = $countTitle * 10 + $countBody;
                }
                $filteredBlogs[] = $b;
            }

            usort($filteredBlogs, function($a, $b) use ($sort, $order, $dateField) {
                if ($sort === 'score') {
                    $valA = $a['score'];
                    $valB = $b['score'];
                } else {
                    $aDate = !empty($a[$dateField]) ? $a[$dateField] : ($a['updated_at'] ?? 'now');
                    $bDate = !empty($b[$dateField]) ? $b[$dateField] : ($b['updated_at'] ?? 'now');
                    $valA = strtotime($aDate);
                    $valB = strtotime($bDate);
                }
                if ($valA == $valB) return 0;
                if ($order === 'asc') return ($valA < $valB) ? -1 : 1;
                return ($valA > $valB) ? -1 : 1;
            });

            $perPage = 10;
            $total = count($filteredBlogs);
            $maxPage = max(1, ceil($total / $perPage));
            if ($p > $maxPage) $p = $maxPage;
            $pagedBlogs = array_slice($filteredBlogs, ($p - 1) * $perPage, $perPage);

            $html = '';
            if ($q || $catId || $tag || $month) {
                $html .= "<div style='margin-bottom:20px; padding:10px; background:#e9ecef; border-radius:4px;'>";
                $html .= "<strong>検索条件:</strong> ";
                if ($q) $html .= "キーワード「".htmlspecialchars($q)."」 ";
                if ($catId) {
                    $cName = '不明';
                    foreach($categories as $c) if($c['id']===$catId) $cName = $c['name'];
                    $html .= "カテゴリ「".htmlspecialchars($cName)."」 ";
                }
                if ($tag) $html .= "タグ「".htmlspecialchars($tag)."」 ";
                if ($month) $html .= "月「".htmlspecialchars($month)."」 ";
                $html .= " <a href='{$actionUrl}' style='font-size:0.9em; margin-left:10px;'>[条件をクリア]</a></div>";
            }

            if (empty($filteredBlogs)) {
                $html .= "<p>記事が見つかりませんでした。</p>";
            } else {
                $html .= "<form method='GET' action='{$actionUrl}' style='display:flex; justify-content:space-between; align-items:center; margin-bottom: 20px; background:#f8f9fa; padding:10px; border-radius:4px;'>";
                if($q) $html .= "<input type='hidden' name='q' value='".htmlspecialchars($q)."'>";
                if($catId) $html .= "<input type='hidden' name='category' value='".htmlspecialchars($catId)."'>";
                if($tag) $html .= "<input type='hidden' name='tag' value='".htmlspecialchars($tag)."'>";
                if($month) $html .= "<input type='hidden' name='month' value='".htmlspecialchars($month)."'>";
                $html .= "<div>全 {$total} 件中 ".(($p-1)*$perPage+1)." - ".min($total, $p*$perPage)." 件を表示</div>";
                $html .= "<div><select name='sort' onchange='this.form.submit()'>";
                if ($q) $html .= "<option value='score' ".($sort==='score'?'selected':'').">一致度順</option>";
                $html .= "<option value='date' ".($sort==='date'?'selected':'').">日付順</option></select> ";
                $html .= "<select name='order' onchange='this.form.submit()'><option value='desc' ".($order==='desc'?'selected':'').">降順</option><option value='asc' ".($order==='asc'?'selected':'').">昇順</option></select></div></form>";

                $html .= "<ul style='list-style:none; padding:0;'>";
                foreach ($pagedBlogs as $blog) {
                    $bDate = !empty($blog[$dateField]) ? $blog[$dateField] : ($blog['updated_at'] ?? 'now');
                    $dateStr = date('Y.m.d', strtotime($bDate));
                    $html .= "<li style='margin-bottom:20px; padding-bottom:15px; border-bottom:1px solid #eee;'>";
                    $html .= "<div style='margin-bottom:5px;'><span style='color:#666; font-size:0.9em; margin-right:10px;'>{$dateStr}</span> ";
                    
                    if (!empty($settings['blog_category_enabled']) && !empty($blog['category_id'])) {
                        foreach($categories as $c) {
                            if($c['id'] === $blog['category_id']) {
                                $color = !empty($c['color']) ? $c['color'] : '#007bff';
                                $html .= "<span style='background:{$color}; color:#fff; padding:2px 6px; border-radius:3px; font-size:0.8em; margin-right:10px;'><a href='{$actionUrl}?category={$c['id']}' style='color:#fff;text-decoration:none;'>".htmlspecialchars($c['name'])."</a></span>";
                                break;
                            }
                        }
                    }
                    $html .= "</div>";
                    $html .= "<a href='{$baseUrl}blog/" . htmlspecialchars($blog['slug'] ?? '') . "' style='font-size:1.2em; font-weight:bold; text-decoration:none; color:#0056b3;'>" . htmlspecialchars($blog['title']) . "</a>";
                    
                    if (!empty($settings['blog_tag_enabled']) && !empty($blog['tags'])) {
                        $html .= "<div style='margin-top:8px; font-size:0.85em; color:#666;'>";
                        foreach ($blog['tags'] as $t) $html .= "<a href='{$actionUrl}?tag=".urlencode($t)."' style='display:inline-block; background:#e2e3e5; padding:2px 8px; border-radius:10px; text-decoration:none; color:#333; margin-right:5px;'>#".htmlspecialchars($t)."</a>";
                        $html .= "</div>";
                    }
                    $html .= "</li>";
                }
                $html .= "</ul>";

                if ($maxPage > 1) {
                    $html .= "<div style='margin-top:30px; display:flex; gap:5px; justify-content:center;'>";
                    for ($i = 1; $i <= $maxPage; $i++) {
                        $qStr = http_build_query(['q'=>$q, 'category'=>$catId, 'tag'=>$tag, 'month'=>$month, 'sort'=>$sort, 'order'=>$order, 'p'=>$i]);
                        if ($i === $p) $html .= "<span style='padding:8px 12px; background:#007bff; color:#fff; border-radius:3px;'>{$i}</span>";
                        else $html .= "<a href='{$actionUrl}?{$qStr}' style='padding:8px 12px; background:#e9ecef; text-decoration:none; color:#333; border-radius:3px;'>{$i}</a>";
                    }
                    $html .= "</div>";
                }
            }
            $text = str_replace('{{blog_main_list}}', $html, $text);
        }

        $text = preg_replace_callback('/\{\{blogs\s*(.*?)\}\}/', function($m) use ($baseUrl, $settings, $dateField, $categories, $actionUrl) {
            $attrStr = trim($m[1]);
            $args = [];
            if (preg_match_all('/(\w+)=([^\s]+)/', $attrStr, $matches, PREG_SET_ORDER)) {
                foreach($matches as $match) $args[$match[1]] = $match[2];
            }

            $limit = isset($args['limit']) ? (int)$args['limit'] : 5;
            $catId = $args['category'] ?? '';
            $tag = $args['tag'] ?? '';
            $order = $args['order'] ?? 'desc';
            $sort = $args['sort'] ?? 'date';
            $showArchive = isset($args['archive']) && $args['archive'] === 'true';
            
            $blogs = array_filter($this->contentModel->getAll(), function($c) { return $c['type'] === 'blog'; });
            
            $filteredBlogs = [];
            foreach ($blogs as $b) {
                if ($catId && ($b['category_id'] ?? '') !== $catId) continue;
                if ($tag && (!isset($b['tags']) || !is_array($b['tags']) || !in_array($tag, $b['tags']))) continue;
                $filteredBlogs[] = $b;
            }

            usort($filteredBlogs, function($a, $b) use ($sort, $order, $dateField) {
                $aDate = !empty($a[$dateField]) ? $a[$dateField] : ($a['updated_at'] ?? 'now');
                $bDate = !empty($b[$dateField]) ? $b[$dateField] : ($b['updated_at'] ?? 'now');
                $valA = strtotime($aDate);
                $valB = strtotime($bDate);
                if ($valA == $valB) return 0;
                if ($order === 'asc') return ($valA < $valB) ? -1 : 1;
                return ($valA > $valB) ? -1 : 1;
            });

            $pagedBlogs = array_slice($filteredBlogs, 0, $limit);
            if (empty($pagedBlogs)) return "<p>記事がありません。</p>";
            
            $html = "<ul class='latest-blogs' style='list-style:none; padding:0;'>";
            foreach ($pagedBlogs as $blog) {
                $bDate = !empty($blog[$dateField]) ? $blog[$dateField] : ($blog['updated_at'] ?? 'now');
                $dateStr = date('Y.m.d', strtotime($bDate));
                $html .= "<li style='margin-bottom:15px; padding-bottom:15px; border-bottom:1px solid #eee;'>";
                $html .= "<div style='margin-bottom:5px;'><span style='color:#666; font-size:0.9em; margin-right:10px;'>{$dateStr}</span> ";
                
                if (!empty($settings['blog_category_enabled']) && !empty($blog['category_id'])) {
                    foreach($categories as $c) {
                        if($c['id'] === $blog['category_id']) {
                            $color = !empty($c['color']) ? $c['color'] : '#007bff';
                            $html .= "<span style='background:{$color}; color:#fff; padding:2px 6px; border-radius:3px; font-size:0.8em; margin-right:10px;'><a href='{$actionUrl}?category={$c['id']}' style='color:#fff;text-decoration:none;'>".htmlspecialchars($c['name'])."</a></span>";
                            break;
                        }
                    }
                }
                $html .= "</div>";
                $html .= "<a href='{$baseUrl}blog/" . htmlspecialchars($blog['slug'] ?? '') . "' style='font-size:1.2em; font-weight:bold; text-decoration:none; color:#0056b3;'>" . htmlspecialchars($blog['title']) . "</a>";
                if (!empty($settings['blog_tag_enabled']) && !empty($blog['tags'])) {
                    $html .= "<div style='margin-top:8px; font-size:0.85em; color:#666;'>";
                    foreach ($blog['tags'] as $t) $html .= "<a href='{$actionUrl}?tag=".urlencode($t)."' style='display:inline-block; background:#e2e3e5; padding:2px 8px; border-radius:10px; text-decoration:none; color:#333; margin-right:5px;'>#".htmlspecialchars($t)."</a>";
                    $html .= "</div>";
                }
                $html .= "</li>";
            }
            $html .= "</ul>";

            if ($showArchive) {
                $olderBlogs = array_slice($filteredBlogs, $limit);
                if (!empty($olderBlogs)) {
                    $groupedOlder = [];
                    foreach ($olderBlogs as $b) {
                        $bDate = !empty($b[$dateField]) ? $b[$dateField] : ($b['updated_at'] ?? 'now');
                        $m = date('Y年n月', strtotime($bDate));
                        $groupedOlder[$m][] = $b;
                    }
                    $html .= "<div class='blog-archive-accordion'>";
                    foreach ($groupedOlder as $mName => $mBlogs) {
                        $html .= "<details style='margin-bottom:10px;'><summary style='cursor:pointer; font-weight:bold; background:#f8f9fa; padding:10px; border:1px solid #dee2e6;'>{$mName} (".count($mBlogs)."件)</summary>";
                        $html .= "<ul style='padding:10px 20px; border:1px solid #dee2e6; border-top:none; margin:0; list-style:none;'>";
                        foreach ($mBlogs as $blog) {
                            $bDate = !empty($blog[$dateField]) ? $blog[$dateField] : ($blog['updated_at'] ?? 'now');
                            $dateStr = date('Y.m.d', strtotime($bDate));
                            $html .= "<li style='margin-bottom:8px;'><span style='color:#666; font-size:0.9em; margin-right:10px;'>{$dateStr}</span> <a href='{$baseUrl}blog/" . htmlspecialchars($blog['slug'] ?? '') . "'>" . htmlspecialchars($blog['title']) . "</a></li>";
                        }
                        $html .= "</ul></details>";
                    }
                    $html .= "</div>";
                }
            }
            return $html;
        }, $text);

        return $text;
    }

    private function injectHeadTags($html, $pageMetaDesc = '', $pageTitle = '', $canonicalUrl = '', $customHead = '') {
        $settings = $this->getSettings();
        $defaultDesc = $settings['seo_description'] ?? '';
        $keywords = $settings['seo_keywords'] ?? '';
        $globalCustomHead = $settings['custom_head'] ?? '';

        $desc = trim($pageMetaDesc) !== '' ? trim($pageMetaDesc) : trim($defaultDesc);
        $tags = "";

        if ($pageTitle !== '') {
            $safeTitle = htmlspecialchars($pageTitle);
            if (preg_match('/<title>.*?<\/title>/is', $html)) {
                $html = preg_replace('/<title>.*?<\/title>/is', "<title>{$safeTitle}</title>", $html, 1);
            } else {
                $tags .= "<title>{$safeTitle}</title>\n";
            }
        }

        if ($canonicalUrl !== '') $tags .= "<link rel=\"canonical\" href=\"" . htmlspecialchars($canonicalUrl) . "\">\n";
        if ($desc !== '') $tags .= "<meta name=\"description\" content=\"" . htmlspecialchars($desc) . "\">\n";
        if (trim($keywords) !== '') $tags .= "<meta name=\"keywords\" content=\"" . htmlspecialchars(trim($keywords)) . "\">\n";
        if (trim($globalCustomHead) !== '') $tags .= trim($globalCustomHead) . "\n";
        if (trim($customHead) !== '') $tags .= trim($customHead) . "\n";

        if ($tags !== "") {
            if (stripos($html, '</head>') !== false) {
                $html = preg_replace('/<\/head>/i', $tags . '</head>', $html, 1);
            } else {
                $html .= $tags; 
            }
        }
        return $html;
    }

    private function renderErrorPage($code, $baseUrl, $defaultMessage, $adminHead = null) {
        http_response_code($code);
        $errorPage = $this->contentModel->getBySlug((string)$code, 'page');
        if ($errorPage) {
            $header = $this->templateModel->renderHeader($baseUrl);
            $header = $this->injectHeadTags($header, $errorPage['meta_description'] ?? '', $errorPage['title'] ?? "{$code} Error", '', $errorPage['custom_head'] ?? '');
            $header = $this->replaceVariables($header, $baseUrl);
            echo $header;
            echo "<main>" . $this->replaceVariables($errorPage['body'], $baseUrl) . "</main>";
            $footer = $this->templateModel->renderFooter();
            if (!empty($errorPage['custom_bottom'])) {
                if (stripos($footer, '</body>') !== false) {
                    $footer = str_ireplace('</body>', $errorPage['custom_bottom'] . "\n</body>", $footer);
                } else {
                    $footer .= "\n" . $errorPage['custom_bottom'];
                }
            }
            echo $this->replaceVariables($footer, $baseUrl);
        } else {
            if ($adminHead) echo $adminHead . "<h1>{$code} Error</h1><p>{$defaultMessage}</p></main></body></html>";
            else echo "<h1>{$code} Error</h1><p>{$defaultMessage}</p>";
        }
        exit;
    }

    private function processSuccessfulLogin($user, $baseUrl, $settings) {
        $trustDays = (int)($settings['2fa_trust_days'] ?? 0);
        if ($trustDays > 0 && !empty($_POST['trust_device'])) {
            $token = bin2hex(random_bytes(32));
            $expires = time() + (86400 * $trustDays);
            setcookie('cms_2fa_trust_' . $user['id'], $token, $expires, '/', '', false, true);
            $user['trusted_devices'] = $user['trusted_devices'] ?? [];
            $user['trusted_devices'][] = ['token' => password_hash($token, PASSWORD_DEFAULT), 'expires' => $expires];
            $this->userModel->save($user);
        }

        $this->auth->completeLogin($user);
        if (isset($_SESSION['expired_user_id']) && $_SESSION['expired_user_id'] !== $user['id']) unset($_SESSION['recovery_post']);
        unset($_SESSION['expired_user_id']);
        $redirect = $_SESSION['redirect_url'] ?? "{$baseUrl}dashboard";
        unset($_SESSION['redirect_url']);
        header("Location: " . $redirect); exit;
    }

    private function getAdminHead($baseUrl, $currentUser, $isAdminOrSpecial) {
        $head = <<<HTML
<!DOCTYPE html>
<html lang="ja"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>CMS管理画面</title>
<style>
    :root { --bg: #f4f6f9; --nav-bg: #343a40; --nav-text: #c2c7d0; --primary: #007bff; --border: #dee2e6; }
    body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; margin: 0; display: flex; min-height: 100vh; background: var(--bg); color: #333; }
    .sidebar { width: 250px; background: var(--nav-bg); color: #fff; padding-top: 20px; flex-shrink: 0; }
    .sidebar h2 { font-size: 1.2rem; margin: 0 20px 20px; color: #fff; border-bottom: 1px solid #4f5962; padding-bottom: 10px; }
    .sidebar nav ul { list-style: none; padding: 0; margin: 0; }
    .sidebar nav li a { display: block; padding: 12px 20px; color: var(--nav-text); text-decoration: none; transition: 0.2s; }
    .sidebar nav li a:hover { background: rgba(255,255,255,0.1); color: #fff; }
    .main-content { flex: 1; padding: 30px; overflow-y: auto; background: #fff; box-shadow: inset 1px 0 0 rgba(0,0,0,0.1); }
    h1 { margin-top: 0; font-size: 1.8rem; border-bottom: 2px solid var(--primary); padding-bottom: 10px; margin-bottom: 20px; }
    table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
    th, td { border: 1px solid var(--border); padding: 12px; text-align: left; }
    th { background: #f8f9fa; }
    input[type="text"], input[type="password"], select, textarea, input[type="email"], input[type="number"], input[type="color"] { width: 100%; padding: 10px; margin-top: 5px; margin-bottom: 15px; border: 1px solid #ced4da; border-radius: 4px; box-sizing: border-box; font-family: inherit; }
    button, .btn { display: inline-block; background: var(--primary); color: #fff; text-decoration: none; padding: 10px 20px; border: none; border-radius: 4px; cursor: pointer; font-size: 1rem; }
    button:hover, .btn:hover { background: #0056b3; }
    .tree-indent { display: inline-block; width: 20px; color: #adb5bd; }
    fieldset { border: 1px solid var(--border); padding: 20px; margin-bottom: 20px; border-radius: 4px; background: #fff; }
    legend { font-weight: bold; padding: 0 10px; background: #fff; }
    .alert { padding: 10px; background: #d4edda; color: #155724; border: 1px solid #c3e6cb; border-radius: 4px; margin-bottom: 15px; }
    .alert-error { background: #f8d7da; color: #721c24; border-color: #f5c6cb; }
</style></head><body>
<aside class="sidebar" aria-label="管理メニュー"><h2>CMS Menu</h2><nav><ul>
    <li><a href="{$baseUrl}dashboard">ダッシュボード</a></li>
    <li><a href="{$baseUrl}cms/contents">記事・ページ管理</a></li>
    <li><a href="{$baseUrl}cms/uploads">メディア(ファイル)管理</a></li>
HTML;
        if ($isAdminOrSpecial) {
            $head .= "<li><a href='{$baseUrl}cms/categories'>カテゴリ管理</a></li>";
            $head .= "<li><a href='{$baseUrl}cms/templates'>システム設定・デザイン</a></li>";
        }
        if ($currentUser['role'] === 'admin') {
            $head .= "<li><a href='{$baseUrl}cms/users'>ユーザー管理</a></li>";
            $head .= "<li><a href='{$baseUrl}cms/settings/mail'>メール送信設定</a></li>";
            $head .= "<li><a href='{$baseUrl}cms/settings/2fa'>二段階認証(2FA)設定</a></li>";
            $head .= "<li><a href='{$baseUrl}cms/backups'>復元と入出力</a></li>";
            $head .= "<li><a href='{$baseUrl}cms/logs'>システムログ</a></li>";
        }
        $head .= <<<HTML
    <li><a href="{$baseUrl}cms/profile">プロフィール設定</a></li>
    <li><a href="{$baseUrl}cms/2fa">マイTOTP設定</a></li>
    <li><a href="{$baseUrl}" target="_blank">サイトを確認 ↗</a></li>
    <li><a href="{$baseUrl}logout">ログアウト</a></li>
</ul></nav></aside><main class="main-content">
HTML;
        return $head;
    }

    public function dispatch($path) {
        $method = $_SERVER['REQUEST_METHOD'];
        $baseUrl = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
        $baseUrl = $baseUrl === '' ? '/' : $baseUrl . '/';
        $settings = $this->getSettings();
        $dateField = $settings['blog_date_type'] ?? 'updated_at';

        $requestUriPath = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
        $hasTrailingSlash = (substr($requestUriPath, -1) === '/');
        $isHome = ($requestUriPath === '/' || rtrim($requestUriPath, '/') === rtrim($baseUrl, '/'));
        $isSystemPath = (strpos($path, 'cms/') === 0 || strpos($path, 'login') === 0 || $path === 'logout' || $path === 'dashboard' || strpos($path, 'assets/') === 0);

        $cleanPath = rtrim($path, '/');
        if ($cleanPath === '') $cleanPath = 'index'; 

        $policy = 'as_is';
        $pageArticle = null;

        if (!$isSystemPath && !$isHome && strpos($cleanPath, 'uploads/') !== 0) {
            if (strpos($cleanPath, 'blog/') === 0 || $cleanPath === 'blogs' || $cleanPath === 'search') {
                $policy = $settings['blog_slash_policy'] ?? 'as_is';
            } else {
                $pageArticle = $this->contentModel->getBySlug($cleanPath, 'page');
                if ($pageArticle) {
                    $pagePolicy = $pageArticle['slash_policy'] ?? 'default';
                    $policy = ($pagePolicy === 'default' || $pagePolicy === '') ? ($settings['page_slash_policy'] ?? 'as_is') : $pagePolicy;
                } else {
                    $policy = $settings['page_slash_policy'] ?? 'as_is';
                }
            }
        }

        if ($method === 'GET' && !$isHome && !$isSystemPath && strpos($cleanPath, 'uploads/') !== 0) {
            if ($policy === 'none' && $hasTrailingSlash) {
                $redirectUrl = rtrim($requestUriPath, '/');
                $query = $_SERVER['QUERY_STRING'] ?? '';
                if ($query) $redirectUrl .= '?' . $query;
                header("Location: " . $redirectUrl, true, 301); exit;
            } elseif ($policy === 'slash' && !$hasTrailingSlash) {
                $redirectUrl = $requestUriPath . '/';
                $query = $_SERVER['QUERY_STRING'] ?? '';
                if ($query) $redirectUrl .= '?' . $query;
                header("Location: " . $redirectUrl, true, 301); exit;
            }
        }

        $canonicalUrl = '';
        if (!$isSystemPath && strpos($cleanPath, 'uploads/') !== 0) {
            $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
            $absoluteBaseUrl = rtrim($protocol . $_SERVER['HTTP_HOST'] . $baseUrl, '/');
            if ($isHome || $cleanPath === 'index') {
                $canonicalUrl = $absoluteBaseUrl . '/'; 
            } else {
                $basePathStr = $absoluteBaseUrl . '/' . ltrim($cleanPath, '/');
                $canonicalUrl = ($policy === 'slash') ? $basePathStr . '/' : $basePathStr;
            }
        }

        if ($path === 'assets/style.css') {
            header('Content-Type: text/css; charset=utf-8');
            echo $this->replaceVariables($this->templateModel->get('style.css'), $baseUrl); return;
        }

        // ==========================================
        // メディア (ファイル) の公開アクセス
        // ==========================================
        if (preg_match('#^uploads/(.+)$#', $cleanPath, $matches)) {
            $filename = basename($matches[1]);
            $filepath = __DIR__ . '/../data/uploads/' . $filename;
            if (file_exists($filepath)) {
                $ext = strtolower(pathinfo($filepath, PATHINFO_EXTENSION));
                $mimes = [
                    'jpg'=>'image/jpeg', 'jpeg'=>'image/jpeg', 'png'=>'image/png', 'gif'=>'image/gif',
                    'webp'=>'image/webp', 'svg'=>'image/svg+xml', 'pdf'=>'application/pdf',
                    'zip'=>'application/zip', 'txt'=>'text/plain'
                ];
                $mime = $mimes[$ext] ?? 'application/octet-stream';
                header("Content-Type: $mime");
                header("Content-Length: " . filesize($filepath));
                readfile($filepath);
                return;
            }
            $this->renderErrorPage(404, $baseUrl, "ファイルが見つかりません。");
        }

        // ==========================================
        // 公開エリア（フロントエンド）
        // ==========================================
        if ($cleanPath === 'index' || $isHome) {
            $indexPage = $this->contentModel->getBySlug('index', 'page');
            if ($indexPage) {
                if (!empty($indexPage['redirect_url'])) {
                    header("Location: " . $indexPage['redirect_url'], true, 301);
                    exit;
                }
                
                $header = $this->templateModel->renderHeader($baseUrl);
                $header = $this->injectHeadTags($header, $indexPage['meta_description'] ?? '', $indexPage['title'] ?? '', $canonicalUrl, $indexPage['custom_head'] ?? '');
                $header = $this->replaceVariables($header, $baseUrl);
                echo $header;
                echo "<main>" . $this->replaceVariables($indexPage['body'], $baseUrl) . "</main>";
                
                $footer = $this->templateModel->renderFooter();
                if (!empty($indexPage['custom_bottom'])) {
                    if (stripos($footer, '</body>') !== false) {
                        $footer = str_ireplace('</body>', $indexPage['custom_bottom'] . "\n</body>", $footer);
                    } else {
                        $footer .= "\n" . $indexPage['custom_bottom'];
                    }
                }
                echo $this->replaceVariables($footer, $baseUrl);
                return;
            }
            $cleanPath = 'blogs'; 
        }

        if ($cleanPath === 'search') {
            if (empty($settings['site_search_enabled'])) $this->renderErrorPage(404, $baseUrl, "検索機能は無効です。");
            $q = trim($_GET['q'] ?? '');
            $p = max(1, (int)($_GET['p'] ?? 1));
            $sort = $_GET['sort'] ?? ($q !== '' ? 'score' : 'date');
            $order = $_GET['order'] ?? 'desc';
            
            $results = [];
            if ($q !== '') {
                $allContents = $this->contentModel->getAll();
                foreach ($allContents as $c) {
                    $c['score'] = 0;
                    $searchStrTitle = mb_strtolower($c['title']);
                    $searchStrBody = mb_strtolower($c['body']);
                    $qLower = mb_strtolower($q);
                    
                    $countTitle = mb_substr_count($searchStrTitle, $qLower);
                    $countBody = mb_substr_count($searchStrBody, $qLower);
                    if ($countTitle === 0 && $countBody === 0) continue;
                    
                    $c['score'] = $countTitle * 10 + $countBody;
                    $results[] = $c;
                }
            }

            usort($results, function($a, $b) use ($sort, $order, $dateField) {
                if ($sort === 'score') {
                    $valA = $a['score'];
                    $valB = $b['score'];
                } else {
                    $aDate = !empty($a[$dateField]) ? $a[$dateField] : ($a['updated_at'] ?? 'now');
                    $bDate = !empty($b[$dateField]) ? $b[$dateField] : ($b['updated_at'] ?? 'now');
                    $valA = strtotime($aDate);
                    $valB = strtotime($bDate);
                }
                if ($valA == $valB) return 0;
                if ($order === 'asc') return ($valA < $valB) ? -1 : 1;
                return ($valA > $valB) ? -1 : 1;
            });

            $perPage = 10;
            $total = count($results);
            $maxPage = max(1, ceil($total / $perPage));
            if ($p > $maxPage) $p = $maxPage;
            $pagedResults = array_slice($results, ($p - 1) * $perPage, $perPage);
            
            $header = $this->templateModel->renderHeader($baseUrl);
            $header = $this->injectHeadTags($header, '', '検索結果: ' . $q, $canonicalUrl);
            $header = $this->replaceVariables($header, $baseUrl);
            echo $header;
            
            echo "<main><h1>サイト内検索</h1>";
            echo "<form action='{$baseUrl}search' method='GET' style='margin-bottom:20px; display:flex; max-width:500px;'><input type='text' name='q' value='".htmlspecialchars($q)."' placeholder='キーワードを入力...' style='padding:8px; flex:1;'><button type='submit' style='padding:8px 15px; cursor:pointer;'>検索</button></form>";
            
            if ($q === '') {
                echo "<p>検索キーワードを入力してください。</p>";
            } elseif (empty($results)) {
                echo "<p>「".htmlspecialchars($q)."」に一致するページは見つかりませんでした。</p>";
            } else {
                echo "<form method='GET' style='display:flex; justify-content:space-between; align-items:center; margin-bottom: 20px; background:#f8f9fa; padding:10px; border-radius:4px;'>";
                echo "<input type='hidden' name='q' value='".htmlspecialchars($q)."'>";
                echo "<div>全 {$total} 件中 ".(($p-1)*$perPage+1)." - ".min($total, $p*$perPage)." 件を表示</div>";
                echo "<div><select name='sort' onchange='this.form.submit()'><option value='score' ".($sort==='score'?'selected':'').">一致度順</option><option value='date' ".($sort==='date'?'selected':'').">日付順</option></select> ";
                echo "<select name='order' onchange='this.form.submit()'><option value='desc' ".($order==='desc'?'selected':'').">降順</option><option value='asc' ".($order==='asc'?'selected':'').">昇順</option></select></div></form>";

                echo "<ul style='list-style:none; padding:0;'>";
                foreach ($pagedResults as $r) {
                    $url = $r['type'] === 'blog' ? "{$baseUrl}blog/" . htmlspecialchars($r['slug']) : "{$baseUrl}" . ltrim($r['slug'], '/');
                    $typeLabel = $r['type'] === 'blog' ? '[ブログ]' : '[ページ]';
                    echo "<li style='margin-bottom:15px; border-bottom:1px solid #eee; padding-bottom:10px;'><span style='color:#666; font-size:0.9em; margin-right:10px;'>{$typeLabel}</span> <a href='{$url}' style='font-size:1.1em;'>".htmlspecialchars($r['title'])."</a></li>";
                }
                echo "</ul>";

                if ($maxPage > 1) {
                    echo "<div style='margin-top:20px; display:flex; gap:5px; justify-content:center;'>";
                    for ($i = 1; $i <= $maxPage; $i++) {
                        $qStr = http_build_query(['q'=>$q, 'sort'=>$sort, 'order'=>$order, 'p'=>$i]);
                        if ($i === $p) echo "<span style='padding:5px 10px; background:#007bff; color:#fff; border-radius:3px;'>{$i}</span>";
                        else echo "<a href='{$baseUrl}search?{$qStr}' style='padding:5px 10px; background:#e9ecef; text-decoration:none; color:#333; border-radius:3px;'>{$i}</a>";
                    }
                    echo "</div>";
                }
            }
            echo "</main>";
            $footer = $this->templateModel->renderFooter();
            echo $this->replaceVariables($footer, $baseUrl);
            return;
        }

        if ($cleanPath === 'blogs') {
            $header = $this->templateModel->renderHeader($baseUrl);
            $header = $this->injectHeadTags($header, '', 'ブログ一覧', $canonicalUrl); 
            $header = $this->replaceVariables($header, $baseUrl);
            echo $header;
            
            $layout = <<<HTML
<style>
.blogs-layout { display:flex; gap:20px; flex-wrap:wrap; }
.blogs-main   { flex:1 1 60%; }
.blogs-side   { flex:1 1 30%; min-width:250px; }
.side-card { background:#f8f9fa; padding:15px; border-radius:4px; margin-bottom:20px; }
.blog-categories-list, .blog-archives-list { list-style:none; padding:0 !important; margin:0 !important; }
.blog-tags-list { display:flex !important; flex-wrap:wrap !important; gap:5px !important; }
</style>
<div class="blogs-layout">
  <div class="blogs-main">
    <h1 style="margin-bottom:20px; margin-top:0;">ブログ一覧</h1>
    {{blog_main_list}}
  </div>
  <aside class="blogs-side">
    <div class="side-card"><h3 style="margin-top:0;">ブログ検索</h3>{{blog_search_form}}</div>
    <div class="side-card"><h3 style="margin-top:0;">カテゴリ</h3>{{blog_categories}}</div>
    <div class="side-card"><h3 style="margin-top:0;">タグ</h3>{{blog_tags}}</div>
    <div class="side-card"><h3 style="margin-top:0;">月別アーカイブ</h3>{{blog_archives}}</div>
  </aside>
</div>
HTML;
            echo "<main>" . $this->replaceVariables($layout, $baseUrl) . "</main>";
            $footer = $this->templateModel->renderFooter();
            echo $this->replaceVariables($footer, $baseUrl);
            return;
        }

        if (preg_match('#^blog/([^/]+)$#', $cleanPath, $matches)) {
            $article = $this->contentModel->getBySlug($matches[1], 'blog');
            if (!$article) $this->renderErrorPage(404, $baseUrl, "お探しの記事は見つかりませんでした。");

            $allBlogs = array_filter($this->contentModel->getAll(), function($c) { return $c['type'] === 'blog'; });
            usort($allBlogs, function($a, $b) use ($dateField) {
                $aDate = !empty($a[$dateField]) ? $a[$dateField] : ($a['updated_at'] ?? 'now');
                $bDate = !empty($b[$dateField]) ? $b[$dateField] : ($b['updated_at'] ?? 'now');
                return strtotime($bDate) <=> strtotime($aDate);
            });
            
            $prev = null; $next = null;
            $allBlogsValues = array_values($allBlogs);
            for ($i = 0; $i < count($allBlogsValues); $i++) {
                if ($allBlogsValues[$i]['id'] === $article['id']) {
                    if ($i < count($allBlogsValues) - 1) $prev = $allBlogsValues[$i + 1];
                    if ($i > 0) $next = $allBlogsValues[$i - 1];
                    break;
                }
            }

            $aDateRaw = !empty($article[$dateField]) ? $article[$dateField] : ($article['updated_at'] ?? 'now');
            $cDate = date('Y.m.d H:i', strtotime($aDateRaw));
            
            $realUpdated = date('Y.m.d H:i', strtotime($article['updated_at'] ?? 'now'));
            $upText = ($cDate !== $realUpdated && $dateField === 'created_at') ? "<span style='margin-left:10px;'>(更新日: <time datetime='".htmlspecialchars($article['updated_at'])."'>{$realUpdated}</time>)</span>" : "";

            $catHtml = '';
            if (!empty($settings['blog_category_enabled']) && !empty($article['category_id'])) {
                $categories = $this->contentModel->getCategories();
                foreach($categories as $c) {
                    if($c['id'] === $article['category_id']) {
                        $color = !empty($c['color']) ? $c['color'] : '#007bff';
                        $catHtml = "<span style='background:{$color}; color:#fff; padding:2px 6px; border-radius:3px; font-size:0.8em; margin-left:10px;'><a href='{$baseUrl}blogs?category={$c['id']}' style='color:#fff;text-decoration:none;'>".htmlspecialchars($c['name'])."</a></span>";
                        break;
                    }
                }
            }
            
            $tagsHtml = '';
            if (!empty($settings['blog_tag_enabled']) && !empty($article['tags'])) {
                foreach ($article['tags'] as $t) {
                    $tagsHtml .= " <a href='{$baseUrl}blogs?tag=".urlencode($t)."' style='display:inline-block; background:#e2e3e5; padding:2px 8px; border-radius:10px; text-decoration:none; color:#333; margin-left:5px; font-size:0.9em;'>#".htmlspecialchars($t)."</a>";
                }
            }

            $blogLayout = $settings['blog_layout'] ?? '';
            if (trim($blogLayout) === '') {
                $blogLayout = "<main><article><h1 style='margin-bottom: 5px;'>{{title}}</h1><div style='color:#666; font-size:0.9em; margin-bottom:20px; border-bottom:1px solid #ccc; padding-bottom:10px;'>作成日: <time datetime='{{created_at}}'>{{created_at_date}}</time> {{updated_at_text}} {{category_html}} {{tags_html}}</div><div id='md-content'></div></article><div style='display:flex; justify-content:space-between; margin-top:30px; padding-top:20px; border-top:1px solid #eee;'><div>{{if_prev}}<a href='{{prev_url}}'>&laquo; {{prev_title}}</a>{{/if_prev}}</div><div>{{if_next}}<a href='{{next_url}}'>{{next_title}} &raquo;</a>{{/if_next}}</div></div></main>";
            }

            if ($prev) {
                $blogLayout = preg_replace('/\{\{if_prev\}\}(.*?)\{\{\/if_prev\}\}/is', '$1', $blogLayout);
                $blogLayout = str_replace('{{prev_url}}', "{$baseUrl}blog/".htmlspecialchars($prev['slug']), $blogLayout);
                $blogLayout = str_replace('{{prev_title}}', htmlspecialchars($prev['title']), $blogLayout);
            } else {
                $blogLayout = preg_replace('/\{\{if_prev\}\}(.*?)\{\{\/if_prev\}\}/is', '', $blogLayout);
            }

            if ($next) {
                $blogLayout = preg_replace('/\{\{if_next\}\}(.*?)\{\{\/if_next\}\}/is', '$1', $blogLayout);
                $blogLayout = str_replace('{{next_url}}', "{$baseUrl}blog/".htmlspecialchars($next['slug']), $blogLayout);
                $blogLayout = str_replace('{{next_title}}', htmlspecialchars($next['title']), $blogLayout);
            } else {
                $blogLayout = preg_replace('/\{\{if_next\}\}(.*?)\{\{\/if_next\}\}/is', '', $blogLayout);
            }

            $replacePairs = [
                '{{title}}' => htmlspecialchars($article['title']),
                '{{created_at}}' => htmlspecialchars($aDateRaw),
                '{{created_at_date}}' => $cDate,
                '{{updated_at_text}}' => $upText,
                '{{category_html}}' => $catHtml,
                '{{tags_html}}' => $tagsHtml
            ];
            foreach ($replacePairs as $k => $v) $blogLayout = str_replace($k, $v, $blogLayout);

            $blogFormat = $settings['blog_title_format'] ?? '{{title}}';
            $customTitle = str_replace('{{title}}', $article['title'] ?? '', $blogFormat);
            $blogLayout = $this->replaceVariables($blogLayout, $baseUrl);
            
            $safeBodyJson = json_encode($article['body'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
            
            $header = $this->templateModel->renderHeader($baseUrl);
            $header = $this->injectHeadTags($header, '', $customTitle, $canonicalUrl);
            $header = $this->replaceVariables($header, $baseUrl);
            echo $header;
            
            echo $blogLayout;

            echo "<script src='https://cdn.jsdelivr.net/npm/marked/marked.min.js'></script>";
            echo "<script src='https://cdnjs.cloudflare.com/ajax/libs/dompurify/3.0.6/purify.min.js'></script>";
            echo "<script>document.addEventListener('DOMContentLoaded', () => { const md = document.getElementById('md-content'); if(md) md.innerHTML = DOMPurify.sanitize(marked.parse({$safeBodyJson}), { ADD_ATTR: ['style', 'class', 'target', 'width', 'height', 'align', 'color'] }); });</script>";
            
            $footer = $this->templateModel->renderFooter();
            echo $this->replaceVariables($footer, $baseUrl);
            return;
        }

        // ==========================================
        // 3. ログイン・二段階認証 (2FA) 処理
        // ==========================================
        if ($path === 'login') {
            if ($this->auth->isLoggedIn()) { 
                $redirect = $_SESSION['redirect_url'] ?? "{$baseUrl}dashboard";
                unset($_SESSION['redirect_url']);
                header("Location: " . $redirect); exit; 
            }
            
            $error = '';
            if (isset($_SESSION['timeout_message'])) {
                $error = "長期間操作がなかったため、自動的にログアウトしました。再度ログインしてください。";
                unset($_SESSION['timeout_message']);
            }

            if ($method === 'POST') {
                $loginResult = $this->auth->login($_POST['student_id'] ?? '', $_POST['password'] ?? '');
                
                if ($loginResult === true) { 
                    $this->writeLog($this->auth->getCurrentUser(), 'Login', 'Success');
                    $user = $this->userModel->findByStudentId($_POST['student_id']);
                    $this->processSuccessfulLogin($user, $baseUrl, $settings);
                } elseif ($loginResult === 'requires_totp') {
                    header("Location: {$baseUrl}login/totp"); exit;
                } elseif ($loginResult === 'requires_email') {
                    $user = $this->userModel->findById($_SESSION['pending_2fa_user_id']);
                    if (empty($user['email'])) {
                        $error = "メールアドレスが未登録のため二段階認証が実行できません。管理者に連絡してください。";
                        unset($_SESSION['pending_2fa_user_id']);
                    } else {
                        $code = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
                        $user['email_verify_code'] = password_hash($code, PASSWORD_DEFAULT);
                        $user['email_verify_expires'] = time() + 600; 
                        $this->userModel->save($user);

                        $mailer = new Mailer($settings);
                        $mailer->send($user['email'], "ログイン確認コード", "ログインするための確認コードです。\n\nコード：{$code}\n\n※このコードは10分間有効です。");
                        header("Location: {$baseUrl}login/email"); exit;
                    }
                } elseif ($loginResult === 'locked') {
                    $this->writeLog(null, 'Login Failed', "Locked ID: {$_POST['student_id']}");
                    $error = "このアカウントは一時停止されています。管理者にお問い合わせください。";
                } elseif ($loginResult === 'blocked_60') {
                    $error = "ログイン試行回数が上限を超えました。60分後に再度お試しください。";
                } elseif ($loginResult === 'blocked_15') {
                    $error = "ログイン試行回数が上限を超えました。15分後に再度お試しください。";
                } else { 
                    $this->writeLog(null, 'Login Failed', "ID: {$_POST['student_id']}");
                    $error = "IDまたはパスワードが違います。"; 
                }
            }
            echo "<!DOCTYPE html><html lang='ja'><head><meta charset='UTF-8'><title>ログイン | CMS</title><style>body{font-family:sans-serif;background:#f4f4f4;display:flex;justify-content:center;align-items:center;height:100vh;margin:0;}main{background:#fff;padding:30px;border-radius:8px;box-shadow:0 0 10px rgba(0,0,0,0.1);}</style></head><body>";
            echo "<main><form method='POST'><fieldset style='border:none; padding:0;'><legend><h1>部員用ログイン</h1></legend>";
            if ($error) echo "<p role='alert' style='color:red;'>$error</p>";
            echo "<label for='student_id'>学籍番号/ID:</label><br><input type='text' id='student_id' name='student_id' required aria-required='true' style='width:100%;margin-bottom:15px;padding:8px;'><br>";
            echo "<label for='password'>パスワード:</label><br><input type='password' id='password' name='password' required aria-required='true' style='width:100%;margin-bottom:15px;padding:8px;'><br>";
            echo "<button type='submit' style='width:100%;padding:10px;background:#0056b3;color:#fff;border:none;border-radius:4px;'>次へ</button>";
            echo "</fieldset></form></main></body></html>";
            return;
        }

        if ($path === 'login/totp') {
            if (empty($_SESSION['pending_2fa_user_id'])) { header("Location: {$baseUrl}login"); exit; }
            $user = $this->userModel->findById($_SESSION['pending_2fa_user_id']);
            $error = '';
            
            if ($method === 'POST') {
                if (isset($_POST['action']) && $_POST['action'] === 'use_email') {
                    if (empty($user['email'])) {
                        $error = "メールアドレスが登録されていないため、メール認証は利用できません。";
                    } else {
                        $code = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
                        $user['email_verify_code'] = password_hash($code, PASSWORD_DEFAULT);
                        $user['email_verify_expires'] = time() + 600; 
                        $this->userModel->save($user);

                        $mailer = new Mailer($settings);
                        $mailer->send($user['email'], "ログイン確認コード", "ログインするための確認コードです。\n\nコード：{$code}\n\n※このコードは10分間有効です。");
                        header("Location: {$baseUrl}login/email"); exit;
                    }
                } else {
                    $code = preg_replace('/[^0-9A-Za-z]/', '', $_POST['code'] ?? '');
                    $verified = false;
                    
                    if (is_numeric($code) && strlen($code) === 6) {
                        if (TOTP::verify($user['totp_secret'], $code)) $verified = true;
                    } else {
                        foreach ($user['backup_codes'] as $idx => $hash) {
                            if (password_verify(strtoupper($code), $hash)) {
                                $verified = true;
                                unset($user['backup_codes'][$idx]);
                                $this->userModel->save($user);
                                break;
                            }
                        }
                    }

                    if ($verified) {
                        $this->writeLog($user, '2FA TOTP Login', 'Success');
                        $this->processSuccessfulLogin($user, $baseUrl, $settings);
                    } else {
                        $error = "認証コードまたはバックアップコードが正しくありません。";
                    }
                }
            }
            echo "<!DOCTYPE html><html lang='ja'><head><meta charset='UTF-8'><title>二段階認証 | CMS</title><style>body{font-family:sans-serif;background:#f4f4f4;display:flex;justify-content:center;align-items:center;height:100vh;margin:0;}main{background:#fff;padding:30px;border-radius:8px;box-shadow:0 0 10px rgba(0,0,0,0.1);}</style></head><body>";
            echo "<main><form method='POST'><fieldset style='border:none; padding:0;'><legend><h1>二段階認証 (TOTP)</h1></legend>";
            if ($error) echo "<p role='alert' style='color:red;'>$error</p>";
            echo "<p>認証アプリの6桁のコード、またはバックアップコードを入力してください。</p>";
            echo "<input type='text' name='code' required autocomplete='off' style='width:100%;margin-bottom:15px;padding:8px;font-size:1.2em;text-align:center;letter-spacing:2px;'><br>";
            
            if (!empty($settings['2fa_trust_days'])) {
                $days = (int)$settings['2fa_trust_days'];
                echo "<label style='display:block;margin-bottom:15px;color:#555;'><input type='checkbox' name='trust_device' value='1'> このデバイスを {$days} 日間記憶する</label>";
            }

            echo "<button type='submit' style='width:100%;padding:10px;background:#0056b3;color:#fff;border:none;border-radius:4px;'>認証する</button>";
            echo "</fieldset></form>";
            
            $mode = $settings['2fa_mode'] ?? 'none';
            if (in_array($mode, ['email_totp_optional', 'email_totp_required'])) {
                echo "<form method='POST' style='margin-top:20px;text-align:center;'><input type='hidden' name='action' value='use_email'><button type='submit' style='background:none;border:none;color:#0056b3;text-decoration:underline;cursor:pointer;font-size:1em;padding:0;'>メール認証を使用する</button></form>";
            }
            echo "<p style='text-align:center;margin-top:20px;'><a href='{$baseUrl}login'>キャンセルして戻る</a></p></main></body></html>";
            return;
        }

        if ($path === 'login/email') {
            if (empty($_SESSION['pending_2fa_user_id'])) { header("Location: {$baseUrl}login"); exit; }
            $user = $this->userModel->findById($_SESSION['pending_2fa_user_id']);
            $error = '';
            if ($method === 'POST') {
                if (time() > $user['email_verify_expires']) {
                    $error = "コードの有効期限が切れています。もう一度ログイン画面からやり直してください。";
                } elseif (password_verify($_POST['code'] ?? '', $user['email_verify_code'])) {
                    $user['email_verify_code'] = null; $user['email_verify_expires'] = null;
                    $this->userModel->save($user);
                    
                    $mode = $settings['2fa_mode'] ?? 'none';
                    if ($mode === 'email_totp_required' && empty($user['totp_secret'])) {
                        $_SESSION['setup_totp_allowed'] = true;
                        $_SESSION['pending_trust_device'] = !empty($_POST['trust_device']);
                        header("Location: {$baseUrl}login/setup_totp"); exit;
                    } else {
                        $this->writeLog($user, '2FA Email Login', 'Success');
                        $this->processSuccessfulLogin($user, $baseUrl, $settings);
                    }
                } else {
                    $error = "確認コードが正しくありません。";
                }
            }
            echo "<!DOCTYPE html><html lang='ja'><head><meta charset='UTF-8'><title>メール認証 | CMS</title><style>body{font-family:sans-serif;background:#f4f4f4;display:flex;justify-content:center;align-items:center;height:100vh;margin:0;}main{background:#fff;padding:30px;border-radius:8px;box-shadow:0 0 10px rgba(0,0,0,0.1);}</style></head><body>";
            echo "<main><form method='POST'><fieldset style='border:none; padding:0;'><legend><h1>メール認証</h1></legend>";
            if ($error) echo "<p role='alert' style='color:red;'>$error</p>";
            echo "<p>登録メールアドレスに送信された6桁の確認コードを入力してください。</p>";
            echo "<input type='text' name='code' required autocomplete='off' style='width:100%;margin-bottom:15px;padding:8px;font-size:1.2em;text-align:center;letter-spacing:2px;'><br>";
            
            if (!empty($settings['2fa_trust_days'])) {
                $days = (int)$settings['2fa_trust_days'];
                echo "<label style='display:block;margin-bottom:15px;color:#555;'><input type='checkbox' name='trust_device' value='1'> このデバイスを {$days} 日間記憶する</label>";
            }

            echo "<button type='submit' style='width:100%;padding:10px;background:#0056b3;color:#fff;border:none;border-radius:4px;'>認証して次へ</button>";
            echo "</fieldset></form><p style='text-align:center;margin-top:20px;'><a href='{$baseUrl}login'>キャンセルして戻る</a></p></main></body></html>";
            return;
        }

        if ($path === 'login/setup_totp') {
            if (empty($_SESSION['pending_2fa_user_id']) || empty($_SESSION['setup_totp_allowed'])) { header("Location: {$baseUrl}login"); exit; }
            $user = $this->userModel->findById($_SESSION['pending_2fa_user_id']);

            $secret = $_SESSION['temp_totp_secret'] ?? TOTP::generateSecret();
            $_SESSION['temp_totp_secret'] = $secret;
            $issuer = urlencode($_SERVER['SERVER_NAME']);
            $account = urlencode($user['student_id']);
            $qrUrl = "otpauth://totp/{$issuer}:{$account}?secret={$secret}&issuer={$issuer}";

            $error = '';
            if ($method === 'POST') {
                $code = preg_replace('/[^0-9]/', '', $_POST['code'] ?? '');
                if (TOTP::verify($secret, $code)) {
                    $user['totp_secret'] = $secret;
                    $user['is_2fa_enabled'] = true;
                    $bCodes = []; $hCodes = [];
                    for($i=0; $i<10; $i++) {
                        $c = strtoupper(bin2hex(random_bytes(4))) . '-' . strtoupper(bin2hex(random_bytes(4)));
                        $bCodes[] = $c;
                        $hCodes[] = password_hash($c, PASSWORD_DEFAULT);
                    }
                    $user['backup_codes'] = $hCodes;
                    $this->userModel->save($user);
                    
                    unset($_SESSION['temp_totp_secret']);
                    unset($_SESSION['setup_totp_allowed']);
                    
                    $_SESSION['flash_backup_codes'] = $bCodes;
                    
                    if (!empty($_SESSION['pending_trust_device'])) {
                        $_POST['trust_device'] = 1;
                        unset($_SESSION['pending_trust_device']);
                    }
                    
                    $this->auth->completeLogin($user);
                    
                    $trustDays = (int)($settings['2fa_trust_days'] ?? 0);
                    if ($trustDays > 0 && !empty($_POST['trust_device'])) {
                        $token = bin2hex(random_bytes(32));
                        $expires = time() + (86400 * $trustDays);
                        setcookie('cms_2fa_trust_' . $user['id'], $token, $expires, '/', '', false, true);
                        $user['trusted_devices'] = $user['trusted_devices'] ?? [];
                        $user['trusted_devices'][] = ['token' => password_hash($token, PASSWORD_DEFAULT), 'expires' => $expires];
                        $this->userModel->save($user);
                    }

                    if (isset($_SESSION['expired_user_id']) && $_SESSION['expired_user_id'] !== $user['id']) unset($_SESSION['recovery_post']);
                    unset($_SESSION['expired_user_id']);
                    
                    header("Location: {$baseUrl}login/backup"); exit;
                } else {
                    $error = "認証コードが正しくありません。";
                }
            }
            echo "<!DOCTYPE html><html lang='ja'><head><meta charset='UTF-8'><title>TOTP 初期設定 | CMS</title><style>body{font-family:sans-serif;background:#f4f4f4;display:flex;justify-content:center;align-items:center;height:100vh;margin:0;}main{background:#fff;padding:30px;border-radius:8px;box-shadow:0 0 10px rgba(0,0,0,0.1);max-width:400px;}</style></head><body>";
            echo "<main><h1>二段階認証の強制セットアップ</h1>";
            echo "<p>システム管理者の設定により、認証アプリ(TOTP)の登録が必須となっています。</p>";
            if ($error) echo "<p style='color:red;'>$error</p>";
            echo "<ol style='padding-left:20px;'><li>認証アプリ（Google Authenticator等）を準備してください</li>";
            echo "<li>以下のQRコードを読み取ってください<br><div id='qrcode' style='margin:15px 0;'></div></li>";
            echo "<li>手動入力キー: <strong>{$secret}</strong></li></ol>";
            echo "<form method='POST'><p>アプリに表示された6桁のコードを入力してください。</p><input type='text' name='code' required style='width:100%;margin-bottom:15px;padding:8px;'><button type='submit' style='width:100%;padding:10px;background:#0056b3;color:#fff;border:none;border-radius:4px;'>設定を完了する</button></form>";
            echo "<script src='https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js'></script>";
            echo "<script>new QRCode(document.getElementById('qrcode'), '{$qrUrl}');</script></main></body></html>";
            return;
        }

        if ($path === 'login/backup') {
            $codes = $_SESSION['flash_backup_codes'] ?? [];
            unset($_SESSION['flash_backup_codes']);
            $redirect = $_SESSION['redirect_url'] ?? "{$baseUrl}dashboard";
            unset($_SESSION['redirect_url']);
            
            echo "<!DOCTYPE html><html lang='ja'><head><meta charset='UTF-8'><title>バックアップコード | CMS</title><style>body{font-family:sans-serif;background:#f4f4f4;display:flex;justify-content:center;align-items:center;height:100vh;margin:0;}main{background:#fff;padding:30px;border-radius:8px;box-shadow:0 0 10px rgba(0,0,0,0.1);max-width:400px;}</style></head><body>";
            echo "<main><h1>バックアップコード</h1>";
            echo "<div style='padding:10px; background:#f8d7da; color:#721c24; border:1px solid #f5c6cb; border-radius:4px; margin-bottom:15px;'><strong>警告: 以下のバックアップコードは一度しか表示されません。必ず安全な場所に保存してください。</strong></div>";
            echo "<div style='background:#fff3cd; padding:20px; font-family:monospace; font-size:1.2em; text-align:center;'>";
            foreach($codes as $c) echo $c . "<br>";
            echo "</div><br><a href='{$redirect}' style='display:block; text-align:center; padding:10px; background:#28a745; color:#fff; text-decoration:none; border-radius:4px;'>確認して次へ進む</a></main></body></html>";
            return;
        }

        if ($path === 'logout') { 
            $this->writeLog($this->auth->getCurrentUser(), 'Logout', 'Success');
            $this->auth->logout(); header("Location: {$baseUrl}login"); exit; 
        }

        // ==========================================
        // 4. 管理画面（バックエンド・認証必須エリア）
        // ==========================================
        
        if ($isSystemPath && strpos($path, 'login') !== 0 && $path !== 'logout' && strpos($path, 'assets/') !== 0) {
            if (!$this->auth->isLoggedIn()) {
                if ($method === 'POST' && isset($_SESSION['expired_user_id']) && strpos($path, 'cms/contents/edit') === 0) {
                    $_SESSION['recovery_post'] = $_POST;
                }
                if ($method === 'GET' || $method === 'POST') $_SESSION['redirect_url'] = $_SERVER['REQUEST_URI'];
                header("Location: {$baseUrl}login"); exit;
            }
        }
        
        $currentUser = $this->auth->getCurrentUser();
        $isAdminOrSpecial = $isSystemPath ? in_array($currentUser['role'], ['admin', 'special']) : false;
        $adminHead = $isSystemPath ? $this->getAdminHead($baseUrl, $currentUser, $isAdminOrSpecial) : '';

        if ($path === 'dashboard') {
            $roles = ['admin' => '管理者', 'special' => '特別部員', 'general' => '一般部員'];
            $roleLabel = $roles[$currentUser['role']] ?? '不明';
            echo $adminHead . "<h1>ダッシュボード</h1><p>ようこそ、" . htmlspecialchars($currentUser['name'] ?? '部員') . "さん。</p>";
            echo "<div class='alert' style='background:#e9ecef; border-color:#dee2e6; color:#333; max-width: 400px;'><strong>あなたの現在の権限:</strong> " . htmlspecialchars($roleLabel) . "</div></main></body></html>";
            return;
        }

        // ==========================================
        // ★ メディア・ファイルアップロード管理
        // ==========================================
        if ($path === 'cms/uploads') {
            $canUpload = ($currentUser['role'] === 'admin' || !empty($settings['upload_allow_general']));

            if ($method === 'POST') {
                $action = $_POST['action'] ?? '';
                if ($action === 'upload' && isset($_FILES['file'])) {
                    if (!$canUpload) {
                        echo json_encode(['success'=>false, 'error'=>"アップロード権限がありません。"]); exit;
                    }
                    
                    $file = $_FILES['file'];
                    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                    $isAdmin = $currentUser['role'] === 'admin';
                    $bypassRest = $isAdmin && !empty($_POST['bypass_restrictions']);
                    
                    $allowedExts = array_map('trim', explode(',', $settings['upload_allowed_exts'] ?? 'jpg,jpeg,png,gif,webp,pdf,zip,txt'));
                    $maxMb = (float)($settings['upload_max_mb'] ?? 5);
                    $maxBytes = $maxMb * 1024 * 1024;

                    if (!$bypassRest) {
                        if (!in_array($ext, $allowedExts)) {
                            echo json_encode(['success'=>false, 'error'=>"許可されていない拡張子です: {$ext}"]); exit;
                        }
                        if ($file['size'] > $maxBytes) {
                            echo json_encode(['success'=>false, 'error'=>"ファイルサイズが大きすぎます (最大 {$maxMb}MB)"]); exit;
                        }
                    }

                    $uploadDir = __DIR__ . '/../data/uploads';
                    if (!is_dir($uploadDir)) @mkdir($uploadDir, 0777, true);
                    
                    $safeName = preg_replace('/[^a-zA-Z0-9_.-]/', '', basename($file['name']));
                    if (!$safeName) $safeName = 'file_' . time() . '.' . $ext;
                    $uniqueName = date('YmdHis') . '_' . substr(md5(uniqid()), 0, 6) . '_' . $safeName;
                    
                    if (move_uploaded_file($file['tmp_name'], $uploadDir . '/' . $uniqueName)) {
                        $uploads = $this->getUploads();
                        $uploads[] = [
                            'id' => uniqid('up_'),
                            'filename' => $uniqueName,
                            'original_name' => $file['name'],
                            'user_id' => $currentUser['id'],
                            'user_name' => $currentUser['name'],
                            'size' => filesize($uploadDir . '/' . $uniqueName),
                            'created_at' => date('Y-m-d H:i:s')
                        ];
                        $this->saveUploads($uploads);
                        $this->writeLog($currentUser, 'File Upload', "File: {$uniqueName}");
                        echo json_encode(['success'=>true, 'filename'=>$uniqueName]); exit;
                    } else {
                        echo json_encode(['success'=>false, 'error'=>"保存に失敗しました"]); exit;
                    }
                }
                
                if ($action === 'delete' && isset($_POST['id'])) {
                    $uploads = $this->getUploads();
                    $newUploads = [];
                    foreach ($uploads as $u) {
                        if ($u['id'] === $_POST['id']) {
                            if ($currentUser['role'] === 'admin' || $u['user_id'] === $currentUser['id']) {
                                @unlink(__DIR__ . '/../data/uploads/' . $u['filename']);
                                $this->writeLog($currentUser, 'File Delete', "File: {$u['filename']}");
                                continue;
                            }
                        }
                        $newUploads[] = $u;
                    }
                    $this->saveUploads($newUploads);
                    header("Location: {$baseUrl}cms/uploads"); exit;
                }
            }
            
            $uploads = $this->getUploads();
            
            // ★ 検索機能
            $q = trim($_GET['q'] ?? '');
            if ($q !== '') {
                $uploads = array_filter($uploads, function($u) use ($q) {
                    $target = $u['original_name'] . ' ' . $u['filename'] . ' ' . $u['created_at'];
                    return mb_stripos($target, $q) !== false;
                });
            }

            // ★ ソート機能
            $sort = $_GET['sort'] ?? 'date';
            $order = $_GET['order'] ?? 'desc';
            usort($uploads, function($a, $b) use ($sort, $order) {
                if ($sort === 'name') {
                    $valA = mb_strtolower($a['original_name']);
                    $valB = mb_strtolower($b['original_name']);
                } else {
                    $valA = strtotime($a['created_at']);
                    $valB = strtotime($b['created_at']);
                }
                if ($valA == $valB) return 0;
                if ($order === 'asc') return ($valA < $valB) ? -1 : 1;
                return ($valA > $valB) ? -1 : 1;
            });

            // ★ ページネーション (1ページ20件)
            $p = max(1, (int)($_GET['p'] ?? 1));
            $perPage = 20;
            $total = count($uploads);
            $maxPage = max(1, ceil($total / $perPage));
            if ($p > $maxPage) $p = $maxPage;
            $pagedUploads = array_slice($uploads, ($p - 1) * $perPage, $perPage);
            
            $webpEnabled = !empty($settings['upload_webp_enable']) ? 'true' : 'false';
            $maxPx = (int)($settings['upload_max_px'] ?? 1200);
            $webpQuality = ((int)($settings['upload_webp_quality'] ?? 80)) / 100;

            echo $adminHead . "<h1>メディア (ファイル) 管理</h1>";
            
            if ($canUpload) {
                echo "<fieldset><legend>ファイルをアップロード</legend>";
                echo "<p style='font-size:0.9em;color:#666;'>※許可された拡張子: " . htmlspecialchars($settings['upload_allowed_exts'] ?? 'jpg, jpeg, png, gif, webp, pdf, zip, txt') . " / 最大サイズ: " . htmlspecialchars($settings['upload_max_mb'] ?? '5') . " MB</p>";
                echo "<div style='display:flex;gap:10px;align-items:center; margin-bottom:10px;'>";
                echo "<input type='file' id='file-upload-input' style='flex:1;'>";
                echo "<button type='button' id='btn-upload' class='btn'>アップロード実行</button>";
                echo "</div>";
                
                if ($currentUser['role'] === 'admin') {
                    echo "<div style='background:#fff3cd; padding:10px; border-radius:4px; font-size:0.9em; margin-bottom:10px;'>";
                    echo "<label><input type='checkbox' id='bypass_restrictions'> 拡張子やサイズの制限を無視する (管理者のみ)</label><br>";
                    echo "<label><input type='checkbox' id='bypass_convert'> 画像のWebP変換・リサイズを行わず元のままアップロードする</label>";
                    echo "</div>";
                }
                
                echo "<div id='upload-status' style='display:none; padding:10px; border-radius:4px; margin-top:10px;'></div>";
                echo "</fieldset>";
            } else {
                echo "<div class='alert' style='background:#e9ecef;'>現在は管理者のみアップロードが許可されています。</div>";
            }

            echo "<h2>アップロード済みファイル一覧</h2>";
            
            echo "<form method='GET' action='{$baseUrl}cms/uploads' style='display:flex; justify-content:space-between; align-items:center; margin-bottom: 20px; background:#f8f9fa; padding:10px; border-radius:4px; flex-wrap:wrap; gap:10px;'>";
            echo "<div style='display:flex; align-items:center; gap:5px;'><input type='text' name='q' value='".htmlspecialchars($q)."' placeholder='ファイル名, 拡張子, 日時...' style='padding:8px; width:250px; margin-bottom:0;'> <button type='submit' class='btn' style='padding:8px 15px;'>検索</button>";
            if ($q) echo " <a href='{$baseUrl}cms/uploads' style='margin-left:5px;'>クリア</a>";
            echo "</div>";
            echo "<div style='font-size:0.9em;'>全 {$total} 件中 ".(($p-1)*$perPage+1)." - ".min($total, $p*$perPage)." 件</div>";
            echo "<div style='display:flex; gap:5px;'><select name='sort' onchange='this.form.submit()' style='margin:0; padding:6px;'><option value='date' ".($sort==='date'?'selected':'').">アップロード日</option><option value='name' ".($sort==='name'?'selected':'').">ファイル名</option></select> ";
            echo "<select name='order' onchange='this.form.submit()' style='margin:0; padding:6px;'><option value='desc' ".($order==='desc'?'selected':'').">降順</option><option value='asc' ".($order==='asc'?'selected':'').">昇順</option></select></div></form>";

            echo "<table><thead><tr><th style='width:100px;'>プレビュー</th><th>ファイル情報</th><th>URL / Markdown</th><th>操作</th></tr></thead><tbody>";
            foreach ($pagedUploads as $u) {
                $ext = strtolower(pathinfo($u['filename'], PATHINFO_EXTENSION));
                $isImg = in_array($ext, ['jpg','jpeg','png','gif','webp']);
                $url = "{$baseUrl}uploads/" . $u['filename'];
                $sizeKb = number_format($u['size'] / 1024, 1);
                
                echo "<tr>";
                echo "<td>" . ($isImg ? "<img src='{$url}' style='max-width:100px; max-height:100px; object-fit:cover; border:1px solid #ccc;'>" : "<div style='font-size:2em;text-align:center;color:#6c757d;'>📄</div>") . "</td>";
                echo "<td><strong>".htmlspecialchars($u['original_name'])."</strong><br><span style='font-size:0.85em;color:#666;'>{$sizeKb} KB<br>".htmlspecialchars($u['created_at'])."<br>アップロード: ".htmlspecialchars($u['user_name'])."</span></td>";
                echo "<td>";
                echo "<input type='text' value='{$url}' readonly style='width:100%; padding:4px; margin-bottom:5px; font-size:0.85em;' onclick='this.select(); document.execCommand(\"copy\"); alert(\"URLをコピーしました\");'>";
                if ($isImg) {
                    $md = "![".htmlspecialchars($u['original_name'])."]({$url})";
                    echo "<input type='text' value='{$md}' readonly style='width:100%; padding:4px; margin-bottom:0; font-size:0.85em;' onclick='this.select(); document.execCommand(\"copy\"); alert(\"Markdownをコピーしました\");'>";
                }
                echo "</td>";
                echo "<td>";
                if ($currentUser['role'] === 'admin' || $u['user_id'] === $currentUser['id']) {
                    echo "<form method='POST' style='margin:0;' onsubmit='return confirm(\"本当に削除しますか？記事などで使用中の場合リンク切れになります。\");'><input type='hidden' name='action' value='delete'><input type='hidden' name='id' value='{$u['id']}'><button type='submit' class='btn' style='background:#dc3545; padding:5px 10px; font-size:0.9em;'>削除</button></form>";
                }
                echo "</td>";
                echo "</tr>";
            }
            if (empty($pagedUploads)) echo "<tr><td colspan='4'>ファイルはありません。</td></tr>";
            echo "</tbody></table>";

            if ($maxPage > 1) {
                echo "<div style='margin-top:20px; display:flex; gap:5px; justify-content:center;'>";
                for ($i = 1; $i <= $maxPage; $i++) {
                    $qStr = http_build_query(['q'=>$q, 'sort'=>$sort, 'order'=>$order, 'p'=>$i]);
                    if ($i === $p) echo "<span style='padding:5px 10px; background:#007bff; color:#fff; border-radius:3px;'>{$i}</span>";
                    else echo "<a href='{$baseUrl}cms/uploads?{$qStr}' style='padding:5px 10px; background:#e9ecef; text-decoration:none; color:#333; border-radius:3px;'>{$i}</a>";
                }
                echo "</div>";
            }

            if ($canUpload) {
                echo <<<JS
<script>
document.getElementById('btn-upload').addEventListener('click', async () => {
    const fileInput = document.getElementById('file-upload-input');
    const statusDiv = document.getElementById('upload-status');
    const bypassRest = document.getElementById('bypass_restrictions');
    const bypassConv = document.getElementById('bypass_convert');
    
    if (!fileInput.files.length) return alert('ファイルを選択してください。');
    
    const file = fileInput.files[0];
    const isImage = file.type.startsWith('image/');
    const webpEnabled = {$webpEnabled};
    const maxPx = {$maxPx};
    const webpQuality = {$webpQuality};
    const skipConvert = bypassConv && bypassConv.checked;
    
    statusDiv.style.display = 'block';
    statusDiv.style.background = '#e9ecef';
    statusDiv.style.color = '#333';
    statusDiv.textContent = '処理中...';

    let uploadFile = file;
    let uploadFileName = file.name;

    if (isImage && webpEnabled && !skipConvert && file.type !== 'image/gif' && file.type !== 'image/svg+xml') {
        statusDiv.textContent = '画像を圧縮・WebPに変換しています...';
        try {
            const convertedBlob = await compressImage(file, maxPx, webpQuality);
            // ★ 逆効果防止: 圧縮後のファイルサイズが元のサイズより小さければ採用
            if (convertedBlob.size < file.size) {
                uploadFile = convertedBlob;
                uploadFileName = file.name.replace(/\.[^/.]+$/, "") + ".webp";
            } else {
                console.log('WebP変換によりファイルサイズが増加したため、元のファイルを使用します。');
            }
        } catch(e) {
            console.error(e);
            alert('画像の変換に失敗しました。元のままでアップロードします。');
        }
    }

    statusDiv.textContent = 'サーバーにアップロードしています...';

    const formData = new FormData();
    formData.append('file', uploadFile, uploadFileName);
    formData.append('action', 'upload');
    if (bypassRest && bypassRest.checked) formData.append('bypass_restrictions', '1');

    try {
        const res = await fetch('{$baseUrl}cms/uploads', { method: 'POST', body: formData });
        const text = await res.text();
        let json;
        try { json = JSON.parse(text); } catch(e) { throw new Error('サーバーエラー: ' + text); }
        
        if (json.success) {
            statusDiv.style.background = '#d4edda';
            statusDiv.style.color = '#155724';
            statusDiv.textContent = 'アップロード成功！画面を更新します...';
            setTimeout(() => location.reload(), 500);
        } else {
            throw new Error(json.error || '不明なエラー');
        }
    } catch (err) {
        statusDiv.style.background = '#f8d7da';
        statusDiv.style.color = '#721c24';
        statusDiv.textContent = 'エラー: ' + err.message;
    }
});

function compressImage(file, maxPx, quality) {
    return new Promise((resolve, reject) => {
        const reader = new FileReader();
        reader.onload = (e) => {
            const img = new Image();
            img.onload = () => {
                let w = img.width;
                let h = img.height;
                if (w > maxPx || h > maxPx) {
                    if (w > h) { h = Math.round(h * maxPx / w); w = maxPx; }
                    else { w = Math.round(w * maxPx / h); h = maxPx; }
                }
                const canvas = document.createElement('canvas');
                canvas.width = w;
                canvas.height = h;
                const ctx = canvas.getContext('2d');
                ctx.drawImage(img, 0, 0, w, h);
                canvas.toBlob((blob) => {
                    if(blob) resolve(blob); else reject('Blob creation failed');
                }, 'image/webp', quality);
            };
            img.onerror = () => reject('Image load failed');
            img.src = e.target.result;
        };
        reader.onerror = () => reject('File read failed');
        reader.readAsDataURL(file);
    });
}
</script>
JS;
            }
            echo "</main></body></html>";
            return;
        }

        // ==========================================
        // 管理機能（各種設定、マイTOTP設定など）
        // ==========================================
        if ($path === 'cms/settings/mail' && $currentUser['role'] === 'admin') {
            $msg = ''; $err = '';
            if ($method === 'POST') {
                if (isset($_POST['action']) && $_POST['action'] === 'test_mail') {
                    $mailer = new Mailer($settings);
                    if ($mailer->send($_POST['test_email'], "CMS テストメール", "これはCMSからのテスト送信です。\nこのメールが届いていれば設定は正常です。")) {
                        $msg = "テストメールを送信しました。";
                    } else {
                        $err = "送信に失敗しました。設定を見直してください。";
                    }
                } else {
                    $settings['mail_method'] = $_POST['mail_method'] ?? 'sendmail';
                    $settings['smtp_host'] = $_POST['smtp_host'] ?? '';
                    $settings['smtp_port'] = $_POST['smtp_port'] ?? '587';
                    $settings['smtp_crypto'] = $_POST['smtp_crypto'] ?? 'STARTTLS';
                    $settings['smtp_user'] = $_POST['smtp_user'] ?? '';
                    $settings['smtp_pass'] = $_POST['smtp_pass'] ?? '';
                    $settings['mail_from'] = $_POST['mail_from'] ?? '';
                    $settings['mail_from_name'] = $_POST['mail_from_name'] ?? '';
                    $this->saveSettings($settings);
                    $msg = "メール設定を保存しました。";
                }
            }

            echo $adminHead . "<h1>メール送信設定</h1>";
            if ($msg) echo "<div class='alert'>$msg</div>";
            if ($err) echo "<div class='alert alert-error'>$err</div>";

            echo "<p>この画面では、ログインシステムがメールを送信する際の設定を行います。<br>sendmail（PHP mail）または SMTP のどちらかを選択できます。</p>";
            echo "<form method='POST'><fieldset><legend>■ メール送信方式</legend>";
            echo "<label><input type='radio' name='mail_method' value='sendmail' ".(($settings['mail_method']??'sendmail')==='sendmail'?'checked':'')."> sendmail (PHP mail)</label><br>";
            echo "<label><input type='radio' name='mail_method' value='smtp' ".(($settings['mail_method']??'')==='smtp'?'checked':'')."> SMTP</label><br><br>";
            echo "<label>送信元メールアドレス (From): <input type='email' name='mail_from' value='".htmlspecialchars($settings['mail_from']??'')."'></label>";
            echo "<label>送信者名 (From Name): <input type='text' name='mail_from_name' value='".htmlspecialchars($settings['mail_from_name']??'')."'></label>";
            echo "</fieldset>";

            echo "<fieldset><legend>■ SMTP設定 (SMTP選択時のみ)</legend>";
            echo "<label>SMTPホスト名: <input type='text' name='smtp_host' value='".htmlspecialchars($settings['smtp_host']??'')."' placeholder='smtp.gmail.com'></label>";
            echo "<label>ポート番号: <input type='text' name='smtp_port' value='".htmlspecialchars($settings['smtp_port']??'587')."'></label>";
            echo "<label>暗号化方式: <select name='smtp_crypto'><option value='STARTTLS' ".(($settings['smtp_crypto']??'')==='STARTTLS'?'selected':'').">STARTTLS</option><option value='SSL' ".(($settings['smtp_crypto']??'')==='SSL'?'selected':'').">SSL</option><option value='NONE' ".(($settings['smtp_crypto']??'')==='NONE'?'selected':'').">なし</option></select></label><br><br>";
            echo "<label>SMTPユーザー名 (※不要な場合は空欄): <input type='text' name='smtp_user' value='".htmlspecialchars($settings['smtp_user']??'')."'></label>";
            echo "<label>SMTPパスワード: <input type='password' name='smtp_pass' value='".htmlspecialchars($settings['smtp_pass']??'')."'></label>";
            echo "</fieldset><button type='submit' class='btn'>設定を保存</button></form><hr>";

            echo "<h2>■ テスト送信</h2><form method='POST'>";
            echo "<input type='hidden' name='action' value='test_mail'>";
            echo "<label>テストメール送信先アドレス: <input type='email' name='test_email' required></label>";
            echo "<button type='submit' class='btn' style='background:#6c757d;'>テストメールを送信する</button></form>";
            echo "</main></body></html>";
            return;
        }

        if ($path === 'cms/settings/2fa' && $currentUser['role'] === 'admin') {
            $msg = ''; $err = '';

            if ($method === 'POST') {
                if (isset($_POST['action']) && $_POST['action'] === 'save_global') {
                    if ($_POST['2fa_mode'] !== 'none') {
                        $users = $this->userModel->getAll();
                        $missingEmail = false;
                        foreach($users as $u) { if (empty($u['email'])) { $missingEmail = true; break; } }
                        if ($missingEmail) {
                            $err = "メールアドレスが未登録のユーザーがいるため、設定を保存できません。全員のメールアドレスを登録してください。";
                        }
                    }
                    if (!$err) {
                        $settings['2fa_mode'] = $_POST['2fa_mode'];
                        $settings['2fa_trust_days'] = (int)($_POST['2fa_trust_days'] ?? 0);
                        $this->saveSettings($settings);
                        $msg = "二段階認証の設定を保存しました。";
                    }
                } elseif (isset($_POST['action']) && $_POST['action'] === 'disable_user') {
                    $u = $this->userModel->findById($_POST['target_user']);
                    if ($u) {
                        $u['is_2fa_enabled'] = false; $u['totp_secret'] = null; $u['backup_codes'] = [];
                        $this->userModel->save($u);
                        $msg = "{$u['name']} のTOTPを無効化しました。";
                    }
                } elseif (isset($_POST['action']) && $_POST['action'] === 'notify_user') {
                    $u = $this->userModel->findById($_POST['target_user']);
                    if ($u && !empty($u['email'])) {
                        $mailer = new Mailer($settings);
                        $body = "{$u['name']} 様\n\n管理者より二段階認証（TOTP）の設定が許可されました。\n以下のURLからログインし、「マイTOTP設定」より設定を完了してください。\n\n{$baseUrl}login";
                        if ($mailer->send($u['email'], "二段階認証の設定について", $body)) {
                            $msg = "{$u['name']} に設定案内メールを送信しました。";
                        } else {
                            $err = "メールの送信に失敗しました。設定を確認してください。";
                        }
                    }
                }
            }

            echo $adminHead . "<h1>二段階認証（2FA）システム設定</h1>";
            if ($msg) echo "<div class='alert'>$msg</div>";
            if ($err) echo "<div class='alert alert-error'>$err</div>";

            echo "<form method='POST'><input type='hidden' name='action' value='save_global'><fieldset><legend>■ 二段階認証方式</legend>";
            $mode = $settings['2fa_mode'] ?? 'none';
            echo "<label><input type='radio' name='2fa_mode' value='none' ".($mode==='none'?'checked':'')."> 2FA無し（メールもTOTPも無効）</label><br>";
            echo "<label><input type='radio' name='2fa_mode' value='email' ".($mode==='email'?'checked':'')."> メール認証のみ（必須）</label><br>";
            echo "<label><input type='radio' name='2fa_mode' value='email_totp_optional' ".($mode==='email_totp_optional'?'checked':'')."> メール認証 ＋ TOTP（任意）</label><br>";
            echo "<label><input type='radio' name='2fa_mode' value='email_totp_required' ".($mode==='email_totp_required'?'checked':'')."> メール認証 ＋ TOTP（必須）</label>";
            echo "</fieldset>";
            
            echo "<fieldset><legend>■ 信頼されたデバイス (2FAのスキップ)</legend>";
            echo "<label>デバイスを記憶する日数: <input type='number' name='2fa_trust_days' value='".htmlspecialchars($settings['2fa_trust_days'] ?? 0)."' min='0' max='365' style='width:100px;display:inline-block;'></label>";
            echo "<p style='font-size:0.9em;color:#666;'>※0を指定するとこの機能を無効にします（常に2FAが要求されます）。</p>";
            echo "</fieldset>";

            echo "<button type='submit' class='btn'>設定を保存</button></form><hr>";

            echo "<h2>ユーザー別 TOTP 管理</h2><table><thead><tr><th>ユーザー名</th><th>メールアドレス</th><th>TOTP 状態</th><th>操作</th></tr></thead><tbody>";
            $users = $this->userModel->getAll();
            $totpAllowed = in_array($mode, ['email_totp_optional', 'email_totp_required']);
            
            foreach ($users as $u) {
                $isSet = !empty($u['is_2fa_enabled']) && !empty($u['totp_secret']);
                $status = $isSet ? "<span style='color:green;'>設定済み</span>" : "<span style='color:gray;'>未設定</span>";
                echo "<tr><td>".htmlspecialchars($u['name'])."</td><td>".htmlspecialchars($u['email']??'')."</td><td>{$status}</td><td>";
                
                if ($totpAllowed) {
                    if ($isSet) {
                        echo "<form method='POST' style='display:inline;' onsubmit='return confirm(\"このユーザーの TOTP を無効にしますか？\\n次回ログイン時は TOTP が不要になります（または強制再設定になります）。\");'>";
                        echo "<input type='hidden' name='action' value='disable_user'><input type='hidden' name='target_user' value='{$u['id']}'>";
                        echo "<button type='submit' class='btn' style='background:#dc3545;'>無効化する</button></form> ";
                    } else {
                        echo "<a href='{$baseUrl}cms/settings/2fa/manage?id={$u['id']}' class='btn' style='background:#28a745; margin-bottom:5px; margin-right:5px;'>管理者が設定</a>";
                        if (!empty($u['email'])) {
                            echo "<form method='POST' style='display:inline;'><input type='hidden' name='action' value='notify_user'><input type='hidden' name='target_user' value='{$u['id']}'>";
                            echo "<button type='submit' class='btn' style='background:#17a2b8;'>ユーザーに設定させる(メール送信)</button></form>";
                        }
                    }
                } else {
                    echo "<span style='color:#666;'>(TOTP無効モード)</span>";
                }
                echo "</td></tr>";
            }
            echo "</tbody></table></main></body></html>";
            return;
        }

        if ($path === 'cms/settings/2fa/manage' && $currentUser['role'] === 'admin') {
            $id = $_GET['id'] ?? '';
            $targetUser = $this->userModel->findById($id);
            if (!$targetUser) $this->renderErrorPage(404, $baseUrl, "ユーザーが見つかりません。", $adminHead);

            $secret = $_SESSION['temp_admin_totp_secret'] ?? TOTP::generateSecret();
            $_SESSION['temp_admin_totp_secret'] = $secret;
            $issuer = urlencode($_SERVER['SERVER_NAME']);
            $account = urlencode($targetUser['student_id']);
            $qrUrl = "otpauth://totp/{$issuer}:{$account}?secret={$secret}&issuer={$issuer}";

            $err = '';
            if ($method === 'POST') {
                if (TOTP::verify($secret, preg_replace('/[^0-9]/', '', $_POST['code'] ?? ''))) {
                    $targetUser['totp_secret'] = $secret;
                    $targetUser['is_2fa_enabled'] = true;
                    $bCodes = []; $hCodes = [];
                    for($i=0; $i<10; $i++) {
                        $c = strtoupper(bin2hex(random_bytes(4))) . '-' . strtoupper(bin2hex(random_bytes(4)));
                        $bCodes[] = $c;
                        $hCodes[] = password_hash($c, PASSWORD_DEFAULT);
                    }
                    $targetUser['backup_codes'] = $hCodes;
                    $this->userModel->save($targetUser);
                    unset($_SESSION['temp_admin_totp_secret']);
                    $_SESSION['flash_backup_codes'] = $bCodes;
                    header("Location: {$baseUrl}cms/settings/2fa/backup?id={$targetUser['id']}"); exit;
                } else {
                    $err = "コードが正しくありません。";
                }
            }

            echo $adminHead . "<h1>TOTP 対面セットアップ: {$targetUser['name']}</h1>";
            if ($err) echo "<div class='alert alert-error'>$err</div>";
            echo "<p>対象者のスマートフォンで以下のQRコードを読み取り、表示されたコードを入力してください。</p>";
            echo "<div id='qrcode' style='margin:20px 0;'></div>";
            echo "<p>手動入力キー: <strong>{$secret}</strong></p>";
            echo "<form method='POST'><label>認証コード: <input type='text' name='code' required></label><button type='submit' class='btn'>設定を完了する</button></form>";
            echo "<script src='https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js'></script>";
            echo "<script>new QRCode(document.getElementById('qrcode'), '{$qrUrl}');</script>";
            echo "</main></body></html>";
            return;
        }

        if ($path === 'cms/settings/2fa/backup' && $currentUser['role'] === 'admin') {
            $codes = $_SESSION['flash_backup_codes'] ?? [];
            unset($_SESSION['flash_backup_codes']);
            echo $adminHead . "<h1>バックアップコード</h1>";
            echo "<div class='alert alert-error'><strong>警告: この画面は一度しか表示されません。</strong>対象者に以下のコードを安全に保管させてください。</div>";
            echo "<div style='background:#fff3cd; padding:20px; font-family:monospace; font-size:1.2em;'>";
            foreach($codes as $c) echo $c . "<br>";
            echo "</div><br><a href='{$baseUrl}cms/settings/2fa' class='btn'>完了</a></main></body></html>";
            return;
        }

        if (strpos($path, 'cms/2fa') === 0) {
            $user = $this->userModel->findById($currentUser['id']);
            $mode = $settings['2fa_mode'] ?? 'none';

            if ($mode === 'none' || $mode === 'email') {
                echo $adminHead . "<h1>二段階認証</h1><p>現在、ユーザーによるTOTP設定は無効化されています。</p></main></body></html>"; return;
            }

            if ($path === 'cms/2fa') {
                echo $adminHead . "<h1>二段階認証（TOTP）</h1>";
                $isSet = !empty($user['is_2fa_enabled']) && !empty($user['totp_secret']);
                
                if ($isSet) {
                    echo "<div class='alert'>状態：有効</div>";
                    $remain = count($user['backup_codes']);
                    echo "<fieldset><legend>バックアップコード</legend><p>残り {$remain} / 10</p>";
                    echo "<form method='POST' action='{$baseUrl}cms/2fa/regenerate' onsubmit='return confirm(\"再生成すると古いコードは全て無効になります。よろしいですか？\")'><button class='btn'>再生成</button></form></fieldset>";
                    
                    if ($mode !== 'email_totp_required') {
                        echo "<form method='POST' action='{$baseUrl}cms/2fa/disable' onsubmit='return confirm(\"TOTPを無効化しますか？\")'><button class='btn' style='background:#dc3545;margin-top:20px;'>TOTP を無効化する</button></form>";
                    }
                } else {
                    echo "<div class='alert alert-error'>状態：未設定</div>";
                    if (empty($user['email'])) {
                        echo "<p>メールアドレスが登録されていないため設定を開始できません。プロフィール設定からメールアドレスを登録してください。</p>";
                    } else {
                        echo "<p>管理者により、TOTP 設定を有効化することが許可されています。<br>続行するにはメール認証が必要です。</p>";
                        echo "<form method='POST' action='{$baseUrl}cms/2fa/send-code'><button class='btn'>メール認証を開始する</button></form>";
                    }
                }
                echo "</main></body></html>"; return;
            }

            if ($path === 'cms/2fa/disable') {
                if ($method === 'POST' && $mode !== 'email_totp_required') {
                    $user['is_2fa_enabled'] = false; $user['totp_secret'] = null; $user['backup_codes'] = [];
                    $this->userModel->save($user);
                }
                header("Location: {$baseUrl}cms/2fa"); exit;
            }

            if ($path === 'cms/2fa/send-code') {
                if ($method === 'POST' && !empty($user['email'])) {
                    $code = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
                    $user['email_verify_code'] = password_hash($code, PASSWORD_DEFAULT);
                    $user['email_verify_expires'] = time() + 600; 
                    $this->userModel->save($user);

                    $mailer = new Mailer($settings);
                    $mailer->send($user['email'], "二段階認証 設定用コード", "設定を続行するための確認コードです。\n\nコード：{$code}\n\n※このコードは10分間有効です。");
                    header("Location: {$baseUrl}cms/2fa/verify"); exit;
                }
            }

            if ($path === 'cms/2fa/verify') {
                $err = '';
                if ($method === 'POST') {
                    if (time() > $user['email_verify_expires']) {
                        $err = "コードの有効期限が切れています。もう一度最初からやり直してください。";
                    } elseif (password_verify($_POST['code'] ?? '', $user['email_verify_code'])) {
                        $_SESSION['2fa_email_verified'] = true;
                        $user['email_verify_code'] = null; $user['email_verify_expires'] = null;
                        $this->userModel->save($user);
                        header("Location: {$baseUrl}cms/2fa/setup"); exit;
                    } else {
                        $err = "確認コードが正しくありません。";
                    }
                }
                echo $adminHead . "<h1>メール認証</h1>";
                if ($err) echo "<div class='alert alert-error'>$err</div>";
                echo "<p>登録メールアドレスに確認コード（6桁）を送信しました。</p>";
                echo "<form method='POST'><label>確認コード（6桁）: <input type='text' name='code' required></label><button type='submit' class='btn'>認証して次へ</button></form></main></body></html>";
                return;
            }

            if ($path === 'cms/2fa/regenerate') {
                if ($method === 'POST') {
                    $bCodes = []; $hCodes = [];
                    for($i=0; $i<10; $i++) {
                        $c = strtoupper(bin2hex(random_bytes(4))) . '-' . strtoupper(bin2hex(random_bytes(4)));
                        $bCodes[] = $c;
                        $hCodes[] = password_hash($c, PASSWORD_DEFAULT);
                    }
                    $user['backup_codes'] = $hCodes;
                    $this->userModel->save($user);
                    $_SESSION['flash_backup_codes'] = $bCodes;
                    header("Location: {$baseUrl}cms/2fa/backup"); exit;
                }
                header("Location: {$baseUrl}cms/2fa"); exit;
            }

            if ($path === 'cms/2fa/setup') {
                if (empty($_SESSION['2fa_email_verified'])) { header("Location: {$baseUrl}cms/2fa"); exit; }

                $secret = $_SESSION['temp_totp_secret'] ?? TOTP::generateSecret();
                $_SESSION['temp_totp_secret'] = $secret;
                $issuer = urlencode($_SERVER['SERVER_NAME']);
                $account = urlencode($user['student_id']);
                $qrUrl = "otpauth://totp/{$issuer}:{$account}?secret={$secret}&issuer={$issuer}";

                $err = '';
                if ($method === 'POST') {
                    if (TOTP::verify($secret, preg_replace('/[^0-9]/', '', $_POST['code'] ?? ''))) {
                        $user['totp_secret'] = $secret;
                        $user['is_2fa_enabled'] = true;
                        $bCodes = []; $hCodes = [];
                        for($i=0; $i<10; $i++) {
                            $c = strtoupper(bin2hex(random_bytes(4))) . '-' . strtoupper(bin2hex(random_bytes(4)));
                            $bCodes[] = $c;
                            $hCodes[] = password_hash($c, PASSWORD_DEFAULT);
                        }
                        $user['backup_codes'] = $hCodes;
                        $this->userModel->save($user);
                        unset($_SESSION['temp_totp_secret']);
                        unset($_SESSION['2fa_email_verified']);
                        $_SESSION['flash_backup_codes'] = $bCodes;
                        header("Location: {$baseUrl}cms/2fa/backup"); exit;
                    } else {
                        $err = "認証コードが正しくありません。";
                    }
                }

                echo $adminHead . "<h1>二段階認証（TOTP）セットアップ</h1>";
                if ($err) echo "<div class='alert alert-error'>$err</div>";
                echo "<ol><li>認証アプリ（Google Authenticator等）を準備してください</li>";
                echo "<li>以下のQRコードを読み取ってください<br><div id='qrcode' style='margin:15px 0;'></div></li>";
                echo "<li>手動入力キー（必要な場合）: <strong>{$secret}</strong></li></ol>";
                echo "<h3>認証コードの確認</h3><p>アプリに表示された6桁のコードを入力してください。</p>";
                echo "<form method='POST'><label>認証コード: <input type='text' name='code' required></label><button type='submit' class='btn'>設定を完了する</button></form>";
                echo "<script src='https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js'></script>";
                echo "<script>new QRCode(document.getElementById('qrcode'), '{$qrUrl}');</script></main></body></html>";
                return;
            }

            if ($path === 'cms/2fa/backup') {
                $codes = $_SESSION['flash_backup_codes'] ?? [];
                unset($_SESSION['flash_backup_codes']);
                echo $adminHead . "<h1>バックアップコード</h1>";
                echo "<div class='alert alert-error'><strong>以下のバックアップコードは一度しか表示されません。必ず安全な場所に保存してください。</strong></div>";
                echo "<div style='background:#fff3cd; padding:20px; font-family:monospace; font-size:1.2em;'>";
                foreach($codes as $c) echo "- " . $c . "<br>";
                echo "</div><br><a href='{$baseUrl}cms/2fa' class='btn'>完了</a></main></body></html>";
                return;
            }
        }

        // ==========================================
        // ログ出力 (管理者)
        // ==========================================
        if ($path === 'cms/logs/download' && $currentUser['role'] === 'admin') {
            $logFile = __DIR__ . '/../data/app.log';
            if (file_exists($logFile)) {
                header('Content-Type: text/plain; charset=utf-8');
                header('Content-Disposition: attachment; filename="app_log_' . date('Ymd_His') . '.txt"');
                readfile($logFile); exit;
            }
            $this->renderErrorPage(404, $baseUrl, "ログファイルが見つかりません。", $adminHead);
        }

        if ($path === 'cms/logs' && $currentUser['role'] === 'admin') {
            echo $adminHead . "<h1>システムログ</h1>";
            $logFile = __DIR__ . '/../data/app.log';
            $logs = file_exists($logFile) ? array_reverse(file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)) : [];
            echo "<div style='display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;'><p style='margin:0;'>システム内で発生した重要なイベントの記録です。</p>";
            echo "<a href='{$baseUrl}cms/logs/download' class='btn' style='background:#28a745;'>ログをダウンロード (.txt)</a></div>";
            echo "<table><thead><tr><th style='width:200px;'>日時</th><th>ユーザー</th><th>アクション</th><th>詳細</th></tr></thead><tbody>";
            foreach ($logs as $line) {
                $parts = explode("\t", $line, 4);
                if (count($parts) >= 3) echo "<tr><td>".htmlspecialchars($parts[0])."</td><td>".htmlspecialchars($parts[1])."</td><td>".htmlspecialchars($parts[2])."</td><td>".htmlspecialchars($parts[3] ?? '')."</td></tr>";
            }
            if (empty($logs)) echo "<tr><td colspan='4'>ログはまだありません。</td></tr>";
            echo "</tbody></table></main></body></html>"; return;
        }

        // ==========================================
        // 全データバックアップ・復元 (管理者)
        // ==========================================
        if ($path === 'cms/backups/export' && $currentUser['role'] === 'admin') {
            if ($method === 'POST') {
                $zip = new ZipArchive();
                $backupDir = DATA_DIR . '/backups';
                if (!is_dir($backupDir)) @mkdir($backupDir, 0777, true);
                $zipFile = $backupDir . '/temp_export_' . time() . '.zip';
                
                if ($zip->open($zipFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true) {
                    $dir = realpath(DATA_DIR);
                    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir), RecursiveIteratorIterator::LEAVES_ONLY);
                    foreach ($files as $name => $file) {
                        if (!$file->isDir()) {
                            $filePath = $file->getRealPath();
                            $relativePath = substr($filePath, strlen($dir) + 1);
                            if (strpos($relativePath, 'backups' . DIRECTORY_SEPARATOR) === 0 || strpos($relativePath, 'backups/') === 0) continue;
                            $zip->addFile($filePath, $relativePath);
                        }
                    }
                    $zip->close();
                    
                    $this->writeLog($currentUser, 'Full Export', 'Exported all data');
                    header('Content-Type: application/zip');
                    header('Content-Disposition: attachment; filename="cms_alldata_' . date('Ymd_His') . '.zip"');
                    header('Content-Length: ' . filesize($zipFile));
                    readfile($zipFile);
                    @unlink($zipFile);
                    exit;
                }
            }
            $this->renderErrorPage(500, $baseUrl, "ZIPエクスポートに失敗しました。", $adminHead);
        }

        if ($path === 'cms/backups/import' && $currentUser['role'] === 'admin') {
            if ($method === 'POST' && isset($_FILES['import_file'])) {
                $file = $_FILES['import_file'];
                if ($file['error'] === UPLOAD_ERR_OK && strtolower(pathinfo($file['name'], PATHINFO_EXTENSION)) === 'zip') {
                    $zip = new ZipArchive();
                    if ($zip->open($file['tmp_name']) === true) {
                        $zip->extractTo(DATA_DIR);
                        $zip->close();
                        $this->writeLog($currentUser, 'Full Import', 'Restored all data from ZIP');
                        header("Location: {$baseUrl}cms/backups?msg=imported");
                        exit;
                    }
                }
            }
            $this->renderErrorPage(500, $baseUrl, "インポートに失敗しました。ZIPファイルが正しいか確認してください。", $adminHead);
        }

        if ($path === 'cms/backups' && $currentUser['role'] === 'admin') {
            echo $adminHead . "<h1>復元と入出力</h1>";
            if (isset($_GET['msg']) && $_GET['msg'] === 'imported') echo "<div class='alert' role='alert'>全データのインポートが完了しました。システムが更新されました。</div>";
            $backupDir = __DIR__ . '/../data/backups';
            if ($method === 'POST' && !empty($_POST['restore_file']) && !empty($_POST['original_file'])) {
                $restorePath = $backupDir . '/' . $_POST['restore_file'];
                $originalPath = __DIR__ . '/../data/' . $_POST['original_file'];
                if (file_exists($restorePath)) {
                    if (file_exists($originalPath)) {
                        $tempBackup = $backupDir . '/' . dirname($_POST['original_file']) . '/' . date('Ymd_His') . '_prerestore_' . basename($_POST['original_file']);
                        @mkdir(dirname($tempBackup), 0777, true);
                        copy($originalPath, $tempBackup);
                    }
                    copy($restorePath, $originalPath);
                    $this->writeLog($currentUser, 'Restore Backup', "Restored {$_POST['original_file']} from {$_POST['restore_file']}");
                    echo "<div class='alert' role='alert'>ファイルの復元が完了しました。</div>";
                }
            }

            echo "<section style='margin-bottom: 30px; padding: 20px; border: 1px solid #ced4da; background: #fff; border-radius: 4px;'>";
            echo "<h2 style='margin-top: 0; border-bottom: none;'>全データの入出力 (バックアップ/移行用)</h2>";
            echo "<p style='color: #666; font-size: 0.9em; margin-bottom: 15px;'>現在のシステム内の全データ（ユーザー、記事、設定、テンプレート等）をZIP形式でダウンロードしたり、アップロードして復元することができます。<br>※サーバー負荷・容量削減のため、下部の「世代バックアップデータ」はZIPに含まれません。</p>";
            echo "<div style='display: flex; gap: 20px; align-items: flex-start;'>";
            echo "<div style='flex: 1; border-right: 1px solid #ddd; padding-right: 20px;'>";
            echo "<h3 style='margin-top:0; font-size:1.1rem;'>エクスポート (出力)</h3>";
            echo "<form method='POST' action='{$baseUrl}cms/backups/export' onsubmit='return confirm(\"全データをZIPファイルとしてダウンロードしますか？\");'>";
            echo "<button type='submit' class='btn' style='background:#28a745; width:100%;'>全データをダウンロード (.zip)</button>";
            echo "</form></div>";
            echo "<div style='flex: 1; padding-left: 10px;'>";
            echo "<h3 style='margin-top:0; font-size:1.1rem;'>インポート (入力)</h3>";
            echo "<form method='POST' action='{$baseUrl}cms/backups/import' enctype='multipart/form-data' onsubmit='return confirm(\"【重大な警告】\\n現在のシステムのデータが全て上書きされます！\\n元の状態には戻せません。\\n\\n本当にインポートを実行しますか？\");'>";
            echo "<input type='file' name='import_file' accept='.zip' required style='margin-bottom: 10px; width:100%; border:none; padding:0;'>";
            echo "<button type='submit' class='btn' style='background:#dc3545; width:100%;'>全データを上書き復元する</button>";
            echo "</form></div></div></section>";

            echo "<h2>自動世代バックアップからの個別復元</h2>";
            echo "<p>ファイルが変更・削除される直前の状態が自動保存されています。復元ボタンを押すと、その時点の状態に戻ります。</p>";
            
            $backupFiles = [];
            if (is_dir($backupDir)) {
                $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($backupDir, RecursiveDirectoryIterator::SKIP_DOTS));
                foreach ($iterator as $file) {
                    if ($file->isFile()) {
                        $relPath = str_replace(str_replace('\\', '/', $backupDir) . '/', '', str_replace('\\', '/', $file->getPathname()));
                        $basename = $file->getBasename();
                        if (preg_match('/^(\d{8}_\d{6})_(.+)$/', $basename, $matches)) {
                            $timeStr = $matches[1];
                            $originalBasename = $matches[2];
                            $originalDir = str_replace('\\', '/', dirname($relPath));
                            $originalFile = ($originalDir === '.' ? '' : $originalDir . '/') . $originalBasename;
                            $time = DateTime::createFromFormat('Ymd_His', $timeStr);
                            $timeFormatted = $time ? $time->format('Y-m-d H:i:s') : $timeStr;
                            $backupFiles[] = [ 'backup_file' => $relPath, 'original_file' => $originalFile, 'time' => $timeFormatted, 'timestamp' => $file->getMTime() ];
                        }
                    }
                }
            }
            usort($backupFiles, function($a, $b) { return $b['timestamp'] <=> $a['timestamp']; });
            
            echo "<table><thead><tr><th>対象ファイル (元の名前)</th><th>バックアップ日時</th><th>操作</th></tr></thead><tbody>";
            foreach ($backupFiles as $bf) {
                echo "<tr><td>" . htmlspecialchars($bf['original_file']) . "</td><td>" . htmlspecialchars($bf['time']) . "</td><td>";
                echo "<form method='POST' onsubmit='return confirm(\"本当にこの日時の状態に復元しますか？（現在の状態は失われます）\")' style='margin:0;'>";
                echo "<input type='hidden' name='restore_file' value='" . htmlspecialchars($bf['backup_file']) . "'>";
                echo "<input type='hidden' name='original_file' value='" . htmlspecialchars($bf['original_file']) . "'>";
                echo "<button type='submit' class='btn' style='padding:5px 10px; font-size:0.9em; background:#ffc107; color:#212529;'>復元する</button>";
                echo "</form></td></tr>";
            }
            if (empty($backupFiles)) echo "<tr><td colspan='3'>バックアップデータはありません。</td></tr>";
            echo "</tbody></table></main></body></html>"; return;
        }

        if ($path === 'cms/users' && $currentUser['role'] === 'admin') {
            $search = $_GET['search'] ?? '';
            $batchMessage = '';
            if ($method === 'POST' && !empty($_POST['batch_action']) && !empty($_POST['user_ids'])) {
                $action = $_POST['batch_action'];
                foreach ($_POST['user_ids'] as $targetId) {
                    if ($targetId === $currentUser['id']) {
                        if ($action === 'delete') { $batchMessage = "※ログイン中の自分自身は削除から除外されました。"; continue; }
                        if ($action === 'lock') { $batchMessage = "※ログイン中の自分自身は一時停止できません。"; continue; }
                        if (in_array($action, ['special', 'general'])) { $batchMessage = "※自分自身の権限を降格させることはできません。"; continue; }
                    }
                    if ($action === 'delete') $this->userModel->delete($targetId);
                    elseif (in_array($action, ['admin', 'special', 'general'])) { $u = $this->userModel->findById($targetId); if ($u) { $u['role'] = $action; $this->userModel->save($u); } }
                    elseif ($action === 'lock') { $u = $this->userModel->findById($targetId); if ($u) { $u['is_locked'] = true; $this->userModel->save($u); } }
                    elseif ($action === 'unlock') { $u = $this->userModel->findById($targetId); if ($u) { $u['is_locked'] = false; $this->userModel->save($u); } }
                }
                $this->writeLog($currentUser, 'Batch Action', "Action: {$action}");
                if (empty($batchMessage)) { header("Location: {$baseUrl}cms/users"); exit; }
            }
            
            echo $adminHead . "<h1>ユーザー管理</h1>";
            if ($batchMessage) echo "<div class='alert alert-error'>$batchMessage</div>";

            echo "<div style='display:flex; justify-content:space-between; align-items:flex-end; margin-bottom:20px; background:#f8f9fa; padding:15px; border-radius:4px; border:1px solid #dee2e6;'><form method='GET' style='margin:0;'>";
            echo "<label for='search'>ユーザー検索:</label><br><input type='text' id='search' name='search' value='".htmlspecialchars($search)."' style='width:250px; margin-bottom:0;'> <button type='submit' class='btn'>検索</button>";
            if ($search) echo " <a href='{$baseUrl}cms/users' style='margin-left:10px;'>クリア</a>";
            echo "</form><div><a href='{$baseUrl}cms/users/edit' class='btn' style='background:#28a745;'>+ 手動でユーザー登録</a></div></div>";
            
            echo "<section aria-labelledby='csv-upload-title'><h2 id='csv-upload-title' style='font-size:1.2rem;'>CSVから一括登録</h2><form method='POST' action='{$baseUrl}cms/users/csv_upload' enctype='multipart/form-data'><fieldset>";
            echo "<label for='csv_file'>CSVファイルを選択:</label> <input type='file' id='csv_file' name='csv_file' accept='.csv' required style='margin-right:15px;'>";
            echo "<label>文字コード: <select name='encoding'><option value='auto'>自動判定</option><option value='SJIS-win'>Shift-JIS (Windows等)</option><option value='UTF-8'>UTF-8</option><option value='EUC-JP'>EUC-JP</option></select></label> ";
            echo "<button type='submit' class='btn' style='margin-left:15px;'>読込・列指定へ</button></fieldset></form></section>";
            
            $users = $this->userModel->getAll();
            if ($search) $users = array_filter($users, function($u) use ($search) { return (stripos($u['student_id'] ?? '', $search) !== false) || (stripos($u['name'] ?? '', $search) !== false); });
            
            echo "<section aria-labelledby='user-list-title'><h2 id='user-list-title' style='font-size:1.2rem;'>ユーザー一覧</h2><form method='POST'><div style='margin-bottom:10px;'><select name='batch_action' required><option value=''>-- 選択 --</option><option value='admin'>管理者にする</option><option value='special'>特別部員にする</option><option value='general'>一般部員にする</option><option value='lock'>アカウントを一時停止する</option><option value='unlock'>アカウントの停止を解除する</option><option value='delete'>削除する</option></select> <button type='submit' class='btn'>一括適用</button></div>";
            echo "<table><thead><tr><th><input type='checkbox' onclick=\"document.querySelectorAll('input[name^=user_ids]').forEach(cb => cb.checked = this.checked)\"></th><th>学籍番号/ID</th><th>名前</th><th>学年</th><th>権限</th><th>状態</th><th>操作</th></tr></thead><tbody>";
            foreach ($users as $u) {
                $grade = !empty($u['grade']) ? $u['grade'] : $this->userModel->calculateGrade($u['student_id']);
                $status = !empty($u['is_locked']) ? "<span style='color:#dc3545; font-weight:bold;'>停止中</span>" : "<span style='color:#28a745;'>有効</span>";
                echo "<tr><td><input type='checkbox' name='user_ids[]' value='" . htmlspecialchars($u['id']) . "'></td><td>" . htmlspecialchars($u['student_id']) . "</td><td>" . htmlspecialchars($u['name'] ?? '') . "</td><td>" . htmlspecialchars($grade) . "</td><td>" . htmlspecialchars($u['role']) . "</td><td>{$status}</td><td><a href='{$baseUrl}cms/users/edit?id=" . urlencode($u['id']) . "'>編集</a></td></tr>";
            }
            echo "</tbody></table></form></section></main></body></html>"; return;
        }

        if ($path === 'cms/users/edit' && $currentUser['role'] === 'admin') {
            $id = $_GET['id'] ?? '';
            $formData = ['id' => '', 'student_id' => '', 'name' => '', 'email' => '', 'grade' => '', 'role' => 'general'];
            $error = '';
            
            if ($id && $method !== 'POST') {
                $existingData = $this->userModel->findById($id);
                if ($existingData) $formData = array_merge($formData, $existingData);
            }

            if ($method === 'POST') {
                $formData = $_POST; 
                $student_id = trim($formData['student_id'] ?? '');

                if (empty($student_id)) {
                    $error = '学籍番号/IDは必須です。';
                } else {
                    $existingUser = $this->userModel->findByStudentId($student_id);
                    if ($existingUser && $existingUser['id'] !== $id) {
                        $error = 'この学籍番号/IDは既に登録されています。';
                    } else {
                        if ($id === $currentUser['id'] && $formData['role'] !== 'admin') {
                            $error = '自分自身の権限を降格させることはできません。';
                        } else {
                            if (!empty($_POST['password'])) {
                                $formData['password_hash'] = password_hash($_POST['password'], PASSWORD_DEFAULT);
                            }
                            unset($formData['password']); 

                            if ($this->userModel->save($formData)) {
                                $this->writeLog($currentUser, 'User Saved', "User ID: {$student_id}");
                                header("Location: {$baseUrl}cms/users"); exit;
                            } else {
                                $error = '保存に失敗しました。';
                            }
                        }
                    }
                }
            }

            echo $adminHead . "<h1>ユーザー" . ($id ? "編集" : "登録") . "</h1>";
            if ($error) echo "<div class='alert alert-error'>$error</div>";
            echo "<form method='POST'>";
            if ($id) echo "<input type='hidden' name='id' value='".htmlspecialchars($formData['id'])."'>";
            
            echo "<fieldset><legend>ユーザー情報</legend>";
            echo "<label>学籍番号/ID <span style='color:red;'>*</span></label><input type='text' name='student_id' value='".htmlspecialchars($formData['student_id'])."' required>";
            echo "<label>名前</label><input type='text' name='name' value='".htmlspecialchars($formData['name'] ?? '')."'>";
            echo "<label>メールアドレス</label><input type='email' name='email' value='".htmlspecialchars($formData['email'] ?? '')."'>";
            echo "<label>学年（空欄の場合は自動計算）</label><input type='text' name='grade' value='".htmlspecialchars($formData['grade'] ?? '')."'>";
            echo "<label>権限</label><select name='role' required>";
            echo "<option value='general' ".(($formData['role'] === 'general')?'selected':'').">一般部員</option>";
            echo "<option value='special' ".(($formData['role'] === 'special')?'selected':'').">特別部員</option>";
            echo "<option value='admin' ".(($formData['role'] === 'admin')?'selected':'').">管理者</option>";
            echo "</select>";
            echo "<label>パスワード " . ($id ? "(変更する場合のみ入力)" : "<span style='color:red;'>*</span>") . "</label>";
            echo "<input type='password' name='password' " . ($id ? "" : "required") . ">";
            echo "</fieldset>";
            echo "<button type='submit' class='btn'>保存する</button> <a href='{$baseUrl}cms/users' class='btn' style='background:#6c757d;'>キャンセル</a></form></main></body></html>";
            return;
        }

        if ($path === 'cms/users/csv_upload' && $currentUser['role'] === 'admin') {
            if ($method === 'POST' && isset($_FILES['csv_file'])) {
                $file = $_FILES['csv_file'];
                if ($file['error'] === UPLOAD_ERR_OK) {
                    $encoding = $_POST['encoding'] ?? 'auto';
                    $fromEncoding = ($encoding === 'auto') ? 'ASCII,JIS,UTF-8,EUC-JP,SJIS-win' : $encoding;
                    $tmpName = $file['tmp_name'];
                    $csvData = file_get_contents($tmpName);
                    $csvData = mb_convert_encoding($csvData, 'UTF-8', $fromEncoding);
                    $lines = explode("\n", str_replace(array("\r\n","\r"), "\n", $csvData));
                    
                    $parsedData = [];
                    foreach ($lines as $line) {
                        if (trim($line) === '') continue;
                        $parsedData[] = str_getcsv($line, ",", "\"", "\\");
                    }
                    
                    if (count($parsedData) > 0) {
                        $_SESSION['csv_import_data'] = $parsedData;
                        header("Location: {$baseUrl}cms/users/csv_map"); exit;
                    }
                }
            }
            $this->renderErrorPage(400, $baseUrl, "CSVファイルのアップロードに失敗しました。", $adminHead);
        }

        if ($path === 'cms/users/csv_map' && $currentUser['role'] === 'admin') {
            if (!isset($_SESSION['csv_import_data'])) { header("Location: {$baseUrl}cms/users"); exit; }
            $csvData = $_SESSION['csv_import_data'];
            $firstRow = $csvData[0];

            if ($method === 'POST') {
                $map = $_POST['map'] ?? [];
                $hasHeader = isset($_POST['has_header']);
                $defaultRole = $_POST['default_role'] ?? 'general';
                $duplicateAction = $_POST['duplicate_action'] ?? 'skip';
                
                $passRule = $_POST['pass_rule'] ?? 'same_as_id';
                $passFixedVal = $_POST['pass_fixed_val'] ?? 'password123';
                $passPrefixVal = $_POST['pass_prefix_val'] ?? '';
                $passSuffixVal = $_POST['pass_suffix_val'] ?? '';

                $importedCount = 0;

                foreach ($csvData as $index => $row) {
                    if ($hasHeader && $index === 0) continue;
                    
                    $studentId = ($map['student_id'] !== '' && isset($row[$map['student_id']])) ? trim($row[$map['student_id']]) : '';
                    if (empty($studentId)) continue;
                    
                    $name = ($map['name'] !== '' && isset($row[$map['name']])) ? trim($row[$map['name']]) : '';
                    $email = ($map['email'] !== '' && isset($row[$map['email']])) ? trim($row[$map['email']]) : '';
                    $role = ($map['role'] !== '' && isset($row[$map['role']])) ? trim($row[$map['role']]) : '';
                    $grade = ($map['grade'] !== '' && isset($row[$map['grade']])) ? trim($row[$map['grade']]) : '';
                    $password = ($map['password'] !== '' && isset($row[$map['password']])) ? trim($row[$map['password']]) : '';

                    if (!in_array($role, ['admin', 'special', 'general'])) $role = $defaultRole;
                    
                    $existing = array_filter($this->userModel->getAll(), function($u) use ($studentId) { return $u['student_id'] === $studentId; });
                    $existingUser = reset($existing);
                    
                    if ($existingUser) {
                        if ($duplicateAction === 'skip') continue; 
                        $userData = $existingUser; 
                    } else {
                        $userData = ['student_id' => $studentId];
                    }
                    
                    $userData['name'] = $name;
                    $userData['email'] = $email;
                    $userData['grade'] = $grade;
                    $userData['role'] = $role;
                    
                    if ($password !== '') {
                        $userData['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
                    } elseif (empty($userData['password_hash'])) {
                        $genPass = '';
                        if ($passRule === 'fixed') $genPass = $passFixedVal !== '' ? $passFixedVal : 'password123';
                        elseif ($passRule === 'prefix') $genPass = $passPrefixVal . $studentId;
                        elseif ($passRule === 'suffix') $genPass = $studentId . $passSuffixVal;
                        else $genPass = $studentId;
                        $userData['password_hash'] = password_hash($genPass, PASSWORD_DEFAULT);
                    }
                    
                    $this->userModel->save($userData);
                    $importedCount++;
                }
                unset($_SESSION['csv_import_data']);
                $this->writeLog($currentUser, 'CSV Import', "{$importedCount} users imported/updated");
                echo $adminHead . "<h1>インポート完了</h1><p>{$importedCount} 件のユーザーを処理しました。</p><a href='{$baseUrl}cms/users' class='btn'>ユーザー一覧に戻る</a></main></body></html>";
                return;
            }

            echo $adminHead . "<h1>CSV列の割り当て</h1>";
            echo "<p>読み込んだCSVデータのどの列を、システムのどの項目に割り当てるか指定してください。</p>";
            echo "<form method='POST'><fieldset>";
            echo "<label><input type='checkbox' name='has_header' value='1' checked> 1行目はヘッダーとして無視する</label><br><br>";
            
            echo "<div style='background:#fff3cd; padding:15px; border-radius:4px; margin-bottom:20px; border:1px solid #ffeeba;'>";
            echo "<label style='font-weight:bold;'>既存データと学籍番号/IDが重複した場合の処理:</label><br>";
            echo "<label><input type='radio' name='duplicate_action' value='skip' checked> スキップする（既存のデータを優先して守る）</label><br>";
            echo "<label><input type='radio' name='duplicate_action' value='overwrite'> 上書きする（CSVの新しいデータで更新する）</label></div>";

            echo "<div style='background:#e9ecef; padding:15px; border-radius:4px; margin-bottom:20px;'>";
            echo "<label for='default_role'>CSVに権限データがない場合のデフォルト権限:</label><br>";
            echo "<select id='default_role' name='default_role' style='width:auto;'><option value='general'>一般部員</option><option value='special'>特別部員</option><option value='admin'>管理者</option></select></div>";

            echo "<div style='background:#e2e3e5; padding:15px; border-radius:4px; margin-bottom:20px; border:1px solid #d6d8db;'>";
            echo "<label style='font-weight:bold;'>【新規登録】パスワード列を割り当てない（またはセルが空欄）場合の自動設定:</label><br>";
            echo "<label style='display:block; margin-bottom:8px;'><input type='radio' name='pass_rule' value='same_as_id' checked> 学籍番号/ID と同じにする</label>";
            echo "<label style='display:block; margin-bottom:8px;'><input type='radio' name='pass_rule' value='prefix'> 先頭に文字を付ける (例: cms12345) <input type='text' name='pass_prefix_val' placeholder='cms' style='width:100px; padding:4px;'></label>";
            echo "<label style='display:block; margin-bottom:8px;'><input type='radio' name='pass_rule' value='suffix'> 末尾に文字を付ける (例: 12345abc) <input type='text' name='pass_suffix_val' placeholder='abc' style='width:100px; padding:4px;'></label>";
            echo "<label style='display:block; margin-bottom:0;'><input type='radio' name='pass_rule' value='fixed'> 全員共通のパスワードにする <input type='text' name='pass_fixed_val' value='password123' style='width:150px; padding:4px;'></label></div>";

            $fields = [
                'student_id' => ['label' => '学籍番号/ID (必須)', 'required' => true],
                'name' => ['label' => '名前', 'required' => false],
                'email' => ['label' => 'メールアドレス', 'required' => false],
                'role' => ['label' => '権限 (admin/special/general)', 'required' => false],
                'grade' => ['label' => '学年 (任意)', 'required' => false],
                'password' => ['label' => 'パスワード (任意)', 'required' => false]
            ];

            foreach ($fields as $key => $conf) {
                $reqAttr = $conf['required'] ? 'required' : '';
                $defaultOption = $conf['required'] ? '-- 選択してください --' : '-- 割り当てない --';
                echo "<label>{$conf['label']}: <select name='map[{$key}]' {$reqAttr}><option value=''>{$defaultOption}</option>";
                foreach ($firstRow as $i => $val) {
                    $preview = mb_strlen($val) > 20 ? mb_substr($val, 0, 20) . '...' : $val;
                    echo "<option value='{$i}'>列 " . ($i + 1) . " (例: " . htmlspecialchars($preview) . ")</option>";
                }
                echo "</select></label><br><br>";
            }

            echo "<button type='submit' class='btn'>インポートを実行</button> <a href='{$baseUrl}cms/users' class='btn' style='background:#6c757d;'>キャンセル</a></fieldset></form></main></body></html>";
            return;
        }

        // ==========================================
        // カテゴリ管理
        // ==========================================
        if ($path === 'cms/categories' && $isAdminOrSpecial) {
            if ($method === 'POST') {
                if (isset($_POST['delete_id'])) {
                    $this->contentModel->deleteCategory($_POST['delete_id']);
                } else {
                    $this->contentModel->saveCategory([
                        'id' => $_POST['id']??'', 
                        'name' => trim($_POST['name']??''),
                        'color' => $_POST['color'] ?? '#007bff'
                    ]);
                }
                header("Location: {$baseUrl}cms/categories"); exit;
            }
            
            echo $adminHead . "<h1>カテゴリ管理</h1>";
            echo "<fieldset><legend>新規作成</legend><form method='POST' style='display:flex;gap:10px;align-items:center;'>";
            echo "<input type='color' name='color' value='#007bff' style='width:50px;height:40px;padding:0;cursor:pointer;' title='カテゴリの色'>";
            echo "<input type='text' name='name' placeholder='カテゴリ名' required style='margin-bottom:0;'><button type='submit' class='btn'>追加</button></form></fieldset>";
            
            $cats = $this->contentModel->getCategories();
            echo "<table><thead><tr><th>カテゴリ名</th><th>操作</th></tr></thead><tbody>";
            foreach ($cats as $c) {
                $col = htmlspecialchars($c['color'] ?? '#007bff');
                echo "<tr><td><form method='POST' style='margin:0;display:flex;gap:10px;align-items:center;'><input type='hidden' name='id' value='{$c['id']}'>";
                echo "<input type='color' name='color' value='{$col}' style='width:50px;height:40px;padding:0;cursor:pointer;'>";
                echo "<input type='text' name='name' value='".htmlspecialchars($c['name'])."' required style='margin-bottom:0;'><button type='submit' class='btn' style='padding:5px 10px; font-size:0.9em;'>更新</button></form></td>";
                echo "<td><form method='POST' style='margin:0;' onsubmit='return confirm(\"削除しますか？\");'><input type='hidden' name='delete_id' value='{$c['id']}'><button type='submit' class='btn' style='background:#dc3545; padding:5px 10px; font-size:0.9em;'>削除</button></form></td></tr>";
            }
            if (empty($cats)) echo "<tr><td colspan='2'>カテゴリはまだありません。</td></tr>";
            echo "</tbody></table></main></body></html>"; return;
        }

        // ==========================================
        // システム設定・テンプレート
        // ==========================================
        if ($path === 'cms/templates' && $isAdminOrSpecial) {
            $message = '';
            if ($method === 'POST') {
                $this->templateModel->save('header.html', $_POST['header']);
                $this->templateModel->save('footer.html', $_POST['footer']);
                $this->templateModel->save('style.css', $_POST['css']);
                
                $newSettings = $settings;
                $newSettings['variables'] = $_POST['variables'];
                $newSettings['seo_description'] = $_POST['seo_description'];
                $newSettings['seo_keywords'] = $_POST['seo_keywords'];
                $newSettings['custom_head'] = $_POST['custom_head'];
                $newSettings['blog_title_format'] = $_POST['blog_title_format'];
                $newSettings['page_slash_policy'] = $_POST['page_slash_policy'] ?? 'as_is';
                $newSettings['blog_slash_policy'] = $_POST['blog_slash_policy'] ?? 'as_is';
                $newSettings['blog_layout'] = $_POST['blog_layout'] ?? '';
                
                if ($currentUser['role'] === 'admin') {
                    $newSettings['backup_retention_count'] = (int)($_POST['backup_retention_count'] ?? 10);
                    $newSettings['log_max_lines'] = (int)($_POST['log_max_lines'] ?? 1000);
                    $newSettings['blog_category_enabled'] = !empty($_POST['blog_category_enabled']);
                    $newSettings['blog_category_required'] = !empty($_POST['blog_category_required']);
                    $newSettings['blog_tag_enabled'] = !empty($_POST['blog_tag_enabled']);
                    $newSettings['blog_latest_count'] = (int)($_POST['blog_latest_count'] ?? 5);
                    $newSettings['site_search_enabled'] = !empty($_POST['site_search_enabled']);
                    $newSettings['blog_date_type'] = $_POST['blog_date_type'] ?? 'updated_at';
                    $newSettings['allow_user_email_change'] = !empty($_POST['allow_user_email_change']);
                    
                    $newSettings['upload_allow_general'] = !empty($_POST['upload_allow_general']);
                    $newSettings['upload_allowed_exts'] = trim($_POST['upload_allowed_exts'] ?? 'jpg, jpeg, png, gif, webp, pdf, zip, txt');
                    $newSettings['upload_max_mb'] = (float)($_POST['upload_max_mb'] ?? 5);
                    $newSettings['upload_webp_enable'] = !empty($_POST['upload_webp_enable']);
                    $newSettings['upload_max_px'] = (int)($_POST['upload_max_px'] ?? 1200);
                    $newSettings['upload_webp_quality'] = max(1, min(100, (int)($_POST['upload_webp_quality'] ?? 80)));
                }
                
                $this->saveSettings($newSettings);
                $this->writeLog($currentUser, 'Settings Saved', 'テンプレート・設定を更新しました');
                $message = "設定を保存しました。";
                $settings = $this->getSettings();
            }
            
            echo $adminHead . "<h1>システム設定・テンプレート管理</h1>";
            if ($message) echo "<div class='alert' role='alert'>$message</div>";
            echo "<form method='POST'>";
            
            if ($currentUser['role'] === 'admin') {
                echo "<fieldset><legend>ブログ・全体機能拡張 (管理者のみ)</legend>";
                echo "<label><input type='checkbox' name='site_search_enabled' value='1' ".(!empty($settings['site_search_enabled'])?'checked':'')."> サイト内全体検索機能を有効にする</label><br><br>";
                echo "<label><input type='checkbox' name='allow_user_email_change' value='1' ".(!empty($settings['allow_user_email_change'])?'checked':'')."> 一般ユーザーによる自身のメールアドレス変更を許可する</label><br><br>";
                echo "<label><input type='checkbox' name='blog_category_enabled' value='1' ".(!empty($settings['blog_category_enabled'])?'checked':'')."> カテゴリ機能を有効にする</label><br>";
                echo "<label><input type='checkbox' name='blog_category_required' value='1' ".(!empty($settings['blog_category_required'])?'checked':'')."> 記事作成時にカテゴリ付けを必須にする</label><br><br>";
                echo "<label><input type='checkbox' name='blog_tag_enabled' value='1' ".(!empty($settings['blog_tag_enabled'])?'checked':'')."> タグ機能を有効にする</label><br><br>";
                
                $dt = $settings['blog_date_type'] ?? 'updated_at';
                echo "<label>ブログの基準日時: <select name='blog_date_type' style='width:auto; display:inline-block;'><option value='updated_at' ".($dt==='updated_at'?'selected':'').">更新日時</option><option value='created_at' ".($dt==='created_at'?'selected':'').">作成日時</option></select></label><br><br>";
                echo "<label>最新記事として独立表示する件数: <input type='number' name='blog_latest_count' value='".htmlspecialchars($settings['blog_latest_count'] ?? 5)."' min='1' max='50' style='width:80px; display:inline-block;'></label>";
                echo "</fieldset>";

                echo "<fieldset><legend>ファイルアップロード・画像設定 (管理者のみ)</legend>";
                echo "<label><input type='checkbox' name='upload_allow_general' value='1' ".(!empty($settings['upload_allow_general'])?'checked':'')."> 一般ユーザー(特別部員・一般部員)のファイルアップロードを許可する</label><br><br>";
                echo "<label>許可する拡張子 (カンマ区切り)</label>";
                echo "<input type='text' name='upload_allowed_exts' value='".htmlspecialchars($settings['upload_allowed_exts'] ?? 'jpg, jpeg, png, gif, webp, pdf, zip, txt')."'>";
                echo "<label>最大ファイルサイズ (MB)</label>";
                echo "<input type='number' name='upload_max_mb' value='".htmlspecialchars($settings['upload_max_mb'] ?? 5)."' min='1' max='100'>";
                echo "<label><input type='checkbox' name='upload_webp_enable' value='1' ".(!empty($settings['upload_webp_enable'])?'checked':'')."> 画像アップロード時に自動でWebPに変換・圧縮する (クライアントサイド処理)</label><br><br>";
                echo "<label>画像圧縮時の最大長辺サイズ (px)</label>";
                echo "<input type='number' name='upload_max_px' value='".htmlspecialchars($settings['upload_max_px'] ?? 1200)."' min='100' max='4000'>";
                echo "<label>WebP変換時の品質 (1〜100、推奨: 80)</label>";
                echo "<input type='number' name='upload_webp_quality' value='".htmlspecialchars($settings['upload_webp_quality'] ?? 80)."' min='1' max='100'>";
                echo "</fieldset>";

                echo "<fieldset><legend>システム保存設定 (管理者のみ)</legend>";
                echo "<label>自動バックアップ保存件数</label><input type='number' name='backup_retention_count' value='".htmlspecialchars($settings['backup_retention_count'] ?? 10)."' min='0' step='1'>";
                echo "<label>システムログ保存行数</label><input type='number' name='log_max_lines' value='".htmlspecialchars($settings['log_max_lines'] ?? 1000)."' min='0' step='1'>";
                echo "</fieldset>";
            }

            echo "<fieldset><legend>カスタム変数 (スニペット)</legend>";
            echo "<textarea id='variables' name='variables' style='height:100px; font-family:monospace;'>".htmlspecialchars($settings['variables'] ?? '')."</textarea></fieldset>";

            echo "<fieldset><legend>SEO・アクセス解析設定</legend>";
            echo "<label>ブログ記事の&lt;title&gt;形式</label><input type='text' name='blog_title_format' value='".htmlspecialchars($settings['blog_title_format'] ?? '{{title}}')."'>";
            echo "<label>デフォルト Meta Description</label><textarea name='seo_description' style='height:80px;'>".htmlspecialchars($settings['seo_description'] ?? '')."</textarea>";
            echo "<label>デフォルト Meta Keywords</label><textarea name='seo_keywords' style='height:60px;'>".htmlspecialchars($settings['seo_keywords'] ?? '')."</textarea>";
            echo "<label>カスタム Head タグ</label><textarea name='custom_head' style='height:120px; font-family:monospace;'>".htmlspecialchars($settings['custom_head'] ?? '')."</textarea></fieldset>";

            echo "<fieldset><legend>URLの末尾スラッシュ (Trailing Slash) 設定</legend>";
            echo "<label>通常ページ（全体設定）</label>";
            echo "<select name='page_slash_policy'>";
            echo "<option value='as_is' ".((($settings['page_slash_policy']??'') === 'as_is')?'selected':'').">統一しない (どちらでもアクセス可能)</option>";
            echo "<option value='none' ".((($settings['page_slash_policy']??'') === 'none')?'selected':'').">スラッシュなしに統一 (例: /about)</option>";
            echo "<option value='slash' ".((($settings['page_slash_policy']??'') === 'slash')?'selected':'').">スラッシュありに統一 (例: /about/)</option>";
            echo "</select>";
            
            echo "<label>ブログ記事一覧・個別記事</label>";
            echo "<select name='blog_slash_policy'>";
            echo "<option value='none' ".((($settings['blog_slash_policy']??'none') === 'none')?'selected':'').">スラッシュなしに統一 (例: /blog/article)</option>";
            echo "<option value='slash' ".((($settings['blog_slash_policy']??'') === 'slash')?'selected':'').">スラッシュありに統一 (例: /blog/article/)</option>";
            echo "<option value='as_is' ".((($settings['blog_slash_policy']??'') === 'as_is')?'selected':'').">統一しない</option>";
            echo "</select></fieldset>";

            echo "<fieldset><legend>デザインテンプレート</legend>";
            echo "<label>ヘッダー</label><textarea name='header' style='height:150px; font-family:monospace;'>" . htmlspecialchars($this->templateModel->get('header.html')) . "</textarea>";
            echo "<label>フッター</label><textarea name='footer' style='height:100px; font-family:monospace;'>" . htmlspecialchars($this->templateModel->get('footer.html')) . "</textarea>";
            echo "<label>共通CSS</label><textarea name='css' style='height:150px; font-family:monospace;'>" . htmlspecialchars($this->templateModel->get('style.css')) . "</textarea>";
            
            $defaultBlogLayout = <<<HTML
<main>
    <article>
        <h1 style='margin-bottom: 5px;'>{{title}}</h1>
        <div style='color:#666; font-size:0.9em; margin-bottom:20px; border-bottom:1px solid #ccc; padding-bottom:10px;'>
            作成日: <time datetime='{{created_at}}'>{{created_at_date}}</time> {{updated_at_text}} {{category_html}} {{tags_html}}
        </div>
        <div id='md-content'></div>
    </article>
    <div style='display:flex; justify-content:space-between; margin-top:30px; padding-top:20px; border-top:1px solid #eee;'>
        <div>
            {{if_prev}}
                <a href="{{prev_url}}">&laquo; {{prev_title}}</a>
            {{/if_prev}}
        </div>
        <div>
            {{if_next}}
                <a href="{{next_url}}">{{next_title}} &raquo;</a>
            {{/if_next}}
        </div>
    </div>
</main>
HTML;
            $savedBlogLayout = $settings['blog_layout'] ?? $defaultBlogLayout;
            if (trim($savedBlogLayout) === '') $savedBlogLayout = $defaultBlogLayout;
            
            echo "<div style='margin-top:20px;'>";
            echo "<label style='font-weight:bold;'>ブログ記事詳細用HTMLテンプレート</label>";
            echo "<p style='font-size:0.9em;color:#666;margin-top:0;'>※必ず <code>&lt;div id='md-content'&gt;&lt;/div&gt;</code> を含めてください。条件分岐として <code>{{if_prev}}...{{/if_prev}}</code>、変数は <code>{{prev_url}}</code> <code>{{prev_title}}</code> などが使えます。</p>";
            echo "<textarea name='blog_layout' style='height:250px; font-family:monospace;'>" . htmlspecialchars($savedBlogLayout) . "</textarea>";
            echo "</div>";

            echo "</fieldset><button type='submit' class='btn'>保存する</button></form></main></body></html>";
            return;
        }

        // ==========================================
        // プロフィール設定
        // ==========================================
        if ($path === 'cms/profile') {
            $message = ''; $error = '';
            $allowEmailChange = !empty($settings['allow_user_email_change']);
            $userProfile = $this->userModel->findById($currentUser['id']);

            if ($method === 'POST') {
                $currentPass = $_POST['current_password'] ?? '';
                $newPass = $_POST['new_password'] ?? '';
                $newPassConf = $_POST['new_password_confirm'] ?? '';
                $newEmail = trim($_POST['email'] ?? '');

                $isCurrentPassValid = false;
                $backupSession = $_SESSION; 
                if ($this->auth->login($currentUser['student_id'], $currentPass) === true) $isCurrentPassValid = true;
                $_SESSION = $backupSession;

                if (!$isCurrentPassValid) {
                    $error = "現在のパスワードが間違っています。";
                } elseif ($newPass !== '' && $newPass !== $newPassConf) {
                    $error = "新しいパスワードと確認用パスワードが一致しません。";
                } elseif ($newPass !== '' && strlen($newPass) < 4) {
                    $error = "パスワードは短すぎます（4文字以上推奨）。";
                } else {
                    if ($newPass !== '') {
                        $this->userModel->changePassword($currentUser['id'], $newPass);
                    }
                    if ($allowEmailChange && $newEmail !== $userProfile['email']) {
                        $userProfile['email'] = $newEmail;
                        $this->userModel->save($userProfile);
                    }
                    
                    $this->writeLog($currentUser, 'Profile Updated', 'プロフィールを変更しました');
                    $message = "プロフィールを安全に変更しました。";
                }
            }
            
            echo $adminHead . "<h1>プロフィール設定</h1>";
            if ($message) echo "<div class='alert' role='alert'>$message</div>";
            if ($error) echo "<div class='alert alert-error' role='alert'>$error</div>";
            
            echo "<form method='POST'><fieldset><legend>ユーザー情報の変更</legend>";
            
            if ($allowEmailChange) {
                echo "<label for='email'>メールアドレス</label><input type='email' id='email' name='email' value='".htmlspecialchars($userProfile['email'] ?? '')."'>";
            } else {
                echo "<label>メールアドレス (現在は変更できません)</label><input type='email' value='".htmlspecialchars($userProfile['email'] ?? '')."' disabled style='background:#e9ecef;'>";
            }

            echo "<hr style='border-top:1px solid #dee2e6; margin:20px 0;'>";
            
            echo "<label for='new_password'>新しいパスワード <span style='color:#666; font-size:0.9em;'>(変更する場合のみ入力)</span></label><input type='password' id='new_password' name='new_password'>";
            echo "<label for='new_password_confirm'>新しいパスワード（確認用）</label><input type='password' id='new_password_confirm' name='new_password_confirm'>";
            
            echo "<hr style='border-top:1px solid #dee2e6; margin:20px 0;'>";
            
            echo "<label for='current_password'>現在のパスワード <span style='color:red;'>*</span> <span style='color:#666; font-size:0.9em;'>(保存の確認に必要です)</span></label><input type='password' id='current_password' name='current_password' required>";
            
            echo "<button type='submit' class='btn'>保存する</button></fieldset></form></main></body></html>";
            return;
        }

        if ($path === 'cms/contents/delete' && $method === 'POST') {
            if (!$isAdminOrSpecial) $this->renderErrorPage(403, $baseUrl, "権限がありません。", $adminHead);
            $id = $_POST['id'] ?? '';
            if ($id) {
                $content = $this->contentModel->getById($id);
                if ($content) {
                    $this->contentModel->delete($id);
                    $this->writeLog($currentUser, 'Content Deleted', "Title: {$content['title']}");
                }
            }
            header("Location: {$baseUrl}cms/contents"); exit;
        }

        if ($path === 'cms/contents') {
            echo $adminHead . "<h1>記事・ページ管理</h1>";
            $contents = $this->contentModel->getAll();
            $pages = array_filter($contents, function($c) { return $c['type'] === 'page'; });
            $blogs = array_filter($contents, function($c) { return $c['type'] === 'blog'; });
            $categories = $this->contentModel->getCategories();
            $dateField = $settings['blog_date_type'] ?? 'updated_at';

            if ($isAdminOrSpecial) {
                echo "<section><h2 id='pages-title'>サイト構造（通常ページ）</h2>";
                echo "<p style='font-size:0.9em;color:#666;margin-top:-10px;'>※スラッグを <code>404</code> や <code>403</code> などのステータスコードにすると、システムのエラーページとして自動的に使われます。</p>";
                echo "<a href='{$baseUrl}cms/contents/edit?type=page' class='btn'>+ 通常ページを新規作成</a><br><br>";
                usort($pages, function($a, $b) { return strcmp($a['slug'] ?? '', $b['slug'] ?? ''); });
                echo "<table><thead><tr><th>ページ階層 (URL)</th><th>タイトル</th><th>操作</th></tr></thead><tbody>";
                foreach ($pages as $p) {
                    $slug = $p['slug'] ?? '';
                    $indent = str_repeat("<span class='tree-indent'>└</span>", substr_count($slug, '/'));
                    echo "<tr><td>{$indent}/" . htmlspecialchars($slug) . "</td><td>" . htmlspecialchars($p['title']) . "</td>";
                    echo "<td><a href='{$baseUrl}cms/contents/edit?id=".urlencode($p['id'])."'>編集</a> <form action='{$baseUrl}cms/contents/delete' method='POST' style='display:inline;' onsubmit='return confirm(\"本当に削除しますか？\")'><input type='hidden' name='id' value='".htmlspecialchars($p['id'])."'><button type='submit' class='btn' style='background:#dc3545; padding:5px 10px; font-size:0.9em;'>削除</button></form></td></tr>";
                }
                echo "</tbody></table></section><hr>";
            }

            echo "<section><h2>ブログ記事</h2><a href='{$baseUrl}cms/contents/edit?type=blog' class='btn'>+ ブログを新規作成</a><br><br>";
            usort($blogs, function($a, $b) use ($dateField) { 
                $aDate = !empty($a[$dateField]) ? $a[$dateField] : ($a['updated_at'] ?? 'now');
                $bDate = !empty($b[$dateField]) ? $b[$dateField] : ($b['updated_at'] ?? 'now');
                return strtotime($bDate) <=> strtotime($aDate); 
            });
            echo "<table><thead><tr><th>タイトル</th><th>カテゴリ</th><th>タグ</th><th>URL</th><th>操作</th></tr></thead><tbody>";
            foreach ($blogs as $b) {
                $cName = '-';
                if (!empty($b['category_id'])) {
                    foreach($categories as $c) if($c['id'] === $b['category_id']) { $cName = $c['name']; break; }
                }
                $tags = !empty($b['tags']) ? implode(', ', $b['tags']) : '-';
                
                echo "<tr><td>" . htmlspecialchars($b['title']) . "</td><td>".htmlspecialchars($cName)."</td><td>".htmlspecialchars($tags)."</td><td>/blog/" . htmlspecialchars($b['slug'] ?? '') . "</td>";
                echo "<td><a href='{$baseUrl}cms/contents/edit?id=".urlencode($b['id'])."'>編集</a>";
                if ($isAdminOrSpecial) echo " <form action='{$baseUrl}cms/contents/delete' method='POST' style='display:inline;' onsubmit='return confirm(\"本当に削除しますか？\")'><input type='hidden' name='id' value='".htmlspecialchars($b['id'])."'><button type='submit' class='btn' style='background:#dc3545; padding:5px 10px; font-size:0.9em;'>削除</button></form>";
                echo "</td></tr>";
            }
            echo "</tbody></table></section></main></body></html>"; return;
        }

        // ==========================================
        // ★ 記事編集
        // ==========================================
        if ($path === 'cms/contents/edit') {
            $id = $_GET['id'] ?? '';
            $requestedType = $_GET['type'] ?? 'blog';
            $formData = [
                'id' => '', 'title' => '', 'slug' => '', 'type' => $requestedType, 'body' => '', 
                'meta_description' => '', 'version' => 1, 'author_id' => $currentUser['id'], 
                'slash_policy' => 'default', 'redirect_url' => '', 'custom_head' => '', 'custom_bottom' => ''
            ];
            $error = '';
            
            if ($method !== 'POST') {
                if (isset($_SESSION['recovery_post'])) {
                    $formData = array_merge($formData, $_SESSION['recovery_post']);
                    unset($_SESSION['recovery_post']);
                    $error = "セッションがタイムアウトしたため再ログインしました。編集中だったデータを復元しました（まだ保存されていません）。";
                } elseif ($id) {
                    $existingData = $this->contentModel->getById($id);
                    if ($existingData) {
                        $formData = array_merge($formData, $existingData);
                    }
                }
            }
            $isPage = ($formData['type'] === 'page');

            if ($method === 'POST') {
                $formData = $_POST;
                $formData['version'] = $_POST['base_version'] ?? 1;
                $formData['author_id'] = $currentUser['id'];
                $isPage = ($formData['type'] === 'page');

                if ($formData['type'] === 'blog' && empty($formData['slug'])) $formData['slug'] = date('YmdHis');
                
                if (isset($formData['tags'])) {
                    $tagArray = [];
                    foreach(explode(',', $formData['tags']) as $t) {
                        $t = trim($t);
                        if ($t !== '') $tagArray[] = $t;
                    }
                    $formData['tags'] = $tagArray;
                }
                
                $forbidden = ['data', 'src', 'views', 'assets', 'cms', 'api', 'login', 'logout', 'dashboard', 'index', 'blogs', 'blog', 'search'];
                $slugParts = explode('/', $formData['slug'] ?? '');
                $firstDir = strtolower($slugParts[0]);

                if ($isPage && in_array($firstDir, $forbidden)) {
                    $error = 'エラー: システムの予約名やディレクトリ（' . htmlspecialchars($firstDir) . '）はURLとして使用できません。別の名前を指定してください。';
                } else {
                    $slugDuplicate = false;
                    $allContents = $this->contentModel->getAll();
                    foreach ($allContents as $c) {
                        if ($c['slug'] === $formData['slug'] && $c['id'] !== $formData['id']) {
                            $slugDuplicate = true;
                            break;
                        }
                    }

                    if ($slugDuplicate) {
                        $error = 'エラー: 指定したURL階層/スラッグは既に使用されています。別の文字列を指定してください。';
                    } else {
                        $result = $this->contentModel->save($formData, (int)$_POST['base_version']);
                        if ($result['success']) { 
                            $this->writeLog($currentUser, 'Content Saved', "Title: {$formData['title']}");
                            header("Location: {$baseUrl}cms/contents"); exit; 
                        } else {
                            if ($result['error'] === 'conflict') {
                                $currentData = $result['current_data'];
                                echo $adminHead . "<h1>編集の競合が発生しました</h1>";
                                echo "<div class='alert alert-error'>他のユーザー（または別端末のあなた）が先にこのページを更新しました。<br>以下の内容を比較し、どうするか選択してください。</div>";
                                
                                echo "<div style='display:flex; gap:20px; margin-bottom:20px;'>";
                                echo "<div style='flex:1; background:#f8f9fa; padding:15px; border:1px solid #dee2e6; border-radius:4px;'>";
                                echo "<h3 style='margin-top:0; border-bottom:1px solid #ccc; padding-bottom:5px;'>サーバー上の最新データ</h3>";
                                echo "<p><strong>タイトル:</strong> ".htmlspecialchars($currentData['title'])."</p>";
                                echo "<p style='margin-bottom:5px;'><strong>本文:</strong></p>";
                                echo "<textarea readonly style='width:100%; height:400px; font-family:monospace; background:#eee;'>".htmlspecialchars($currentData['body'])."</textarea>";
                                echo "</div>";

                                echo "<div style='flex:1; background:#fff3cd; padding:15px; border:1px solid #ffeeba; border-radius:4px;'>";
                                echo "<h3 style='margin-top:0; border-bottom:1px solid #ccc; padding-bottom:5px;'>あなたの入力データ</h3>";
                                echo "<p><strong>タイトル:</strong> ".htmlspecialchars($formData['title'])."</p>";
                                echo "<p style='margin-bottom:5px;'><strong>本文:</strong></p>";
                                echo "<textarea readonly style='width:100%; height:400px; font-family:monospace; background:#fff;'>".htmlspecialchars($formData['body'])."</textarea>";
                                echo "</div></div>";

                                echo "<div style='display:flex; gap:10px;'>";
                                echo "<form method='POST' style='margin:0;'>";
                                foreach ($formData as $k => $v) {
                                    if ($k === 'base_version') echo "<input type='hidden' name='base_version' value='".htmlspecialchars($currentData['version'])."'>";
                                    elseif (is_array($v)) {
                                        foreach($v as $arrV) echo "<input type='hidden' name='{$k}[]' value='".htmlspecialchars($arrV)."'>";
                                    } else {
                                        echo "<input type='hidden' name='".htmlspecialchars($k)."' value='".htmlspecialchars($v)."'>";
                                    }
                                }
                                echo "<button type='submit' class='btn' style='background:#dc3545;' onclick='return confirm(\"本当にサーバーのデータを上書きしてよろしいですか？\")'>あなたの入力データで強制的に上書きする</button>";
                                echo "</form>";

                                echo "<a href='{$baseUrl}cms/contents/edit?id=".urlencode($formData['id'])."' class='btn' style='background:#6c757d;'>キャンセルして最新のデータを編集し直す</a>";
                                echo "</div></main></body></html>";
                                return;
                            } else {
                                $error = '保存に失敗しました。';
                            }
                        }
                    }
                }
            }

            echo $adminHead . "<h1>" . ($isPage ? '通常ページ' : 'ブログ記事') . "の編集</h1>";
            if ($error) echo "<div class='alert alert-error'>$error</div>";
            echo "<form method='POST'>";
            echo "<input type='hidden' name='id' value='".htmlspecialchars($formData['id'])."'>";
            echo "<input type='hidden' name='base_version' value='".htmlspecialchars($formData['version'] ?? 1)."'>";
            echo "<input type='hidden' name='type' value='".htmlspecialchars($formData['type'])."'>";
            
            echo "<fieldset><legend>基本情報</legend>";
            echo "<label>タイトル <span style='color:red;'>*</span></label><input type='text' name='title' value='".htmlspecialchars($formData['title'])."' required>";
            
            if ($isPage) {
                echo "<label>URL階層（スラッグ） <span style='color:red;'>*</span></label><input type='text' name='slug' value='".htmlspecialchars($formData['slug'] ?? '')."' required>";
                
                echo "<label>末尾スラッシュ設定 (このページのみ)</label>";
                echo "<select name='slash_policy'>";
                echo "<option value='default' ".(($formData['slash_policy'] === 'default' || empty($formData['slash_policy']))?'selected':'').">全体設定に従う</option>";
                echo "<option value='none' ".(($formData['slash_policy'] === 'none')?'selected':'').">スラッシュなしに統一</option>";
                echo "<option value='slash' ".(($formData['slash_policy'] === 'slash')?'selected':'').">スラッシュありに統一</option>";
                echo "<option value='as_is' ".(($formData['slash_policy'] === 'as_is')?'selected':'').">統一しない (どちらでもOK)</option>";
                echo "</select>";

                echo "<label>リダイレクト先URL (アクセス時に自動転送する場合)</label><input type='text' name='redirect_url' value='".htmlspecialchars($formData['redirect_url'] ?? '')."' placeholder='例: https://example.com/ または /new-page'>";

                echo "<label>Meta Description (任意)</label><textarea name='meta_description' style='height:80px;'>".htmlspecialchars($formData['meta_description'] ?? '')."</textarea>";

                echo "<label>カスタムHeadタグ (このページのみの&lt;head&gt;内追加)</label><textarea name='custom_head' style='height:80px; font-family:monospace;' placeholder='&lt;link rel=\"stylesheet\" href=\"...\"&gt; や &lt;script src=\"...\"&gt;&lt;/script&gt; など'>".htmlspecialchars($formData['custom_head'] ?? '')."</textarea>";

                echo "<label>カスタムBody末尾タグ (このページのみの&lt;/body&gt;直前追加)</label><textarea name='custom_bottom' style='height:80px; font-family:monospace;' placeholder='&lt;script&gt;...&lt;/script&gt; など'>".htmlspecialchars($formData['custom_bottom'] ?? '')."</textarea>";
            } else {
                if ($isAdminOrSpecial) echo "<label>URLスラッグ (任意)</label><input type='text' name='slug' value='".htmlspecialchars($formData['slug'] ?? '')."'>";
                else echo "<input type='hidden' name='slug' value='".htmlspecialchars($formData['slug'] ?? '')."'>";

                if (!empty($settings['blog_category_enabled'])) {
                    $cats = $this->contentModel->getCategories();
                    $req = !empty($settings['blog_category_required']) ? 'required' : '';
                    echo "<label>カテゴリ ".($req?"<span style='color:red;'>*</span>":"")."</label>";
                    echo "<select name='category_id' {$req}><option value=''>-- 選択してください --</option>";
                    foreach ($cats as $c) {
                        $sel = (($formData['category_id']??'') === $c['id']) ? 'selected' : '';
                        echo "<option value='".htmlspecialchars($c['id'])."' {$sel}>".htmlspecialchars($c['name'])."</option>";
                    }
                    echo "</select>";
                }

                if (!empty($settings['blog_tag_enabled'])) {
                    $tagsStr = isset($formData['tags']) && is_array($formData['tags']) ? implode(', ', $formData['tags']) : '';
                    echo "<label>タグ (カンマ区切りで複数指定可)</label>";
                    echo "<input type='text' name='tags' value='".htmlspecialchars($tagsStr)."' placeholder='例: パソコン, 部活, イベント'>";
                }
            }
            echo "</fieldset>";

            $customVars = [];
            $rawVars = $settings['variables'] ?? '';
            $lines = explode("\n", $rawVars);
            foreach($lines as $line) {
                $line = trim($line);
                if ($line === '' || strpos($line, '=') === false) continue;
                list($k, ) = explode('=', $line, 2);
                $customVars[] = trim($k);
            }

            echo "<fieldset style='display:flex; flex-direction:column;'><legend>本文</legend>";
            
            echo "<div style='text-align:right; margin-bottom:10px;'>";
            echo "<a href='{$baseUrl}cms/uploads' target='_blank' class='btn' style='background:#6f42c1; font-size:0.9em;'>📷 ファイル管理を開く (別タブ)</a>";
            echo "</div>";

            echo "<div style='margin-bottom:10px; padding:10px; background:#f8f9fa; border:1px solid #ced4da; border-radius:4px;'>";
            if ($isPage) {
                echo "<strong style='display:block; margin-bottom:5px; font-size:0.9em; color:#555;'>変数挿入 (クリックで挿入)</strong>";
                echo "<div style='display:flex; flex-wrap:wrap; gap:5px;'>";
                echo "<button type='button' class='btn var-btn' data-var='{{blog_main_list}}' style='padding:4px 8px; font-size:0.85em; background:#28a745;'>ブログ一覧(完全版)</button>";
                echo "<button type='button' class='btn var-btn' data-var='{{blogs limit=5 archive=true}}' style='padding:4px 8px; font-size:0.85em; background:#17a2b8;'>最新ブログ＋アーカイブ</button>";
                echo "<button type='button' class='btn var-btn' data-var='{{blogs limit=10 category=news order=desc}}' style='padding:4px 8px; font-size:0.85em; background:#17a2b8;'>ブログ詳細指定</button>";
                echo "<button type='button' class='btn var-btn' data-var='{{blog_categories}}' style='padding:4px 8px; font-size:0.85em; background:#17a2b8;'>カテゴリ一覧</button>";
                echo "<button type='button' class='btn var-btn' data-var='{{blog_tags}}' style='padding:4px 8px; font-size:0.85em; background:#17a2b8;'>タグ一覧</button>";
                echo "<button type='button' class='btn var-btn' data-var='{{blog_archives}}' style='padding:4px 8px; font-size:0.85em; background:#17a2b8;'>月別アーカイブ</button>";
                echo "<button type='button' class='btn var-btn' data-var='{{blog_search_form}}' style='padding:4px 8px; font-size:0.85em; background:#17a2b8;'>ブログ検索窓</button>";
                if (!empty($settings['site_search_enabled'])) {
                    echo "<button type='button' class='btn var-btn' data-var='{{site_search_form}}' style='padding:4px 8px; font-size:0.85em; background:#17a2b8;'>全体検索窓</button>";
                }
                foreach ($customVars as $cv) {
                    echo "<button type='button' class='btn var-btn' data-var='{{{$cv}}}' style='padding:4px 8px; font-size:0.85em; background:#ffc107; color:#333;'>{$cv}</button>";
                }
                echo "</div>";
            } else {
                echo "<strong style='display:block; margin-bottom:5px; font-size:0.9em; color:#555;'>Markdown入力補助</strong>";
                echo "<div style='display:flex; flex-wrap:wrap; gap:5px;'>";
                echo "<button type='button' class='btn md-btn' data-prefix='**' data-suffix='**' style='padding:4px 8px; font-size:0.9em; background:#6c757d;'>太字</button>";
                echo "<button type='button' class='btn md-btn' data-prefix='*' data-suffix='*' style='padding:4px 8px; font-size:0.9em; background:#6c757d;'>斜体</button>";
                echo "<button type='button' class='btn md-btn' data-prefix='## ' data-suffix='' style='padding:4px 8px; font-size:0.9em; background:#6c757d;'>見出し2</button>";
                echo "<button type='button' class='btn md-btn' data-prefix='### ' data-suffix='' style='padding:4px 8px; font-size:0.9em; background:#6c757d;'>見出し3</button>";
                echo "<button type='button' class='btn md-btn' data-prefix='[リンク名](' data-suffix=')' style='padding:4px 8px; font-size:0.9em; background:#6c757d;'>リンク</button>";
                echo "<button type='button' class='btn md-btn' data-prefix='![代替テキスト](' data-suffix=')' style='padding:4px 8px; font-size:0.9em; background:#6c757d;'>画像</button>";
                echo "<button type='button' class='btn md-btn' data-prefix='- ' data-suffix='' style='padding:4px 8px; font-size:0.9em; background:#6c757d;'>リスト</button>";
                echo "<button type='button' class='btn md-btn' data-prefix='> ' data-suffix='' style='padding:4px 8px; font-size:0.9em; background:#6c757d;'>引用</button>";
                echo "</div>";
                echo "<div style='margin-top:10px; padding-top:10px; border-top:1px dashed #ccc;'>";
                echo "<label><input type='checkbox' id='toggle-preview' checked> リアルタイムプレビューを表示する</label>";
                echo "</div>";
            }
            echo "</div>";

            echo "<div style='display:flex; gap:20px; flex-wrap:wrap;'>";
            echo "<textarea id='editor-textarea' name='body' style='flex:1; min-width:300px; height:500px; font-family:monospace;' required>".htmlspecialchars($formData['body'])."</textarea>";
            
            if (!$isPage) {
                echo "<div id='preview-container' style='flex:1; min-width:300px; display:flex; flex-direction:column;'>";
                echo "<div style='background:#007bff; color:#fff; padding:5px 10px; font-size:0.9em; border-radius:4px 4px 0 0;'>リアルタイムプレビュー</div>";
                echo "<div id='preview-area' style='flex:1; height:465px; overflow-y:auto; border:1px solid #ced4da; padding:15px; background:#fafafa; border-radius:0 0 4px 4px;'></div>";
                echo "</div>";
            }
            echo "</div></fieldset>";

            echo "<button type='submit' class='btn' style='margin-right:10px;'>保存する</button> <a href='{$baseUrl}cms/contents' class='btn' style='background:#6c757d;'>キャンセル</a></form>";
            
            echo <<<JS
<script src='https://cdn.jsdelivr.net/npm/marked/marked.min.js'></script>
<script src='https://cdnjs.cloudflare.com/ajax/libs/dompurify/3.0.6/purify.min.js'></script>
<script>
document.addEventListener('DOMContentLoaded', () => {
    const editor = document.getElementById('editor-textarea');
    const preview = document.getElementById('preview-area');
    const previewContainer = document.getElementById('preview-container');
    const toggle = document.getElementById('toggle-preview');
    
    if (preview && toggle) {
        const updatePreview = () => {
            if (!toggle.checked) return;
            const md = editor.value;
            const html = DOMPurify.sanitize(marked.parse(md), { ADD_ATTR: ['style', 'class', 'target', 'width', 'height', 'align', 'color'] });
            preview.innerHTML = html || '<span style="color:#999;">(テキストを入力するとここにプレビューが表示されます)</span>';
        };
        
        editor.addEventListener('input', updatePreview);
        updatePreview();
        
        toggle.addEventListener('change', () => {
            if (toggle.checked) {
                previewContainer.style.display = 'flex';
                updatePreview();
            } else {
                previewContainer.style.display = 'none';
            }
        });
    }

    const insertText = (text, prefix = '', suffix = '') => {
        const start = editor.selectionStart;
        const end = editor.selectionEnd;
        const textBefore = editor.value.substring(0, start);
        const textAfter = editor.value.substring(end, editor.value.length);
        const selected = editor.value.substring(start, end);

        let inserted = '';
        if (prefix || suffix) {
            inserted = prefix + selected + suffix;
        } else {
            inserted = text;
        }

        editor.value = textBefore + inserted + textAfter;
        editor.selectionStart = start + prefix.length + (selected ? selected.length : text.length);
        editor.selectionEnd = editor.selectionStart;
        editor.focus();
        
        if (preview && toggle && toggle.checked) {
            editor.dispatchEvent(new Event('input'));
        }
    };

    document.querySelectorAll('.md-btn').forEach(btn => {
        btn.addEventListener('click', () => insertText('', btn.dataset.prefix, btn.dataset.suffix));
    });

    document.querySelectorAll('.var-btn').forEach(btn => {
        btn.addEventListener('click', () => insertText(btn.dataset.var));
    });
});
</script>
</main></body></html>
JS;
            return;
        }

        // ==========================================
        // 4. キャッチオール：静的サイト風の通常ページ出力
        // ==========================================
        if ($pageArticle ?? false) {
            if (!empty($pageArticle['redirect_url'])) {
                header("Location: " . $pageArticle['redirect_url'], true, 301);
                exit;
            }
            $header = $this->templateModel->renderHeader($baseUrl);
            $header = $this->injectHeadTags($header, $pageArticle['meta_description'] ?? '', $pageArticle['title'] ?? '', $canonicalUrl, $pageArticle['custom_head'] ?? '');
            $header = $this->replaceVariables($header, $baseUrl);
            echo $header;
            echo "<main>" . $this->replaceVariables($pageArticle['body'], $baseUrl) . "</main>";
            
            $footer = $this->templateModel->renderFooter();
            if (!empty($pageArticle['custom_bottom'])) {
                if (stripos($footer, '</body>') !== false) {
                    $footer = str_ireplace('</body>', $pageArticle['custom_bottom'] . "\n</body>", $footer);
                } else {
                    $footer .= "\n" . $pageArticle['custom_bottom'];
                }
            }
            echo $this->replaceVariables($footer, $baseUrl);
            return;
        }

        $this->renderErrorPage(404, $baseUrl, "お探しのページは見つかりませんでした。");
    }
}
