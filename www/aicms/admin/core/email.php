<?php
/**
 * AICMS Email/SMTP Sender
 * =======================
 * Sends emails via PHP mail() or SMTP.
 * 
 * SMTP settings are configured in Settings → Integrations.
 * 
 * Usage:
 *   require_once __DIR__ . '/core/email.php';
 *   send_email('to@example.com', 'Subject', '<p>HTML body</p>');
 *   send_email('to@example.com', 'Subject', 'Plain text', false);
 */

if (!function_exists('send_email')) {
    function send_email($to, $subject, $body, $is_html = true) {
        $config_file = __DIR__ . '/../../data/config.json';
        $config = [];
        if (file_exists($config_file)) {
            $config = json_decode(@file_get_contents($config_file), true) ?: [];
        }
        
        $smtp = $config['smtp'] ?? [];
        $from_email = $config['site_email'] ?? ($smtp['username'] ?? 'noreply@' . ($_SERVER['HTTP_HOST'] ?? 'localhost'));
        $from_name = $config['site_title'] ?? 'AICMS';
        
        $headers = "From: {$from_name} <{$from_email}>\r\n";
        $headers .= "Reply-To: {$from_email}\r\n";
        $headers .= "MIME-Version: 1.0\r\n";
        if ($is_html) {
            $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
        } else {
            $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
        }
        
        // If SMTP is configured, use socket-based SMTP
        if (!empty($smtp['host']) && !empty($smtp['username']) && !empty($smtp['password'])) {
            return smtp_send($to, $subject, $body, $headers, $smtp, $from_email, $from_name, $is_html);
        }
        
        // Fallback to PHP mail()
        $encoded_subject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
        return @mail($to, $encoded_subject, $body, $headers);
    }
}

if (!function_exists('smtp_send')) {
    function smtp_send($to, $subject, $body, $headers, $smtp, $from_email, $from_name, $is_html) {
        $host = $smtp['host'];
        $port = (int)($smtp['port'] ?? 587);
        $username = $smtp['username'];
        $password = $smtp['password'];
        $security = $smtp['security'] ?? 'tls'; // tls, ssl, none
        
        $remote = ($security === 'ssl' ? 'ssl://' : '') . $host . ':' . $port;
        
        $socket = @stream_socket_client($remote, $errno, $errstr, 15);
        if (!$socket) {
            error_log("SMTP connect failed: $errstr ($errno)");
            return false;
        }
        
        $response = fgets($socket, 515);
        if (strpos($response, '220') !== 0) {
            fclose($socket);
            return false;
        }
        
        // EHLO
        fwrite($socket, "EHLO " . ($_SERVER['HTTP_HOST'] ?? 'localhost') . "\r\n");
        $response = '';
        while (strpos($response, '250') !== 0 && !feof($socket)) {
            $response = fgets($socket, 515);
            if (strpos($response, '250') === 0 && substr($response, 3, 1) === ' ') break;
        }
        
        // STARTTLS
        if ($security === 'tls') {
            fwrite($socket, "STARTTLS\r\n");
            $response = fgets($socket, 515);
            if (strpos($response, '220') === 0) {
                stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
                fwrite($socket, "EHLO " . ($_SERVER['HTTP_HOST'] ?? 'localhost') . "\r\n");
                while (!feof($socket)) {
                    $response = fgets($socket, 515);
                    if (strpos($response, '250') === 0 && substr($response, 3, 1) === ' ') break;
                }
            }
        }
        
        // AUTH LOGIN
        fwrite($socket, "AUTH LOGIN\r\n");
        $response = fgets($socket, 515);
        
        fwrite($socket, base64_encode($username) . "\r\n");
        $response = fgets($socket, 515);
        
        fwrite($socket, base64_encode($password) . "\r\n");
        $response = fgets($socket, 515);
        if (strpos($response, '235') !== 0) {
            fclose($socket);
            return false;
        }
        
        // MAIL FROM
        fwrite($socket, "MAIL FROM:<{$from_email}>\r\n");
        $response = fgets($socket, 515);
        
        // RCPT TO
        fwrite($socket, "RCPT TO:<{$to}>\r\n");
        $response = fgets($socket, 515);
        
        // DATA
        fwrite($socket, "DATA\r\n");
        $response = fgets($socket, 515);
        
        $encoded_subject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
        $message = "Subject: {$encoded_subject}\r\n";
        $message .= "To: {$to}\r\n";
        $message .= $headers;
        $message .= "\r\n";
        $message .= $body;
        $message .= "\r\n.\r\n";
        
        fwrite($socket, $message);
        $response = fgets($socket, 515);
        
        // QUIT
        fwrite($socket, "QUIT\r\n");
        fclose($socket);
        
        return strpos($response, '250') === 0;
    }
}
