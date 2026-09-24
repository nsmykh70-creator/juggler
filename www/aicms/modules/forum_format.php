<?php
/**
 * Forum BBCode formatter.
 * ========================
 * Extracted from modules/forum.php so it can be unit-tested in isolation
 * (see tests/Security/ForumFormatTest.php).
 *
 * Security model: text is escaped FIRST (htmlspecialchars ENT_QUOTES), then
 * BBCode is applied. The [url=] tag is validated against a scheme whitelist
 * (http/https/ftp/mailto only) and \t\r\n are stripped from the URL to defeat
 * the 'java\tscript:' scheme-normalization bypass.
 */

if (!function_exists('forum_format')) {
    function forum_format($text) {
        $text = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
        $text = preg_replace('/\[b\](.*?)\[\/b\]/is', '<strong>$1</strong>', $text);
        $text = preg_replace('/\[i\](.*?)\[\/i\]/is', '<em>$1</em>', $text);
        $text = preg_replace('/\[code\](.*?)\[\/code\]/is', '<code style="background:#f1f5f9; padding:2px 6px; border-radius:4px; ">$1</code>', $text);
        $text = preg_replace_callback('/\[url=(.*?)\](.*?)\[\/url\]/is', function ($m) {
            // Убираем табы/переводы строк (обход схемы 'java\tscript:'), убираем обрезку по краям.
            $url = preg_replace('/[\t\r\n]+/', '', trim($m[1]));
            // Вайтлист схем: только http/https/ftp-ссылки и mailto:.
            if (preg_match('#^(https?://|ftp://|mailto:)#i', $url)) {
                return '<a href="' . $url . '" target="_blank" rel="nofollow noopener">' . $m[2] . '</a>';
            }
            // Неразрешённая схема (javascript:, data:, vbscript:, ...) — рендерим как текст без ссылки.
            return $m[2];
        }, $text);
        $text = preg_replace('/\[quote\](.*?)\[\/quote\]/is', '<blockquote style="border-left:3px solid #cbd5e1; padding-left:12px; margin:8px 0; color:#64748b; font-style:italic;">$1</blockquote>', $text);
        $text = nl2br($text);
        return $text;
    }
}
