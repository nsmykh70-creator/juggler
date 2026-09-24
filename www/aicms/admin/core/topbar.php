<?php
// Sticky topbar component.
// Usage: include __DIR__ . '/core/topbar.php';
// Optional variables (set BEFORE including):
//   $page_title    — overrides default title
//   $breadcrumb    — array of [label, url?] items
//   $action_panel  — HTML string for the action panel body (rendered below)
//   $action_panel_title — title for the panel (default: "Действия")
//   $action_panel_collapsed — bool, initial state
if (!isset($action_panel_title)) $action_panel_title = 'Действия';
if (!isset($breadcrumb)) $breadcrumb = [];
// Default: NOT collapsed (panels visible by default)
if (!isset($action_panel_collapsed)) $action_panel_collapsed = false;
$_tp_collapsed = $action_panel_collapsed ? 'is-collapsed' : '';
$current_page = basename($_SERVER['PHP_SELF']);
?>
<div class="aicms-topbar">
    <div class="aicms-topbar-title">
        <!-- Mobile sidebar toggle -->
        <button onclick="window.openMobileSidebar()" class="lg:hidden w-9 h-9 flex items-center justify-center rounded-lg text-slate-500 hover:bg-slate-100 hover:text-slate-700 transition mr-1" title="<?= t('menu') ?>">
            <i class="fas fa-bars"></i>
        </button>
        <!-- Desktop collapse toggle -->
        <button onclick="window.toggleSidebar()" class="hidden lg:flex w-9 h-9 items-center justify-center rounded-lg text-slate-500 hover:bg-slate-100 hover:text-slate-700 transition mr-1" title="<?= t('collapse_menu') ?>">
            <i class="fas fa-outdent"></i>
        </button>
        <div class="min-w-0">
            <?php if (!empty($breadcrumb)): ?>
            <div class="aicms-breadcrumb mb-0.5">
                <?php foreach ($breadcrumb as $_bi => $_bc): ?>
                    <?php if ($_bi > 0): ?><span class="sep">/</span><?php endif; ?>
                    <?php if (!empty($_bc['url'])): ?>
                    <a href="<?= h($_bc['url']) ?>"><?= h($_bc['label']) ?></a>
                    <?php else: ?>
                    <span><?= h($_bc['label']) ?></span>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
            <h1><?= h($page_title ?? '') ?></h1>
        </div>
    </div>
    <div class="aicms-topbar-actions">
        <!-- NOTE: children are laid out with flex-direction: row-reverse
             (see tailwind.css .aicms-topbar-actions), so the FIRST child is
             the RIGHTMOST element and the whole block stretches LEFT when
             any button expands on hover. The theme toggle is therefore
             pinned to the right edge of the topbar. -->
        <!-- Theme toggle — pinned to the right edge -->
        <button class="theme-toggle" onclick="document.body.classList.toggle('dark-theme');localStorage.setItem('aicms_dark',document.body.classList.contains('dark-theme')?'1':'0');">
            <i class="fas fa-moon" id="themeIcon"></i><span class="btn-label"><?= t('dark_theme') ?></span>
        </button>
        <!-- Logout -->
        <form method="POST" action="logout.php" style="display:inline; margin:0;">
            <?= csrf_field() ?>
            <button type="submit" class="aicms-btn aicms-btn--danger aicms-btn--sm aicms-expand-btn" style="border:none;cursor:pointer;">
                <i class="fas fa-sign-out-alt"></i><span class="btn-label"><?= t('logout_btn') ?></span>
            </button>
        </form>
        <!-- Open site -->
        <a href="../" target="_blank" class="aicms-btn aicms-btn--ghost aicms-btn--sm aicms-expand-btn aicms-topbar-site">
            <i class="fas fa-external-link-alt"></i><span class="btn-label"><?= t('open_site') ?></span>
        </a>
        <!-- Advanced search -->
        <a href="search.php" class="aicms-btn aicms-btn--ghost aicms-btn--sm aicms-expand-btn aicms-topbar-search">
            <i class="fas fa-search"></i><span class="btn-label"><?= t('advanced_search') ?></span>
        </a>
        <!-- Global search -->
        <div class="relative" x-data="{q:'',results:[],show:false}" @click.outside="show=false">
            <input type="text" x-model="q" @input="if(q.length>1){fetch('api_search.php?q='+encodeURIComponent(q)).then(r=>r.json()).then(d=>{results=d;show=true})}else{show=false;results=[]}" placeholder="<?= t('search_placeholder') ?>" class="h-8 w-40 md:w-56 px-3 pr-8 text-sm border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none">
            <i class="fas fa-search absolute right-3 top-1/2 -translate-y-1/2 text-slate-400 text-xs"></i>
            <div x-show="show && results.length>0" x-cloak class="absolute right-0 top-full mt-1 w-96 bg-white rounded-xl shadow-2xl border border-slate-200 py-2 z-50 max-h-[28rem] overflow-y-auto">
                <template x-for="r in results.slice(0, 12)" :key="r.url">
                    <a :href="r.url" class="flex items-start gap-3 px-4 py-2.5 hover:bg-slate-50 border-b border-slate-100 last:border-0" @click="show=false">
                        <i class="fas mt-0.5 text-slate-400" :class="r.icon"></i>
                        <div class="flex-1 min-w-0">
                            <div class="text-sm font-medium text-slate-800 truncate" x-text="r.title"></div>
                            <div class="text-xs text-slate-400 mb-0.5" x-text="r.type"></div>
                            <div x-show="r.snippet" class="text-xs text-slate-500 line-clamp-2" x-html="r.snippet || ''"></div>
                        </div>
                    </a>
                </template>
                <a href="search.php" class="block text-center text-xs text-blue-600 hover:text-blue-700 py-2 mt-1 border-t border-slate-200 font-medium">
                    <?= t('advanced_search') ?> →
                </a>
            </div>
        </div>
        <!-- Multi-site switcher (only shown if more than 1 site) — leftmost -->
        <?php if (function_exists('is_multisite') && is_multisite()): ?>
            <?php $_sites_list = get_sites(); $_cur = current_site(); ?>
            <form method="POST" action="sites.php" class="inline">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="switch">
                <select name="site_id" onchange="this.form.submit()" class="h-8 px-2 text-sm border border-slate-300 rounded-lg outline-none focus:ring-2 focus:ring-blue-500 cursor-pointer" title="<?= h(t('switch_site') ?? 'Switch site') ?>">
                    <?php foreach ($_sites_list as $_s): if (empty($_s['enabled'])) continue; ?>
                    <option value="<?= h($_s['id']) ?>" <?= $_cur && $_cur['id'] === $_s['id'] ? 'selected' : '' ?>>
                        <?= h($_s['name']) ?> (<?= h($_s['id']) ?>)
                    </option>
                    <?php endforeach; ?>
                </select>
            </form>
        <?php endif; ?>
    </div>
</div>

<?php if (!empty($action_panel)): ?>
<style>
.aicms-action-panel { background:#fff; border-radius:12px; border:1px solid #e2e8f0; margin-bottom:20px; }
.aicms-action-panel-header { display:flex; align-items:center; justify-content:space-between; padding:12px 20px; border-bottom:1px solid #f1f5f9; }
.aicms-action-panel-title { display:flex; align-items:center; gap:8px; font-size:14px; font-weight:600; color:#475569; }
.aicms-action-panel-body { padding:16px 20px; display:flex; flex-wrap:wrap; align-items:center; gap:8px; }
.aicms-action-panel.is-collapsed .aicms-action-panel-body { display:none !important; }
.aicms-action-panel.is-collapsed .aicms-action-panel-header { border-bottom:0 !important; }
</style>
<div class="aicms-action-panel <?= $_tp_collapsed ?>" id="aicmsActionPanel">
    <div class="aicms-action-panel-header">
        <div class="aicms-action-panel-title">
            <i class="fas fa-bolt text-blue-500"></i>
            <span><?= h($action_panel_title) ?></span>
        </div>
        <button onclick="var p=document.getElementById('aicmsActionPanel');p.classList.toggle('is-collapsed');this.querySelector('i').className='fas text-xs '+(p.classList.contains('is-collapsed')?'fa-chevron-down':'fa-chevron-up');" class="w-7 h-7 flex items-center justify-center rounded-md text-slate-400 hover:bg-slate-100 hover:text-slate-700 transition" title="Свернуть/Развернуть">
            <i class="fas text-xs <?= $_tp_collapsed ? 'fa-chevron-down' : 'fa-chevron-up' ?>"></i>
        </button>
    </div>
    <div class="aicms-action-panel-body">
        <?= $action_panel ?>
    </div>
</div>
<?php endif; ?>
