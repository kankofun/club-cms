<?php
// src/Auth.php

class Auth {
    private $userModel;

    public function __construct(User $userModel) {
        $this->userModel = $userModel;
    }

    public function login($student_id, $password) {
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        
        // ★ 1. レートリミットのチェック（ブルートフォース攻撃対策）
        $rateLimitStatus = $this->checkRateLimit($ip, $student_id);
        if ($rateLimitStatus !== true) {
            if ($rateLimitStatus === 'blocked_60') return 'blocked_60';
            if ($rateLimitStatus === 'blocked_15') return 'blocked_15';
        }

        $user = $this->userModel->findByStudentId($student_id);
        
        if ($user && password_verify($password, $user['password_hash'])) {
            if (!empty($user['is_locked'])) {
                return 'locked';
            }

            // ログイン成功時はレートリミット（失敗カウント）をリセット
            $this->clearRateLimit($ip, $student_id);

            $settingsFile = DATA_DIR . '/settings.json';
            $settings = file_exists($settingsFile) ? json_decode(file_get_contents($settingsFile), true) : [];
            $mode = $settings['2fa_mode'] ?? 'none';

            if ($mode === 'none') {
                return true; // 呼び出し元（Router）で completeLogin が呼ばれる
            }

            // セッションに「認証待機中」としてIDを保持
            $_SESSION['pending_2fa_user_id'] = $user['id'];
            
            $hasTotp = !empty($user['is_2fa_enabled']) && !empty($user['totp_secret']);

            // TOTPが有効なモードで、かつユーザーが設定済みの場合はTOTP認証へ
            if (in_array($mode, ['email_totp_optional', 'email_totp_required']) && $hasTotp) {
                return 'requires_totp';
            }

            // それ以外（メール認証のみ、またはTOTP未設定の場合）はメール認証へ
            return 'requires_email';
        }
        
        // ★ 2. 失敗時にカウントを記録
        $this->recordFailedLogin($ip, $student_id);
        return false;
    }

    public function completeLogin($user) {
        // ★ 3. セッションハイジャック対策：ログイン成功時にセッションIDを再生成
        session_regenerate_id(true);
        
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['student_id'] = $user['student_id'];
        $_SESSION['role'] = $user['role'];
        $_SESSION['name'] = $user['name'];
        $_SESSION['last_activity'] = time(); // スライディングセッションの起点
        
        unset($_SESSION['pending_2fa_user_id']);
    }

    public function logout() {
        // タイムアウト復元用の一部のセッションデータだけを保護してログアウト
        $expiredUserId = $_SESSION['expired_user_id'] ?? null;
        $timeoutMsg = $_SESSION['timeout_message'] ?? null;
        $recoveryPost = $_SESSION['recovery_post'] ?? null;
        $redirectUrl = $_SESSION['redirect_url'] ?? null;

        $_SESSION = [];
        // セッションIDの再生成
        session_regenerate_id(true);

        if ($expiredUserId) $_SESSION['expired_user_id'] = $expiredUserId;
        if ($timeoutMsg) $_SESSION['timeout_message'] = $timeoutMsg;
        if ($recoveryPost) $_SESSION['recovery_post'] = $recoveryPost;
        if ($redirectUrl) $_SESSION['redirect_url'] = $redirectUrl;
    }

    // ★ 4. スライディングセッションと権限のリアルタイムチェック
    public function isLoggedIn() {
        return $this->getCurrentUser() !== null;
    }
    
    public function getCurrentUser() {
        if (!isset($_SESSION['user_id'])) {
            return null;
        }

        $now = time();
        $timeout = 120 * 60; // 120分 = 7200秒

        if (isset($_SESSION['last_activity']) && ($now - $_SESSION['last_activity']) > $timeout) {
            // 120分間アクセスがなかった場合はタイムアウト
            $_SESSION['expired_user_id'] = $_SESSION['user_id'];
            $_SESSION['timeout_message'] = true;
            $this->logout();
            return null;
        }

        // アクセスがあるたびに期限を延長（スライディングセッション）
        $_SESSION['last_activity'] = $now;

        // DBから最新のユーザー情報を取得し、ロック・削除・権限変更を即時反映
        $user = $this->userModel->findById($_SESSION['user_id']);
        if (!$user || !empty($user['is_locked'])) {
            $this->logout();
            return null;
        }

        $_SESSION['role'] = $user['role'];
        $_SESSION['name'] = $user['name'];
        $_SESSION['student_id'] = $user['student_id'];

        return [
            'id' => $user['id'],
            'name' => $user['name'],
            'role' => $user['role'],
            'student_id' => $user['student_id']
        ];
    }

    // ==========================================
    // レートリミッター（ブルートフォース攻撃対策）
    // ==========================================
    private function checkRateLimit($ip, $username) {
        $db = new FileDB();
        $data = $db->read('rate_limit.json', ['ip'=>[], 'user'=>[], 'ip_user'=>[]]);
        $now = time();

        // 1. IP単位のロック
        if (isset($data['ip'][$ip])) {
            $fails = $data['ip'][$ip]['fails'];
            $last = $data['ip'][$ip]['last'];
            if ($fails >= 100) { if ($now - $last < 3600) return "blocked_60"; }
            elseif ($fails >= 50) { if ($now - $last < 900) return "blocked_15"; }
            elseif ($fails >= 20) { sleep(2); } // ソフト制限（意図的な遅延）
        }

        // 2. ユーザー単位のロック（パスワードスプレー攻撃対策）
        if (isset($data['user'][$username])) {
            $fails = $data['user'][$username]['fails'];
            $last = $data['user'][$username]['last'];
            if ($fails >= 10) { if ($now - $last < 3600) return "blocked_60"; }
            elseif ($fails >= 5) { if ($now - $last < 900) return "blocked_15"; }
        }

        // 3. IP × ユーザーの組み合わせロック（特定の狙い撃ち対策）
        $ipUser = $ip . '_' . $username;
        if (isset($data['ip_user'][$ipUser])) {
            $fails = $data['ip_user'][$ipUser]['fails'];
            $last = $data['ip_user'][$ipUser]['last'];
            if ($fails >= 7) { if ($now - $last < 900) return "blocked_15"; }
        }

        return true;
    }

    private function recordFailedLogin($ip, $username) {
        $db = new FileDB();
        $data = $db->read('rate_limit.json', ['ip'=>[], 'user'=>[], 'ip_user'=>[]]);
        $now = time();

        // 1時間以上経過した古い記録をクリーンアップ
        foreach (['ip', 'user', 'ip_user'] as $type) {
            foreach ($data[$type] as $key => $val) {
                if ($now - $val['last'] > 3600) unset($data[$type][$key]);
            }
        }

        $ipUser = $ip . '_' . $username;

        if (!isset($data['ip'][$ip])) $data['ip'][$ip] = ['fails'=>0, 'last'=>$now];
        $data['ip'][$ip]['fails']++;
        $data['ip'][$ip]['last'] = $now;

        if (!isset($data['user'][$username])) $data['user'][$username] = ['fails'=>0, 'last'=>$now];
        $data['user'][$username]['fails']++;
        $data['user'][$username]['last'] = $now;

        if (!isset($data['ip_user'][$ipUser])) $data['ip_user'][$ipUser] = ['fails'=>0, 'last'=>$now];
        $data['ip_user'][$ipUser]['fails']++;
        $data['ip_user'][$ipUser]['last'] = $now;

        $db->write('rate_limit.json', $data);
    }

    private function clearRateLimit($ip, $username) {
        $db = new FileDB();
        $data = $db->read('rate_limit.json', null);
        if (!$data) return;

        $ipUser = $ip . '_' . $username;
        if (isset($data['ip'][$ip])) unset($data['ip'][$ip]);
        if (isset($data['user'][$username])) unset($data['user'][$username]);
        if (isset($data['ip_user'][$ipUser])) unset($data['ip_user'][$ipUser]);

        $db->write('rate_limit.json', $data);
    }
}