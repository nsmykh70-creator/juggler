/**
 * Module Picker for Lumina Editor
 * --------------------------------
 * Opens a modal listing all available AICMS modules (PHP files in /modules/).
 * Clicking a module inserts the corresponding shortcode
 * (e.g. `{{module:contact_form}}`) at the current cursor position.
 *
 * Styling note: all visual styles (colors, backgrounds, borders) are kept
 * in styles.js as CSS classes (.lumina-mod-*) so they can be overridden
 * by dark-theme rules. No inline color/background styles here — they
 * would override the dark-theme CSS and cause invisible text.
 */

const MODULE_CATALOG = [
    { slug: 'contact_form',    title: 'Форма обратной связи',  desc: 'Форма с настраиваемым email и полями',                       icon: 'mail' },
    { slug: 'contact_info',    title: 'Карточка контактов',    desc: 'Адрес, телефон, email, часы работы',                        icon: 'map' },
    { slug: 'features_grid',   title: 'Сетка преимуществ',     desc: 'Карточки с иконками, заголовками и описаниями',            icon: 'layout' },
    { slug: 'cta_banner',      title: 'CTA-баннер',            desc: 'Баннер призыва к действию с кнопкой',                      icon: 'mouse-pointer' },
    { slug: 'social_links',    title: 'Социальные ссылки',     desc: 'Иконки соцсетей: Telegram, VK, YouTube и др.',             icon: 'users' },
    { slug: 'countdown_timer', title: 'Таймер обратного отсчёта', desc: 'Отсчёт до заданной даты/времени',                       icon: 'bar-chart' },
    { slug: 'example_module',  title: 'Пример модуля',         desc: 'Демонстрация настроек модуля',                              icon: 'box' },
    { slug: 'latest_news',     title: 'Лента новостей',        desc: 'Последние новости с настройками',                          icon: 'list' },
    { slug: 'booking',         title: 'Бронирование',          desc: 'Форма: дата, время, гости, контакты',                       icon: 'clipboard' },
    { slug: 'comments',        title: 'Комментарии',           desc: 'Комментарии и рейтинги (требует БД)',                       icon: 'message-square-plus', requires_db: true },
    { slug: 'shop',            title: 'Минимагазин',           desc: 'Каталог, корзина, заказы (требует БД)',                     icon: 'box', requires_db: true },
    { slug: 'forum',           title: 'Минифорум',             desc: 'Темы и сообщения (требует БД)',                             icon: 'message-square-plus', requires_db: true },
    { slug: 'auth_block',      title: 'Блок авторизации',      desc: 'Виджет входа / профиля пользователя',                       icon: 'users' },
    { slug: 'newsletter',      title: 'Подписка на рассылку',  desc: 'Email-подписка с сохранением в админку',                    icon: 'mail' },
    // === New modules (v2) ===
    { slug: 'hero',            title: 'Hero-секция',           desc: 'Большой блок с заголовком, подзаголовком и кнопкой',       icon: 'monitor' },
    { slug: 'pricing',         title: 'Тарифные планы',        desc: '3 колонки с ценами и списком возможностей',                icon: 'credit-card' },
    { slug: 'team',            title: 'Команда',               desc: 'Карточки сотрудников с фото и соцсетями',                  icon: 'users' },
    { slug: 'testimonials',    title: 'Отзывы клиентов',       desc: 'Карусель с отзывами и оценками',                           icon: 'star' },
    { slug: 'stats',           title: 'Статистика',            desc: 'Анимированные счётчики с цифрами',                         icon: 'bar-chart' },
    { slug: 'progress',        title: 'Прогресс-бары',         desc: 'Шкалы навыков с анимацией заполнения',                     icon: 'check-circle' },
    { slug: 'faq',             title: 'FAQ',                   desc: 'Раскрывающийся список вопросов и ответов',                 icon: 'help-circle' },
    { slug: 'accordion',       title: 'Аккордеон',             desc: 'Универсальные раскрывающиеся панели',                      icon: 'chevron-down' },
    { slug: 'tabs',            title: 'Вкладки',               desc: 'Переключение между блоками контента',                      icon: 'folder' },
    { slug: 'gallery',         title: 'Галерея',               desc: 'Сетка изображений с лайтбоксом',                           icon: 'image' },
    // === New modules (v3) ===
    { slug: 'map',             title: 'Карта',                 desc: 'Google Maps или Яндекс.Карты',                            icon: 'map' },
    { slug: 'video_gallery',   title: 'Видео-галерея',         desc: 'Сетка видео с модальным плеером',                         icon: 'video' },
    { slug: 'parallax',        title: 'Параллакс',             desc: 'Фоновое изображение с эффектом параллакса',               icon: 'image' },
];

export function openModulePicker(editor) {
    editor.saveSelection();

    // Search input — uses .lumina-mod-search class for dark-theme styling
    const content = `
        <div class="lumina-mod-picker">
            <input type="text" id="lumina-mod-search" class="lumina-mod-search" placeholder="Поиск модуля...">
            <div id="lumina-mod-grid" class="lumina-mod-grid"></div>
        </div>
    `;

    const body = editor.createModalOverlay('Вставить модуль', content);

    const grid = body.querySelector('#lumina-mod-grid');
    const searchInput = body.querySelector('#lumina-mod-search');

    function renderModules(filter) {
        const q = (filter || '').trim().toLowerCase();
        const items = MODULE_CATALOG.filter(m => {
            if (!q) return true;
            return m.title.toLowerCase().indexOf(q) !== -1 ||
                   m.slug.toLowerCase().indexOf(q) !== -1 ||
                   m.desc.toLowerCase().indexOf(q) !== -1;
        });

        if (!items.length) {
            grid.innerHTML = '<div class="lumina-mod-empty">Ничего не найдено.</div>';
            return;
        }

        grid.innerHTML = items.map(m => {
            const dbBadge = m.requires_db
                ? '<span class="lumina-mod-badge-db">БД</span>'
                : '';
            return `
                <div class="lumina-mod-item" data-slug="${m.slug}">
                    <div class="lumina-mod-icon">
                        ${editor.getIcon(m.icon || 'box')}
                    </div>
                    <div class="lumina-mod-info">
                        <div class="lumina-mod-title">
                            ${m.title}${dbBadge}
                        </div>
                        <div class="lumina-mod-desc">${m.desc}</div>
                        <div class="lumina-mod-code">{{module:${m.slug}}}</div>
                    </div>
                </div>
            `;
        }).join('');

        grid.querySelectorAll('.lumina-mod-item').forEach(el => {
            el.onclick = () => {
                const slug = el.dataset.slug;
                const shortcode = `{{module:${slug}}}`;
                editor.restoreSelection();
                editor.editor && editor.editor.focus();
                document.execCommand('insertHTML', false, shortcode);
                body.closest('.lumina-modal-overlay').remove();
                editor.sync();
                editor.pushHistory(true);
            };
        });
    }

    renderModules('');

    searchInput.oninput = (e) => renderModules(e.target.value);
    setTimeout(() => { searchInput.focus(); }, 50);
}

export default { openModulePicker, MODULE_CATALOG };
