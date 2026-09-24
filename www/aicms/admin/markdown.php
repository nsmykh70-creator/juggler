<?php
/**
 * AICMS Markdown Import/Export
 * ============================
 * Converts pages between HTML and Markdown formats.
 * 
 * Usage:
 *   Import: admin/markdown.php?action=import&id=1
 *   Export: admin/markdown.php?action=export&id=1
 */

require_once __DIR__ . '/core/functions.php';
require_auth();

$action = $_GET['action'] ?? '';

// Simple HTML to Markdown converter
function html_to_markdown($html) {
    // Remove script and style
    $html = preg_replace('/<script[^>]*>.*?<\/script>/is', '', $html);
    $html = preg_replace('/<style[^>]*>.*?<\/style>/is', '', $html);
    
    // Headings
    $html = preg_replace('/<h1[^>]*>(.*?)<\/h1>/is', "# $1\n\n", $html);
    $html = preg_replace('/<h2[^>]*>(.*?)<\/h2>/is', "## $1\n\n", $html);
    $html = preg_replace('/<h3[^>]*>(.*?)<\/h3>/is', "### $1\n\n", $html);
    $html = preg_replace('/<h4[^>]*>(.*?)<\/h4>/is', "#### $1\n\n", $html);
    
    // Bold and italic
    $html = preg_replace('/<(strong|b)[^>]*>(.*?)<\/\1>/is', '**$2**', $html);
    $html = preg_replace('/<(em|i)[^>]*>(.*?)<\/\1>/is', '*$2*', $html);
    
    // Links
    $html = preg_replace('/<a[^>]*href=["\']([^"\']*)["\'][^>]*>(.*?)<\/a>/is', '[$2]($1)', $html);
    
    // Images
    $html = preg_replace('/<img[^>]*src=["\']([^"\']*)["\'][^>]*alt=["\']([^"\']*)["\'][^>]*>/is', '![$2]($1)', $html);
    $html = preg_replace('/<img[^>]*src=["\']([^"\']*)["\'][^>]*>/is', '![]($1)', $html);
    
    // Lists
    $html = preg_replace('/<ul[^>]*>(.*?)<\/ul>/is', '$1', $html);
    $html = preg_replace('/<ol[^>]*>(.*?)<\/ol>/is', '$1', $html);
    $html = preg_replace('/<li[^>]*>(.*?)<\/li>/is', '- $1\n', $html);
    
    // Code blocks
    $html = preg_replace('/<pre[^>]*><code[^>]*>(.*?)<\/code><\/pre>/is', "```\n$1\n```\n\n", $html);
    $html = preg_replace('/<code[^>]*>(.*?)<\/code>/is', '`$1`', $html);
    
    // Blockquotes
    $html = preg_replace('/<blockquote[^>]*>(.*?)<\/blockquote>/is', "> $1\n\n", $html);
    
    // Paragraphs and breaks
    $html = preg_replace('/<p[^>]*>(.*?)<\/p>/is', "$1\n\n", $html);
    $html = preg_replace('/<br\s*\/?>/i', "\n", $html);
    $html = preg_replace('/<hr\s*\/?>/i', "---\n\n", $html);
    
    // Remove remaining tags
    $html = strip_tags($html);
    
    // Clean up whitespace
    $html = preg_replace("/\n{3,}/", "\n\n", $html);
    $html = html_entity_decode($html, ENT_QUOTES, 'UTF-8');
    
    return trim($html);
}

// Simple Markdown to HTML converter
function markdown_to_html($md) {
    // Code blocks
    $md = preg_replace('/```(\w*)\n(.*?)```/s', '<pre><code>$2</code></pre>', $md);
    
    // Headings
    $md = preg_replace('/^#### (.*)$/m', '<h4>$1</h4>', $md);
    $md = preg_replace('/^### (.*)$/m', '<h3>$1</h3>', $md);
    $md = preg_replace('/^## (.*)$/m', '<h2>$1</h2>', $md);
    $md = preg_replace('/^# (.*)$/m', '<h1>$1</h1>', $md);
    
    // Bold and italic
    $md = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $md);
    $md = preg_replace('/\*(.+?)\*/', '<em>$1</em>', $md);
    
    // Links
    $md = preg_replace('/\[([^\]]+)\]\(([^)]+)\)/', '<a href="$2">$1</a>', $md);
    
    // Images
    $md = preg_replace('/!\[([^\]]*)\]\(([^)]+)\)/', '<img src="$2" alt="$1">', $md);
    
    // Lists
    $md = preg_replace('/^- (.*)$/m', '<li>$1</li>', $md);
    $md = preg_replace('/(<li>.*<\/li>)/s', '<ul>$1</ul>', $md);
    
    // Blockquotes
    $md = preg_replace('/^> (.*)$/m', '<blockquote>$1</blockquote>', $md);
    
    // Horizontal rule
    $md = preg_replace('/^---$/m', '<hr>', $md);
    
    // Code inline
    $md = preg_replace('/`([^`]+)`/', '<code>$1</code>', $md);
    
    // Paragraphs (lines not already in tags)
    $lines = explode("\n", $md);
    $result = [];
    $in_paragraph = false;
    foreach ($lines as $line) {
        $trimmed = trim($line);
        if (empty($trimmed)) {
            if ($in_paragraph) { $result[] = '</p>'; $in_paragraph = false; }
            continue;
        }
        // Skip lines that are already HTML tags
        if (preg_match('/^<(h[1-4]|ul|ol|li|pre|blockquote|hr|img|table)/', $trimmed)) {
            if ($in_paragraph) { $result[] = '</p>'; $in_paragraph = false; }
            $result[] = $line;
        } else {
            if (!$in_paragraph) { $result[] = '<p>'; $in_paragraph = true; }
            $result[] = $line;
        }
    }
    if ($in_paragraph) $result[] = '</p>';
    
    return implode("\n", $result);
}

if ($action === 'export' && isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    $lang = $_GET['lang'] ?? ADMIN_LANG;
    
    // Find page
    $pages = read_content('pages.json', [], $lang);
    $page = null;
    foreach ($pages as $p) {
        if ($p['id'] === $id) { $page = $p; break; }
    }
    
    if (!$page) {
        set_flash_message('Страница не найдена.', 'danger');
        header('Location: pages.php');
        exit;
    }
    
    $md = "# " . ($page['title'] ?? '') . "\n\n";
    $md .= "Slug: " . ($page['slug'] ?? '') . "\n";
    $md .= "Status: " . ($page['status'] ?? 'published') . "\n\n";
    $md .= "---\n\n";
    $md .= html_to_markdown($page['content'] ?? '');
    
    $filename = ($page['slug'] ?? 'page') . '.md';
    header('Content-Type: text/markdown; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    echo $md;
    exit;
}

if ($action === 'import' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        set_flash_message('CSRF error.', 'danger');
        header('Location: pages.php');
        exit;
    }
    
    $id = (int)($_POST['id'] ?? 0);
    $lang = $_POST['lang'] ?? ADMIN_LANG;
    
    if (!isset($_FILES['md_file']) || $_FILES['md_file']['error'] !== UPLOAD_ERR_OK) {
        set_flash_message('Файл не загружен.', 'danger');
        header('Location: edit_page.php?id=' . $id);
        exit;
    }
    
    $md_content = file_get_contents($_FILES['md_file']['tmp_name']);
    $html = markdown_to_html($md_content);
    
    // Update page content
    $pages = read_content('pages.json', [], $lang);
    foreach ($pages as &$p) {
        if ($p['id'] === $id) {
            $p['content'] = $html;
            break;
        }
    }
    unset($p);
    write_content('pages.json', $pages, $lang);
    
    require_once __DIR__ . '/core/cache.php';
    cache_clear();
    
    set_flash_message('Markdown импортирован!', 'success');
    header('Location: edit_page.php?id=' . $id);
    exit;
}

header('Location: pages.php');
exit;
