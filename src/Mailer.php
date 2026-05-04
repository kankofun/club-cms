<?php
// src/Mailer.php

class Mailer {
    private $settings;

    public function __construct($settings) {
        $this->settings = $settings;
    }

    public function send($to, $subject, $body) {
        $method = $this->settings['mail_method'] ?? 'sendmail';
        $from = $this->settings['mail_from'] ?? 'noreply@example.com';
        $fromName = $this->settings['mail_from_name'] ?? 'System';

        if ($method === 'smtp') {
            $headers = "From: =?UTF-8?B?" . base64_encode($fromName) . "?= <{$from}>\r\n";
            $headers .= "Reply-To: {$from}\r\n";
            $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
            // 本文の文字化けを防ぐためBase64転送を指定
            $headers .= "Content-Transfer-Encoding: base64\r\n";
            return $this->sendSMTP($to, $subject, $body, $headers, $from);
        } else {
            // sendmail (PHP mail) の場合の文字化け対策
            mb_language("ja");
            mb_internal_encoding("UTF-8");
            $headers = "From: " . mb_encode_mimeheader($fromName) . " <{$from}>\r\n";
            $headers .= "Reply-To: {$from}\r\n";
            return mb_send_mail($to, $subject, $body, $headers);
        }
    }

    private function sendSMTP($to, $subject, $body, $headers, $from) {
        $host = $this->settings['smtp_host'] ?? '';
        $port = $this->settings['smtp_port'] ?? 587;
        $crypto = $this->settings['smtp_crypto'] ?? 'STARTTLS';
        $user = $this->settings['smtp_user'] ?? '';
        $pass = $this->settings['smtp_pass'] ?? '';

        $transport = (strtoupper($crypto) === 'SSL') ? 'ssl://' : 'tcp://';
        $socket = @fsockopen($transport . $host, $port, $errno, $errstr, 10);
        if (!$socket) return false;

        $this->readSocket($socket);
        fwrite($socket, "EHLO " . $_SERVER['SERVER_NAME'] . "\r\n");
        $this->readSocket($socket);

        if (strtoupper($crypto) === 'STARTTLS') {
            fwrite($socket, "STARTTLS\r\n");
            $this->readSocket($socket);
            stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
            fwrite($socket, "EHLO " . $_SERVER['SERVER_NAME'] . "\r\n");
            $this->readSocket($socket);
        }

        if (!empty($user)) {
            fwrite($socket, "AUTH LOGIN\r\n"); $this->readSocket($socket);
            fwrite($socket, base64_encode($user) . "\r\n"); $this->readSocket($socket);
            fwrite($socket, base64_encode($pass) . "\r\n"); $this->readSocket($socket);
        }

        fwrite($socket, "MAIL FROM:<{$from}>\r\n"); $this->readSocket($socket);
        fwrite($socket, "RCPT TO:<{$to}>\r\n"); $this->readSocket($socket);
        fwrite($socket, "DATA\r\n"); $this->readSocket($socket);

        $msg = "Subject: =?UTF-8?B?" . base64_encode($subject) . "?=\r\n";
        $msg .= $headers . "\r\n";
        // Base64にエンコードして分割送信
        $msg .= chunk_split(base64_encode($body)) . "\r\n.\r\n";

        fwrite($socket, $msg); $this->readSocket($socket);
        fwrite($socket, "QUIT\r\n");
        fclose($socket);
        return true;
    }

    private function readSocket($socket) {
        $data = '';
        while ($str = fgets($socket, 515)) {
            $data .= $str;
            if (substr($str, 3, 1) == ' ') break;
        }
        return $data;
    }
}