/**
 * Lumina Editor i18n module
 *
 * Provides:
 *   - getLang()  — current language code (ru|en), reads localStorage('lumina_lang'), default 'ru'
 *   - setLang(lang) — persist language choice to localStorage
 *   - t(key)     — translate a key for the current language; falls back to key
 *   - getAvailableLangs() — list of supported languages
 *
 * The dictionary lives in TRANSLATIONS below. Add new keys there.
 */

const TRANSLATIONS = {
    // ----- Toolbar button titles -----
    'bold':              { ru: 'Жирный',           en: 'Bold' },
    'italic':            { ru: 'Курсив',            en: 'Italic' },
    'underline':         { ru: 'Подчёркнутый',      en: 'Underline' },
    'strikethrough':     { ru: 'Зачёркнутый',       en: 'Strikethrough' },
    'strike':            { ru: 'Зачёркнутый',       en: 'Strikethrough' },
    'superscript':       { ru: 'Верхний индекс',    en: 'Superscript' },
    'subscript':         { ru: 'Нижний индекс',     en: 'Subscript' },
    'h1':                { ru: 'Заголовок 1',       en: 'Heading 1' },
    'h2':                { ru: 'Заголовок 2',       en: 'Heading 2' },
    'h3':                { ru: 'Заголовок 3',       en: 'Heading 3' },
    'h4':                { ru: 'Заголовок 4',       en: 'Heading 4' },
    'ul':                { ru: 'Маркированный список', en: 'Bulleted List' },
    'ol':                { ru: 'Нумерованный список',  en: 'Numbered List' },
    'alignLeft':         { ru: 'По левому краю',    en: 'Align Left' },
    'alignCenter':       { ru: 'По центру',         en: 'Align Center' },
    'alignRight':        { ru: 'По правому краю',   en: 'Align Right' },
    'alignJustify':      { ru: 'По ширине',         en: 'Justify' },
    'link':              { ru: 'Ссылка',            en: 'Link' },
    'anchor':            { ru: 'Якорь (ID)',        en: 'Anchor (ID)' },
    'image':             { ru: 'Изображение',       en: 'Image' },
    'video':             { ru: 'Видео',             en: 'Video' },
    'table':             { ru: 'Таблица',           en: 'Table' },
    'code':              { ru: 'Код',               en: 'Code' },
    'codeBlock':         { ru: 'Блок кода',         en: 'Code Block' },
    'sourceCode':        { ru: 'Исходный код',      en: 'Source Code' },
    'preview':           { ru: 'Предпросмотр',      en: 'Preview' },
    'theme_view':        { ru: 'Режим шаблона',     en: 'Theme View' },
    'exitPreview':       { ru: 'Выйти из предпросмотра', en: 'Exit Preview' },
    'settings':          { ru: 'Настройки',         en: 'Settings' },
    'fullscreen':        { ru: 'Во весь экран',     en: 'Fullscreen' },
    'insertModule':      { ru: 'Вставить модуль',   en: 'Insert Module' },
    'aiGenerator':       { ru: 'AI генератор',      en: 'AI Generator' },
    'blockTemplates':    { ru: 'Шаблоны блоков',    en: 'Block Templates' },
    'blocks':            { ru: 'Блоки',             en: 'Blocks' },
    'undo':              { ru: 'Отменить',          en: 'Undo' },
    'redo':              { ru: 'Повторить',         en: 'Redo' },
    'textColor':         { ru: 'Цвет текста',       en: 'Text Color' },
    'highlightColor':    { ru: 'Цвет выделения',    en: 'Highlight Color' },
    'fontSize':          { ru: 'Размер шрифта',     en: 'Font Size' },
    'fontFamily':        { ru: 'Шрифт',             en: 'Font Family' },
    'quote':             { ru: 'Цитата',            en: 'Blockquote' },
    'checklist':         { ru: 'Список дел',        en: 'To-Do List' },
    'box':               { ru: 'Бокс',              en: 'Box' },
    'section':           { ru: 'Секция',            en: 'Section' },
    'separator':         { ru: 'Разделитель',       en: 'Separator' },
    'emoji':             { ru: 'Эмодзи',            en: 'Emoji' },
    'ai_image':          { ru: 'AI генерация изображения', en: 'AI Image Generator' },
    'emoji_search_ph':   { ru: 'Поиск эмодзи...',   en: 'Search emoji...' },
    'emoji_no_results':  { ru: 'Ничего не найдено', en: 'No results' },
    'emoji_recent':      { ru: 'Недавние',          en: 'Recent' },
    'emoji_no_recent':   { ru: 'Нет недавних эмодзи', en: 'No recently used' },
    'specialChars':      { ru: 'Спецсимволы',       en: 'Special Characters' },
    'tooltip':           { ru: 'Вставить/редактировать всплывающую подсказку', en: 'Insert/Edit Tooltip' },
    'createButton':      { ru: 'Создать кнопку',    en: 'Create Button' },
    'toggleButton':      { ru: 'Вставить кнопку «Читать далее / Свернуть»', en: 'Insert "Read more / Collapse" button' },
    'duplicate':         { ru: 'Дублировать',       en: 'Duplicate Selection' },
    'wordCount':         { ru: 'Статистика слов',   en: 'Word Count Statistics' },
    'clearFormat':       { ru: 'Очистить форматирование', en: 'Clear Formatting' },
    'fixSpacing':        { ru: 'Исправить пробелы в пунктуации', en: 'Fix Punctuation Spacing' },
    'spellCheck':        { ru: 'Переключить проверку орфографии', en: 'Toggle Spellcheck' },
    'lineHeight':        { ru: 'Межстрочный интервал', en: 'Line Height' },
    'letterSpacing':     { ru: 'Межбуквенный интервал', en: 'Letter Spacing' },
    'kerning':           { ru: 'Кернинг шрифта',    en: 'Font Kerning' },

    // ----- Modal titles -----
    'modal_settings':       { ru: 'Настройки',                en: 'Settings' },
    'modal_insertLink':     { ru: 'Вставить ссылку',          en: 'Insert Link' },
    'modal_insertImage':    { ru: 'Вставить изображение',     en: 'Insert Image' },
    'modal_insertVideo':    { ru: 'Вставить видео',           en: 'Insert Video' },
    'modal_insertTable':    { ru: 'Вставить таблицу',         en: 'Insert Table' },
    'modal_insertModule':   { ru: 'Вставить модуль',          en: 'Insert Module' },
    'modal_sourceCode':     { ru: 'Исходный код',             en: 'Source Code' },
    'modal_preview':        { ru: 'Предпросмотр',             en: 'Preview' },
    'modal_exitPreview':    { ru: 'Выйти из предпросмотра',   en: 'Exit Preview' },
    'modal_aiGenerator':    { ru: 'AI генератор',             en: 'AI Generator' },
    'modal_blockTemplates': { ru: 'Шаблоны блоков',           en: 'Block Templates' },
    'modal_setTooltip':     { ru: 'Установить всплывашку',    en: 'Set Tooltip' },
    'modal_setAnchor':      { ru: 'Установить якорь',         en: 'Set Anchor' },
    'modal_wordCount':      { ru: 'Статистика',               en: 'Word Count' },

    // ----- Settings tab labels -----
    'tab_general':         { ru: 'Общие',                en: 'General' },
    'tab_toolbarButtons':  { ru: 'Кнопки панели',        en: 'Toolbar Buttons' },
    'tab_bubbleMenus':     { ru: 'Всплывающие меню',     en: 'Bubble Menus' },
    'tab_language':        { ru: 'Язык',                 en: 'Language' },

    // ----- Common buttons -----
    'btn_save':    { ru: 'Сохранить',  en: 'Save' },
    'btn_cancel':  { ru: 'Отмена',     en: 'Cancel' },
    'btn_close':   { ru: 'Закрыть',    en: 'Close' },
    'btn_insert':  { ru: 'Вставить',   en: 'Insert' },
    'btn_delete':  { ru: 'Удалить',    en: 'Delete' },
    'btn_ok':      { ru: 'OK',         en: 'OK' },
    'btn_apply':   { ru: 'Применить',  en: 'Apply' },

    // ----- Status bar -----
    'stat_words':       { ru: 'слов',         en: 'words' },
    'stat_characters':  { ru: 'символов',     en: 'characters' },
    'stat_paragraphs':  { ru: 'абзацев',      en: 'paragraphs' },

    // ----- Device names / preview controls -----
    'device_desktop':       { ru: 'Десктоп',          en: 'Desktop' },
    'device_tablet':        { ru: 'Планшет',          en: 'Tablet' },
    'device_mobile':        { ru: 'Мобильный',        en: 'Mobile' },
    'device_rotateScreen':  { ru: 'Повернуть экран',  en: 'Rotate Screen' },
    'device_refreshPreview':{ ru: 'Обновить предпросмотр', en: 'Refresh Preview' },

    // ----- Settings tab content -----
    'settings_stickyToolbar':    { ru: 'Липкая панель',                 en: 'Sticky Toolbar' },
    'settings_moduleSuffix':     { ru: 'модуль',                        en: 'Module' },
    'settings_buttonsHint':      { ru: 'Перетащите для сортировки, переключите для отображения', en: 'Drag to reorder buttons, toggle to show/hide' },
    'settings_selectsHint':      { ru: 'Выпадающие списки (только видимость)', en: 'Dropdown selects (visibility only)' },
    'settings_bubblesHint':      { ru: 'Включить/выключить всплывающие меню', en: 'Enable/Disable floating menus' },
    'settings_bubbleText':       { ru: 'Меню текста',                   en: 'Text Selection Bubble' },
    'settings_bubbleImage':      { ru: 'Панель изображений',            en: 'Image Toolbar' },
    'settings_bubbleBlock':      { ru: 'Панель блоков (Layouts)',       en: 'Block Toolbar (Layouts)' },
    'settings_bubbleBox':        { ru: 'Панель боксов',                 en: 'Box Toolbar' },
    'settings_bubbleQuickInsert':{ ru: 'Быстрая вставка (+)',           en: 'Quick Insert Menu (+)' },
    'settings_bubbleCodeBlock':  { ru: 'Меню блока кода',               en: 'Code Block Menu' },
    'settings_languageLabel':    { ru: 'Язык редактора',                en: 'Editor Language' },
    'settings_languageNote':     { ru: 'Язык редактора не зависит от языка админки', en: 'Editor language is independent from admin language' },
    'settings_languageSavedReload': { ru: 'Язык сохранён. Перезагрузка…', en: 'Language saved. Reloading…' },

    // ----- Search & Replace -----
    'search_replace_title': { ru: 'Поиск и замена',  en: 'Search & Replace' },
    'search_find':          { ru: 'Найти',            en: 'Find' },
    'search_replace':       { ru: 'Заменить на',      en: 'Replace with' },
    'search_placeholder':   { ru: 'Текст для поиска...', en: 'Text to find...' },
    'replace_placeholder':  { ru: 'Текст для замены...', en: 'Replacement text...' },
    'find_next':            { ru: 'Найти далее',      en: 'Find Next' },
    'replace_one':          { ru: 'Заменить',         en: 'Replace' },
    'replace_all':          { ru: 'Заменить все',     en: 'Replace All' },
    'found_match':          { ru: 'Найдено',           en: 'Found' },
    'not_found':            { ru: 'Не найдено',        en: 'Not found' },
    'replaced':             { ru: 'Заменено',          en: 'Replaced' },
    'replaced_next':        { ru: 'Заменено, найдено следующее', en: 'Replaced, found next' },
    'replaced_done':        { ru: 'Заменено, больше нет совпадений', en: 'Replaced, no more matches' },
    'replaced_count':       { ru: 'Заменено: ',        en: 'Replaced: ' },

    // ----- Link modal -----
    'link_url':           { ru: 'URL',              en: 'URL' },
    'link_openInNewTab':  { ru: 'Открыть в новой вкладке', en: 'Open in new tab' },

    // ----- Context menu / formatting -----
    'format_html':        { ru: 'Форматировать HTML', en: 'Format HTML' },
    'history':            { ru: 'История версий',     en: 'Version History' },
    'cut':                { ru: 'Вырезать',           en: 'Cut' },
    'copy':               { ru: 'Копировать',         en: 'Copy' },
    'paste':              { ru: 'Вставить',           en: 'Paste' },
    'paste_ctrl_v':       { ru: 'Нажмите Ctrl+V для вставки', en: 'Press Ctrl+V to paste' },
    'html_formatted':     { ru: 'HTML отформатирован', en: 'HTML formatted' },
    'link_insert':        { ru: 'Вставить ссылку',  en: 'Insert Link' },

    // ----- Image modal -----
    'image_uploadFile':   { ru: 'Загрузить файл',   en: 'Upload File' },
    'image_byUrl':        { ru: 'По URL',           en: 'By URL' },
    'image_dropHere':     { ru: 'Перетащите изображение сюда или нажмите для выбора', en: 'Drag & Drop image here<br>or click to select' },
    'image_urlLabel':     { ru: 'URL изображения:', en: 'Image URL:' },
    'image_insert':       { ru: 'Вставить изображение', en: 'Insert Image' },
    'image_uploading':    { ru: 'Загрузка…',        en: 'Uploading...' },

    // ----- Video modal -----
    'video_urlLabel':     { ru: 'URL видео / embed:', en: 'Video/Embed URL:' },
    'video_hint':         { ru: 'Поддержка YouTube, Vimeo, Dailymotion или прямой .mp4.', en: 'Supports YouTube, Vimeo, Dailymotion, or direct .mp4 links.' },
    'video_insert':       { ru: 'Вставить видео',   en: 'Insert Video' },

    // ----- Table modal -----
    'table_rows':         { ru: 'Строки',           en: 'Rows' },
    'table_cols':         { ru: 'Столбцы',          en: 'Cols' },

    // ----- Anchor / tooltip modals -----
    'anchor_idLabel':     { ru: 'ID якоря:',        en: 'Anchor ID:' },
    'anchor_hint':        { ru: 'Используйте этот ID для ссылки (например, #section-1)', en: 'Use this ID to link here (e.g. #section-1)' },
    'anchor_setId':       { ru: 'Установить ID',    en: 'Set ID' },
    'tooltip_label':      { ru: 'Текст всплывашки:', en: 'Tooltip Text:' },
    'tooltip_hint':       { ru: 'Оставьте пустым, чтобы удалить всплывашку.', en: 'Leave empty to remove tooltip.' },

    // ----- Word count -----
    'wordCount_count':    { ru: 'Показатель',       en: 'Count' },
    'wordCount_document': { ru: 'Документ',         en: 'Document' },
    'wordCount_selection':{ ru: 'Выделение',        en: 'Selection' },
    'wordCount_words':    { ru: 'Слова',            en: 'Words' },
    'wordCount_chars':    { ru: 'Символы',          en: 'Characters' },
    'wordCount_paras':    { ru: 'Абзацы',           en: 'Paragraphs' },

    // ----- Format select options -----
    'fmt_normal':         { ru: 'Обычный',          en: 'Normal' },
    'fmt_quote':          { ru: 'Цитата',           en: 'Quote' },
    'fmt_code':           { ru: 'Код',              en: 'Code' },

    // ----- Misc -----
    'fixSpacing_selectText': { ru: 'Выделите текст для исправления пробелов.', en: 'Please select text to fix spacing.' }
};

/**
 * Read the current editor language from localStorage.
 * Falls back to 'ru' if unset or invalid.
 */
export function getLang() {
    try {
        const lang = localStorage.getItem('lumina_lang');
        if (lang && (lang === 'ru' || lang === 'en')) return lang;
    } catch (e) { /* localStorage may be unavailable */ }
    return 'ru';
}

/**
 * Persist the chosen language to localStorage.
 * @param {string} lang — 'ru' or 'en'
 */
export function setLang(lang) {
    try {
        if (lang !== 'ru' && lang !== 'en') lang = 'ru';
        localStorage.setItem('lumina_lang', lang);
    } catch (e) { /* ignore */ }
    return lang;
}

/**
 * Translate a key for the current language.
 * Falls back to the key itself if no translation exists.
 * @param {string} key
 * @returns {string}
 */
export function t(key) {
    if (!key) return '';
    const entry = TRANSLATIONS[key];
    if (!entry) return key;
    const lang = getLang();
    return entry[lang] || entry.en || entry.ru || key;
}

/**
 * List of supported languages for the settings dropdown.
 */
export function getAvailableLangs() {
    return [
        { code: 'ru', name: 'Русский' },
        { code: 'en', name: 'English' }
    ];
}

export default {
    getLang,
    setLang,
    t,
    getAvailableLangs,
    TRANSLATIONS
};
