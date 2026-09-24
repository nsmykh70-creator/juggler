<?php

if (!function_exists('h')) {
    function h($string) { return htmlspecialchars($string, ENT_QUOTES, 'UTF-8'); }
}

/**
 * AICMS Template Engine
 * Supports: variables, foreach (including nested children), if/endif, page_url, lang_url
 */
function render_template($template_path, $data = []) {
    if (!file_exists($template_path)) {
        return "Ошибка: файл шаблона не найден: " . h($template_path);
    }

    $template_content = file_get_contents($template_path);

    // Process foreach loops — handle NESTED loops by processing innermost first
    $template_content = process_foreach_loops($template_content, $data);

    // --- Обработка кастомных полей {{page.custom.key}} ---
    if (isset($data['currentPage']['custom']) && is_array($data['currentPage']['custom'])) {
        foreach ($data['currentPage']['custom'] as $custom_key => $custom_value) {
             $template_content = str_replace("{{page.custom.{$custom_key}}}", h($custom_value), $template_content);
        }
    }
    $template_content = preg_replace('/{{page\.custom\.[a-zA-Z0-9_]+}}/', '', $template_content);
    
    // --- Обработка простых переменных {{...}} вне циклов ---
    foreach ($data as $key => $value) {
        if (is_scalar($value)) {
            $template_content = str_replace("{{{$key}}}", $value, $template_content);
        }
    }

    // --- Обработка тега {{clean_urls}} ---
    $clean_urls = !empty($data['clean_urls']);
    $template_content = str_replace('{{clean_urls}}', $clean_urls ? '1' : '0', $template_content);

    // --- Обработка функции {{page_url:slug}} ---
    $current_lang = $data['current_lang'] ?? 'ru';
    $default_lang = $data['default_lang'] ?? 'ru';
    $template_content = preg_replace_callback('/{{page_url:([^}]+)}}/', function($m) use ($clean_urls, $current_lang, $default_lang) {
        $slug = trim($m[1]);
        if ($clean_urls) {
            $url = '/' . $slug;
            if ($current_lang !== $default_lang) $url .= '?lang=' . $current_lang;
        } else {
            $url = '?page=' . $slug;
            if ($current_lang !== $default_lang) $url .= '&lang=' . $current_lang;
        }
        return $url;
    }, $template_content);

    // --- Обработка функции {{lang_url:code}} ---
    $current_slug = $data['current_slug'] ?? 'home';
    $template_content = preg_replace_callback('/{{lang_url:([^}]+)}}/', function($m) use ($clean_urls, $current_slug) {
        $code = trim($m[1]);
        if ($clean_urls) {
            return '/' . $current_slug . '?lang=' . $code;
        }
        return '?page=' . $current_slug . '&lang=' . $code;
    }, $template_content);

    return $template_content;
}

/**
 * Process foreach loops — handles nested loops by finding the innermost first.
 * This avoids the problem where {{endforeach}} of inner loop is matched by outer loop.
 */
function process_foreach_loops($content, $data) {
    // Keep processing until no more foreach loops
    $max_iterations = 10;
    while ($max_iterations-- > 0 && strpos($content, '{{foreach:') !== false) {
        // Find the INNERMOST foreach (the one without nested foreach inside it)
        if (preg_match('/{{foreach:([^}]+)}}((?:(?!{{foreach:).)*?){{endforeach}}/s', $content, $match, PREG_OFFSET_CAPTURE)) {
            $full_match = $match[0][0];
            $match_start = $match[0][1];
            $loop_header = $match[1][0];
            $loop_body = $match[2][0];

            // Parse the loop header: "array_name as item_name"
            @list($arrayName, $itemName) = array_map('trim', explode(' as ', $loop_header));
            if (!$arrayName || !$itemName) {
                $content = substr_replace($content, 'Ошибка в синтаксисе foreach', $match_start, strlen($full_match));
                continue;
            }

            // Check if this is a children loop: "m.children as c"
            $is_children_loop = (strpos($arrayName, '.') !== false);
            
            if ($is_children_loop) {
                // This is a children loop (e.g. {{foreach:m.children as c}})
                // Find the enclosing PARENT foreach
                $before = substr($content, 0, $match_start);
                $parent_pos = strrpos($before, '{{foreach:');
                if ($parent_pos !== false) {
                    $parent_header_end = strpos($content, '}}', $parent_pos + 10);
                    if ($parent_header_end !== false) {
                        $parent_header = substr($content, $parent_pos + 10, $parent_header_end - $parent_pos - 10);
                        // Check if parent is a top-level array (not another children)
                        if (strpos(trim($parent_header), '.') === false) {
                            // Find the matching {{endforeach}} for the parent.
                            // Count nested foreach/endforeach pairs after the parent start.
                            $search_pos = $parent_header_end + 2;
                            $depth = 1;
                            $parent_end = false;
                            while ($depth > 0 && $search_pos < strlen($content)) {
                                $next_foreach = strpos($content, '{{foreach:', $search_pos);
                                $next_endforeach = strpos($content, '{{endforeach}}', $search_pos);
                                if ($next_endforeach === false) break;
                                if ($next_foreach !== false && $next_foreach < $next_endforeach) {
                                    $depth++;
                                    $search_pos = $next_foreach + 10;
                                } else {
                                    $depth--;
                                    if ($depth === 0) {
                                        $parent_end = $next_endforeach + strlen('{{endforeach}}');
                                        break;
                                    }
                                    $search_pos = $next_endforeach + strlen('{{endforeach}}');
                                }
                            }
                            if ($parent_end !== false) {
                                $parent_full = substr($content, $parent_pos, $parent_end - $parent_pos);
                                $parent_output = process_parent_loop($parent_full, $parent_header, $data);
                                $content = substr_replace($content, $parent_output, $parent_pos, strlen($parent_full));
                                continue;
                            }
                        }
                    }
                }
                // Fallback: remove the children loop
                $content = substr_replace($content, '', $match_start, strlen($full_match));
                continue;
            }

            // Get the array data
            $array_data = $data[$arrayName] ?? null;
            if ($array_data === null) {
                // Try to get from item context (for nested loops)
                $content = substr_replace($content, '', $match_start, strlen($full_match));
                continue;
            }

            $output = '';
            if (is_array($array_data)) {
                foreach ($array_data as $item) {
                    $output .= process_loop_iteration($loop_body, $item, $itemName, $data);
                }
            }

            $content = substr_replace($content, $output, $match_start, strlen($full_match));
        } else {
            break; // No more matchable loops
        }
    }
    return $content;
}

/**
 * Process a parent foreach loop that contains children loops.
 */
function process_parent_loop($full_template, $header, $data) {
    // Extract loop body (between }} and last {{endforeach}})
    $body_start = strpos($full_template, '}}') + 2;
    $body_end = strrpos($full_template, '{{endforeach}}');
    $loop_body = substr($full_template, $body_start, $body_end - $body_start);
    
    @list($arrayName, $itemName) = array_map('trim', explode(' as ', $header));
    if (!$arrayName || !$itemName) return '';
    
    $array_data = $data[$arrayName] ?? null;
    if (!is_array($array_data)) return '';
    
    $output = '';
    foreach ($array_data as $item) {
        $output .= process_loop_iteration($loop_body, $item, $itemName, $data);
    }
    return $output;
}

/**
 * Process a single iteration of a foreach loop.
 * Handles variables, conditions, and nested children loops.
 */
function process_loop_iteration($loopContent, $item, $itemName, $data) {
    $tempContent = $loopContent;

    // --- Handle nested children foreach: {{foreach:m.children as c}} ---
    if (isset($item['children']) && is_array($item['children'])) {
        $pattern = '/{{foreach:' . preg_quote($itemName, '/') . '\.children as (\w+)}}(.*?){{endforeach}}/s';
        if (!empty($item['children'])) {
            $tempContent = preg_replace_callback($pattern, function($child_matches) use ($item, $data) {
                $childName = $child_matches[1];
                $childLoop = $child_matches[2];
                $childOutput = '';
                foreach ($item['children'] as $child) {
                    $cc = $childLoop;
                    // Replace child variables {{c.title}}, {{c.slug}}
                    foreach ($child as $ck => $cv) {
                        if (is_scalar($cv)) {
                            $cc = str_replace("{{{$childName}.{$ck}}}", h($cv), $cc);
                        }
                    }
                    // Process if conditions
                    $cc = preg_replace_callback('/{{if:(.*?)}}(.*?){{endif}}/s', function($if_m) use ($child, $data, $childName) {
                        $cs = trim($if_m[1]);
                        $ct = $if_m[2];
                        // Truthy check
                        if (strpos($cs, '==') === false && strpos($cs, '!=') === false && strpos($cs, '||') === false) {
                            $val = (strpos($cs, "{$childName}.") === 0) ? ($child[substr($cs, strlen($childName)+1)] ?? null) : ($data[$cs] ?? null);
                            return !empty($val) ? $ct : '';
                        }
                        // == comparison
                        if (strpos($cs, "{$childName}.slug") === 0 && strpos($cs, '==') !== false) {
                            $parts = explode('==', $cs);
                            $val = trim($parts[1]);
                            return ($child['slug'] ?? '') == $val ? $ct : '';
                        }
                        return $ct;
                    }, $cc);
                    // Process page_url
                    $clean_urls = !empty($data['clean_urls']);
                    $cur_lang = $data['current_lang'] ?? 'ru';
                    $def_lang = $data['default_lang'] ?? 'ru';
                    $cc = preg_replace_callback('/{{page_url:([^}]+)}}/', function($m) use ($clean_urls, $cur_lang, $def_lang, $child, $childName) {
                        $param = trim($m[1]);
                        if (strpos($param, $childName . '.') === 0) {
                            $key = substr($param, strlen($childName) + 1);
                            $param = $child[$key] ?? $param;
                        }
                        if ($clean_urls) {
                            $url = '/' . $param;
                            if ($cur_lang !== $def_lang) $url .= '?lang=' . $cur_lang;
                        } else {
                            $url = '?page=' . $param;
                            if ($cur_lang !== $def_lang) $url .= '&lang=' . $cur_lang;
                        }
                        return $url;
                    }, $cc);
                    $childOutput .= $cc;
                }
                return $childOutput;
            }, $tempContent);
        } else {
            // No children — remove the block
            $tempContent = preg_replace($pattern, '', $tempContent);
        }
    } else {
        // No children property — remove any children foreach blocks
        $pattern = '/{{foreach:' . preg_quote($itemName, '/') . '\.children as \w+}}.*?{{endforeach}}/s';
        $tempContent = preg_replace($pattern, '', $tempContent);
    }

    // --- Handle if conditions {{if:...}} ---
    $tempContent = preg_replace_callback('/{{if:(.*?)}}(.*?){{endif}}/s', function($if_matches) use ($item, $data, $itemName) {
        $condition_string = $if_matches[1];
        $content_if_true = $if_matches[2];
        
        // OR operator: {{if:p.slug||home|about}}
        if (strpos($condition_string, '||') !== false) {
            list($var_str, $values_str) = array_map('trim', explode('||', $condition_string, 2));
            $values = array_map('trim', explode('|', $values_str));
            $val = (strpos($var_str, "{$itemName}.") === 0) ? ($item[substr($var_str, strlen($itemName)+1)] ?? null) : ($data[$var_str] ?? null);
            $result = in_array($val, $values);
            return $result ? $content_if_true : '';
        }
        
        // Truthy check: {{if:m.has_children}} (no operator)
        if (strpos($condition_string, '==') === false && strpos($condition_string, '!=') === false) {
            $var_str = trim($condition_string);
            $val = (strpos($var_str, "{$itemName}.") === 0) ? ($item[substr($var_str, strlen($itemName)+1)] ?? null) : ($data[$var_str] ?? null);
            return !empty($val) ? $content_if_true : '';
        }
        
        // == and != operators
        @list($var1_str, $operator, $var2_str) = array_map('trim', preg_split('/\s+(==|!=)\s+/', $condition_string, -1, PREG_SPLIT_DELIM_CAPTURE));
        if (!$var1_str || !$operator || !$var2_str) return '';

        $val1 = (strpos($var1_str, "{$itemName}.") === 0) ? ($item[substr($var1_str, strlen($itemName)+1)] ?? null) : ($data[$var1_str] ?? null);
        $val2 = (strpos($var2_str, "{$itemName}.") === 0) ? ($item[substr($var2_str, strlen($itemName)+1)] ?? null) : ($data[$var2_str] ?? null);

        $result = false;
        if ($operator == '==') { $result = ($val1 == $val2); }
        elseif ($operator == '!=') { $result = ($val1 != $val2); }
        
        return $result ? $content_if_true : '';
    }, $tempContent);

    // --- Replace item variables {{p.title}}, {{p.slug}} ---
    foreach ($item as $key => $value) {
        if (is_scalar($value)) {
            $tempContent = str_replace("{{{$itemName}.{$key}}}", h($value), $tempContent);
        }
    }

    // --- Process page_url inside loop ---
    $clean_urls = !empty($data['clean_urls']);
    $cur_lang = $data['current_lang'] ?? 'ru';
    $def_lang = $data['default_lang'] ?? 'ru';
    $tempContent = preg_replace_callback('/{{page_url:([^}]+)}}/', function($m) use ($clean_urls, $cur_lang, $def_lang, $item, $itemName) {
        $param = trim($m[1]);
        if (strpos($param, $itemName . '.') === 0) {
            $key = substr($param, strlen($itemName) + 1);
            $param = $item[$key] ?? $param;
        }
        if ($clean_urls) {
            $url = '/' . $param;
            if ($cur_lang !== $def_lang) $url .= '?lang=' . $cur_lang;
        } else {
            $url = '?page=' . $param;
            if ($cur_lang !== $def_lang) $url .= '&lang=' . $cur_lang;
        }
        return $url;
    }, $tempContent);

    // --- Process lang_url inside loop ---
    $cur_slug = $data['current_slug'] ?? 'home';
    $tempContent = preg_replace_callback('/{{lang_url:([^}]+)}}/', function($m) use ($clean_urls, $cur_slug, $item, $itemName) {
        $param = trim($m[1]);
        if (strpos($param, 'lang.') === 0) {
            $key = substr($param, 5);
            $param = $item[$key] ?? $param;
        }
        if ($clean_urls) {
            return '/' . $cur_slug . '?lang=' . $param;
        }
        return '?page=' . $cur_slug . '&lang=' . $param;
    }, $tempContent);

    return $tempContent;
}
