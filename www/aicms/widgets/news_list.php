<?php
// Этот файл выводит полный список новостей на странице /news в виде аккордеона
// Settings: first_open, date_format
// Tailwind + Alpine.js implementation (replaces Bootstrap accordion)

$module_file = __DIR__ . '/../admin/core/module_settings.php';
if (file_exists($module_file)) require_once $module_file;
$nl_settings = function_exists('module_get_settings')
    ? module_get_settings('news_list', ['first_open' => true, 'date_format' => 'd.m.Y'])
    : ['first_open' => true, 'date_format' => 'd.m.Y'];

if (!empty($news)):
    $date_fmt = $nl_settings['date_format'] ?? 'd.m.Y';
    $first_open = !empty($nl_settings['first_open']);
    // Initialize Alpine state: first item open if first_open=true
    $initial_open = $first_open && !empty($news[0]['id']) ? (int)$news[0]['id'] : 0;
?>
<div class="space-y-3" x-data="{open: <?= $initial_open ?>}">
    <?php foreach($news as $item): $iid = (int)$item['id']; ?>
    <div class="border border-slate-200 rounded-lg overflow-hidden bg-white">
        <button type="button"
                @click="open = open===<?= $iid ?> ? 0 : <?= $iid ?>"
                class="w-full text-left px-5 py-4 hover:bg-slate-50 flex justify-between items-center gap-3 transition"
                :class="open===<?= $iid ?> ? 'bg-slate-50' : ''">
            <span class="font-semibold text-slate-800"><?= h($item['title']) ?></span>
            <span class="text-xs text-slate-500 ml-auto whitespace-nowrap"><?= date($date_fmt, strtotime($item['date'])) ?></span>
            <i class="fas fa-chevron-down text-slate-400 transition-transform duration-200" :class="open===<?= $iid ?> ? 'rotate-180' : ''"></i>
        </button>
        <div x-show="open===<?= $iid ?>" x-collapse.duration.300ms class="px-5 py-4 border-t border-slate-200 text-slate-700 prose max-w-none">
            <?= $item['content'] ?>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php else: ?>
<p class="text-slate-500">Новостей пока нет.</p>
<?php endif; ?>
