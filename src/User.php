<?php
// src/User.php

class User {
    private $db;
    private const FILE = 'users.json';

    public function __construct(FileDB $db) {
        $this->db = $db;
        $this->initAdminIfNeeded();
    }

    private function initAdminIfNeeded() {
        $users = $this->db->read(self::FILE, []);
        if (empty($users)) {
            $adminId = 'admin';
            $adminPass = password_hash($adminId . '1221', PASSWORD_DEFAULT);
            $users[] = [
                'id' => uniqid('user_'), 'student_id' => $adminId, 'email' => 'admin@example.com',
                'name' => '初期管理者', 'role' => 'admin', 'password_hash' => $adminPass,
                'totp_secret' => null, 'is_2fa_enabled' => false, 'backup_codes' => [], 
                'email_verify_code' => null, 'email_verify_expires' => null,
                'is_locked' => false, 'created_at' => date('Y-m-d H:i:s')
            ];
            $this->db->write(self::FILE, $users);
        }
    }

    public function getAll() { return $this->db->read(self::FILE, []); }

    public function findByStudentId($student_id) {
        foreach ($this->getAll() as $user) {
            if ($user['student_id'] === $student_id) return $user;
        }
        return null;
    }

    public function findById($id) {
        foreach ($this->getAll() as $user) {
            if ($user['id'] === $id) return $user;
        }
        return null;
    }

    public function save($data) {
        $users = $this->getAll();
        $isNew = empty($data['id']);
        
        if ($isNew) {
            $data['id'] = uniqid('user_');
            if (empty($data['password_hash'])) $data['password_hash'] = password_hash($data['student_id'] . '1221', PASSWORD_DEFAULT);
            $data['created_at'] = date('Y-m-d H:i:s');
            $data['totp_secret'] = null;
            $data['is_2fa_enabled'] = false;
            $data['backup_codes'] = [];
            $data['email_verify_code'] = null;
            $data['email_verify_expires'] = null;
            $data['is_locked'] = false;
            $users[] = $data;
        } else {
            foreach ($users as $key => $user) {
                if ($user['id'] === $data['id']) {
                    $users[$key] = array_merge($user, $data);
                    break;
                }
            }
        }
        return $this->db->write(self::FILE, $users);
    }

    public function delete($id) {
        $users = array_filter($this->getAll(), function($u) use ($id) { return $u['id'] !== $id; });
        return $this->db->write(self::FILE, array_values($users));
    }

    public function changePassword($id, $newPassword) {
        $users = $this->getAll();
        foreach ($users as $key => $user) {
            if ($user['id'] === $id) {
                $users[$key]['password_hash'] = password_hash($newPassword, PASSWORD_DEFAULT);
                return $this->db->write(self::FILE, $users);
            }
        }
        return false;
    }

    public function calculateGrade($student_id) {
        if (!preg_match('/^[0-9]{7}$/', $student_id)) return '教師/顧問';
        $enroll_year = 2000 + (int)substr($student_id, 0, 2); 
        $current_school_year = ((int)date('n') <= 3) ? (int)date('Y') - 1 : (int)date('Y');
        $grade = $current_school_year - $enroll_year + 1;
        return $grade > 0 ? $grade . '年生' : '入学前';
    }
}