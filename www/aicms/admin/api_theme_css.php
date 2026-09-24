<?php
// display_errors intentionally off — errors go to server log, not to clients
header('Content-Type: text/css; charset=utf-8');
header('Cache-Control: no-cache');

function _read_config() {
    $f = __DIR__ . '/../data/config.json';
    if (!file_exists($f)) return [];
    $c = @file_get_contents($f);
    if ($c === false) return [];
    $d = json_decode($c, true);
    return is_array($d) ? $d : [];
}

$config = _read_config();
$themeName = $config['theme'] ?? 'helios';
if (!empty($_GET['theme']) && preg_match('/^[a-z0-9_]+$/i', $_GET['theme'])) {
    $themeName = $_GET['theme'];
}

$cssFile = __DIR__ . '/../themes/' . $themeName . '/style.css';
if (!file_exists($cssFile)) {
    echo "/* Theme not found: $themeName */";
    exit;
}

$css = file_get_contents($cssFile);

// Strip <style> and </style> tags
$css = preg_replace('/<\/?style[^>]*>/i', '', $css);

// Remove comments
$css = preg_replace('/\/\*[^*]*\*+([^\/*][^*]*\*+)*\//', '', $css);

// Proper CSS parser that handles nested braces (@keyframes, @media)
function scope_css($css) {
    $output = '';
    $len = strlen($css);
    $i = 0;
    
    while ($i < $len) {
        // Skip whitespace
        while ($i < $len && ctype_space($css[$i])) $i++;
        if ($i >= $len) break;
        
        // Read selector/at-rule until {
        $selectorStart = $i;
        while ($i < $len && $css[$i] !== '{') $i++;
        if ($i >= $len) break;
        
        $selector = trim(substr($css, $selectorStart, $i - $selectorStart));
        $i++; // skip {
        
        // Read declaration block (handle nested braces)
        $blockStart = $i;
        $depth = 1;
        while ($i < $len && $depth > 0) {
            if ($css[$i] === '{') $depth++;
            elseif ($css[$i] === '}') $depth--;
            if ($depth > 0) $i++;
        }
        $block = substr($css, $blockStart, $i - $blockStart);
        if ($i < $len) $i++; // skip }
        
        // Handle @-rules
        if (strpos($selector, '@') === 0) {
            if (strpos($selector, '@media') === 0 || strpos($selector, '@supports') === 0) {
                // Scope inner rules
                $output .= $selector . " {\n" . scope_css($block) . "}\n";
            } else {
                // @keyframes, @font-face — keep as-is
                $output .= $selector . " { " . $block . " }\n";
            }
            continue;
        }
        
        // Regular rule — scope it
        $decl = trim($block);
        
        // Skip universal selector
        if ($selector === '*') continue;
        
        // :root → scope to .lumina-editor.lumina-theme-view (so CSS variables work!)
        if ($selector === ':root') {
            $selector = '.lumina-editor.lumina-theme-view';
            $output .= $selector . ' { ' . $decl . " }\n";
            continue;
        }
        
        // Replace body
        $selector = preg_replace('/(^|,)\s*body(?![a-zA-Z0-9_-])/', '$1 .lumina-editor.lumina-theme-view', $selector);
        // Replace theme content wrappers
        $selector = preg_replace('/\.(helios|aurora|cosmos|default|camel|avangard|999|777|paralax)-content/', '.lumina-editor.lumina-theme-view', $selector);
        $selector = preg_replace('/\.page-content/', '.lumina-editor.lumina-theme-view', $selector);
        $selector = preg_replace('/\.content-card/', '.lumina-editor.lumina-theme-view', $selector);
        
        // For .lumina-editor.lumina-theme-view itself — strip layout-breaking props
        if (trim($selector) === '.lumina-editor.lumina-theme-view') {
            $decl = preg_replace('/overflow-x\s*:\s*[^;]+;?/i', '', $decl);
            $decl = preg_replace('/overflow\s*:\s*[^;]+;?/i', '', $decl);
            $decl = preg_replace('/display\s*:\s*[^;]+;?/i', '', $decl);
            $decl = preg_replace('/margin\s*:\s*[^;]+;?/i', '', $decl);
            $decl = preg_replace('/position\s*:\s*[^;]+;?/i', '', $decl);
        }
        
        // Strip opacity:0 (hides content) but keep opacity:0.3 etc
        $decl = preg_replace('/opacity\s*:\s*0(?!\.\d)\s*;?/i', '', $decl);
        // Strip visibility:hidden
        $decl = preg_replace('/visibility\s*:\s*hidden\s*;?/i', '', $decl);
        
        // Prefix selectors that don't have .lumina-editor.lumina-theme-view
        if (strpos($selector, '.lumina-editor.lumina-theme-view') === false) {
            $sels = explode(',', $selector);
            $newSels = [];
            foreach ($sels as $s) {
                $s = trim($s);
                if ($s && strpos($s, '.lumina-editor.lumina-theme-view') === false) {
                    $newSels[] = '.lumina-editor.lumina-theme-view ' . $s;
                } else {
                    $newSels[] = $s;
                }
            }
            $selector = implode(', ', $newSels);
        }
        
        $output .= $selector . ' { ' . $decl . " }\n";
    }
    
    return $output;
}

echo scope_css($css);
