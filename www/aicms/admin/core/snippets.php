<?php
return [
    'Герой-секции' => [
        [
            'name' => 'Текст + Картинка',
            'icon' => 'fa fa-star',
            'code' => '<section class="py-12 bg-slate-50">
    <div class="max-w-6xl mx-auto px-4">
        <div class="flex flex-col md:flex-row items-center gap-8">
            <div class="md:w-1/2">
                <h1 class="text-4xl md:text-5xl font-extrabold text-slate-900 mb-4">Заголовок героя</h1>
                <p class="text-lg text-slate-600 mb-6">Привлекающее внимание описание вашего продукта или услуги</p>
                <div class="flex flex-wrap gap-3">
                    <a href="#" class="inline-block px-6 py-3 bg-blue-600 text-white font-semibold rounded-lg hover:bg-blue-700 transition">Основное действие</a>
                    <a href="#" class="inline-block px-6 py-3 border border-slate-300 text-slate-700 font-semibold rounded-lg hover:bg-slate-100 transition">Узнать больше</a>
                </div>
            </div>
            <div class="md:w-1/2">
                <img src="https://via.placeholder.com/600x400" alt="Герой изображение" class="w-full rounded-xl shadow-lg">
            </div>
        </div>
    </div>
</section>'
        ],

        [
            'name' => 'Центрированный',
            'icon' => 'fa fa-bullseye',
            'code' => '<section class="py-20 text-center bg-gradient-to-br from-blue-600 to-indigo-700 text-white">
    <div class="max-w-4xl mx-auto px-4 py-10">
        <h1 class="text-4xl md:text-6xl font-extrabold mb-4">Главный заголовок</h1>
        <p class="text-xl mb-8 opacity-90">Краткое и мощное описание вашего предложения</p>
        <a href="#" class="inline-block px-8 py-4 bg-white text-blue-600 font-bold rounded-lg hover:bg-slate-100 transition text-lg">Начать</a>
    </div>
</section>'
        ]
    ],

    'Карточки' => [
        [
            'name' => 'Команда (3 человека)',
            'icon' => 'fa fa-users',
            'code' => '<section class="py-12">
    <div class="max-w-6xl mx-auto px-4">
        <h2 class="text-3xl font-bold text-center mb-10 text-slate-900">Наша команда</h2>
        <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
            <div class="bg-white rounded-xl shadow-md overflow-hidden">
                <img src="https://via.placeholder.com/400x300" class="w-full h-56 object-cover" alt="Член команды">
                <div class="p-6 text-center">
                    <h5 class="text-lg font-bold text-slate-900 m-0">Имя Фамилия</h5>
                    <p class="text-slate-500 text-sm mt-1 mb-3">Должность</p>
                    <p class="text-slate-700 m-0">Краткое описание специализации и опыта</p>
                </div>
            </div>
            <div class="bg-white rounded-xl shadow-md overflow-hidden">
                <img src="https://via.placeholder.com/400x300" class="w-full h-56 object-cover" alt="Член команды">
                <div class="p-6 text-center">
                    <h5 class="text-lg font-bold text-slate-900 m-0">Имя Фамилия</h5>
                    <p class="text-slate-500 text-sm mt-1 mb-3">Должность</p>
                    <p class="text-slate-700 m-0">Краткое описание специализации и опыта</p>
                </div>
            </div>
            <div class="bg-white rounded-xl shadow-md overflow-hidden">
                <img src="https://via.placeholder.com/400x300" class="w-full h-56 object-cover" alt="Член команды">
                <div class="p-6 text-center">
                    <h5 class="text-lg font-bold text-slate-900 m-0">Имя Фамилия</h5>
                    <p class="text-slate-500 text-sm mt-1 mb-3">Должность</p>
                    <p class="text-slate-700 m-0">Краткое описание специализации и опыта</p>
                </div>
            </div>
        </div>
    </div>
</section>'
        ],
        [
            'name' => 'Тарифные планы',
            'icon' => 'fa fa-dollar-sign',
            'code' => '<section class="py-12 bg-slate-50">
    <div class="max-w-6xl mx-auto px-4">
        <h2 class="text-3xl font-bold text-center mb-10 text-slate-900">Наши тарифы</h2>
        <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
            <div class="bg-white rounded-xl shadow-sm border border-slate-200 text-center p-6">
                <h4 class="text-lg font-semibold m-0 pb-4 border-b border-slate-200">Базовый</h4>
                <div class="py-6">
                    <span class="text-5xl font-extrabold text-slate-900">$0</span><span class="text-slate-500">/мес</span>
                    <ul class="list-none m-0 p-0 mt-4 mb-6 space-y-2 text-sm text-slate-600">
                        <li>10 пользователей</li>
                        <li>5 ГБ хранилища</li>
                        <li>Базовая поддержка</li>
                    </ul>
                    <button class="w-full py-2.5 border border-blue-600 text-blue-600 font-semibold rounded-lg hover:bg-blue-50 transition">Выбрать</button>
                </div>
            </div>
            <div class="bg-white rounded-xl shadow-md border-2 border-blue-600 text-center p-6 relative">
                <span class="absolute top-0 left-1/2 -translate-x-1/2 -translate-y-1/2 bg-blue-600 text-white text-xs font-semibold px-3 py-1 rounded-full">Популярный</span>
                <h4 class="text-lg font-semibold m-0 pb-4 border-b border-slate-200">Профессиональный</h4>
                <div class="py-6">
                    <span class="text-5xl font-extrabold text-slate-900">$29</span><span class="text-slate-500">/мес</span>
                    <ul class="list-none m-0 p-0 mt-4 mb-6 space-y-2 text-sm text-slate-600">
                        <li>25 пользователей</li>
                        <li>20 ГБ хранилища</li>
                        <li>Приоритетная поддержка</li>
                    </ul>
                    <button class="w-full py-2.5 bg-blue-600 text-white font-semibold rounded-lg hover:bg-blue-700 transition">Выбрать</button>
                </div>
            </div>
            <div class="bg-white rounded-xl shadow-sm border border-slate-200 text-center p-6">
                <h4 class="text-lg font-semibold m-0 pb-4 border-b border-slate-200">Премиум</h4>
                <div class="py-6">
                    <span class="text-5xl font-extrabold text-slate-900">$99</span><span class="text-slate-500">/мес</span>
                    <ul class="list-none m-0 p-0 mt-4 mb-6 space-y-2 text-sm text-slate-600">
                        <li>Неограниченные пользователи</li>
                        <li>100 ГБ хранилища</li>
                        <li>24/7 Премиум поддержка</li>
                    </ul>
                    <button class="w-full py-2.5 border border-blue-600 text-blue-600 font-semibold rounded-lg hover:bg-blue-50 transition">Выбрать</button>
                </div>
            </div>
        </div>
    </div>
</section>'
        ]
    ],

    'Формы' => [
        [
            'name' => 'Обратная связь',
            'icon' => 'fa fa-envelope',
            'code' => '<section class="py-12">
    <div class="max-w-3xl mx-auto px-4">
        <div class="bg-white rounded-xl shadow-md p-8">
            <h2 class="text-2xl font-bold mb-6 text-slate-900">Свяжитесь с нами</h2>
            <form>
                <div class="mb-4">
                    <label class="block text-sm font-medium text-slate-700 mb-1">Ваше имя</label>
                    <input type="text" class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500 outline-none" required>
                </div>
                <div class="mb-4">
                    <label class="block text-sm font-medium text-slate-700 mb-1">Email</label>
                    <input type="email" class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500 outline-none" required>
                </div>
                <div class="mb-4">
                    <label class="block text-sm font-medium text-slate-700 mb-1">Сообщение</label>
                    <textarea class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500 outline-none" rows="4" required></textarea>
                </div>
                <button type="submit" class="px-6 py-2.5 bg-blue-600 text-white font-semibold rounded-lg hover:bg-blue-700 transition">Отправить</button>
            </form>
        </div>
    </div>
</section>'
        ],
        [
            'name' => 'Подписка',
            'icon' => 'fa fa-newspaper',
            'code' => '<section class="bg-slate-900 text-white py-12">
    <div class="max-w-3xl mx-auto px-4 text-center">
        <h2 class="text-3xl font-bold mb-3">Подпишитесь на новости</h2>
        <p class="text-lg mb-6 text-slate-300">Получайте свежие обновления прямо на почту</p>
        <form class="flex flex-col sm:flex-row gap-3 justify-center">
            <input type="email" class="px-4 py-3 rounded-lg text-slate-900 outline-none focus:ring-2 focus:ring-blue-500 sm:max-w-xs w-full" placeholder="Ваш email">
            <button type="submit" class="px-6 py-3 bg-white text-slate-900 font-semibold rounded-lg hover:bg-slate-100 transition">Подписаться</button>
        </form>
    </div>
</section>'
        ]
    ],

    'Элементы UI' => [
        [
            'name' => 'Аккордеон FAQ',
            'icon' => 'fa fa-question-circle',
            'code' => '<section class="py-12">
    <div class="max-w-3xl mx-auto px-4">
        <h2 class="text-3xl font-bold text-center mb-10 text-slate-900">Часто задаваемые вопросы</h2>
        <div class="space-y-3" x-data="{open:1}">
            <div class="border border-slate-200 rounded-lg overflow-hidden">
                <button class="w-full text-left px-5 py-4 bg-slate-50 hover:bg-slate-100 font-semibold text-slate-800 flex justify-between items-center" @click="open = open===1?0:1">
                    Как начать пользоваться сервисом?
                    <i class="fas fa-chevron-down transition" :class="open===1?\'rotate-180\':\'\'"></i>
                </button>
                <div x-show="open===1" x-collapse class="px-5 py-4 text-slate-600">
                    Просто зарегистрируйтесь на нашем сайте, выберите подходящий тариф и начните использовать все возможности сервиса сразу после оплаты.
                </div>
            </div>
            <div class="border border-slate-200 rounded-lg overflow-hidden">
                <button class="w-full text-left px-5 py-4 bg-slate-50 hover:bg-slate-100 font-semibold text-slate-800 flex justify-between items-center" @click="open = open===2?0:2">
                    Какие способы оплаты вы принимаете?
                    <i class="fas fa-chevron-down transition" :class="open===2?\'rotate-180\':\'\'"></i>
                </button>
                <div x-show="open===2" x-collapse class="px-5 py-4 text-slate-600">
                    Мы принимаем все основные кредитные карты (Visa, MasterCard, Maestro), а также платежи через PayPal и банковские переводы.
                </div>
            </div>
            <div class="border border-slate-200 rounded-lg overflow-hidden">
                <button class="w-full text-left px-5 py-4 bg-slate-50 hover:bg-slate-100 font-semibold text-slate-800 flex justify-between items-center" @click="open = open===3?0:3">
                    Есть ли бесплатный период?
                    <i class="fas fa-chevron-down transition" :class="open===3?\'rotate-180\':\'\'"></i>
                </button>
                <div x-show="open===3" x-collapse class="px-5 py-4 text-slate-600">
                    Да, мы предлагаем 14-дневный бесплатный пробный период для всех новых пользователей без необходимости указания платежных данных.
                </div>
            </div>
        </div>
    </div>
</section>'
        ],
        [
            'name' => 'Кнопки соцсетей',
            'icon' => 'fa fa-share-alt',
            'code' => '<div class="flex flex-wrap gap-3 my-4">
    <a href="#" class="inline-flex items-center gap-2 px-4 py-2 border border-blue-500 text-blue-600 rounded-lg hover:bg-blue-50 transition"><i class="fab fa-telegram"></i> Telegram</a>
    <a href="#" class="inline-flex items-center gap-2 px-4 py-2 border border-sky-500 text-sky-600 rounded-lg hover:bg-sky-50 transition"><i class="fab fa-vk"></i> ВКонтакте</a>
    <a href="#" class="inline-flex items-center gap-2 px-4 py-2 border border-red-500 text-red-600 rounded-lg hover:bg-red-50 transition"><i class="fab fa-youtube"></i> YouTube</a>
</div>'
        ]
    ],

    'Медиа' => [
        [
            'name' => 'Загрузить фото',
            'icon' => 'fa fa-upload text-success',
            'action' => 'uploadImage'
        ],
        [
            'name' => 'Галерея',
            'icon' => 'fa fa-images',
            'code' => '<section class="py-12">
    <div class="max-w-6xl mx-auto px-4">
        <h2 class="text-3xl font-bold text-center mb-10 text-slate-900">Наша галерея</h2>
        <div class="grid grid-cols-2 md:grid-cols-3 gap-4">
            <img src="https://via.placeholder.com/400x300" class="w-full h-48 object-cover rounded-lg shadow-md" alt="Изображение 1">
            <img src="https://via.placeholder.com/400x300" class="w-full h-48 object-cover rounded-lg shadow-md" alt="Изображение 2">
            <img src="https://via.placeholder.com/400x300" class="w-full h-48 object-cover rounded-lg shadow-md" alt="Изображение 3">
            <img src="https://via.placeholder.com/400x300" class="w-full h-48 object-cover rounded-lg shadow-md" alt="Изображение 4">
            <img src="https://via.placeholder.com/400x300" class="w-full h-48 object-cover rounded-lg shadow-md" alt="Изображение 5">
            <img src="https://via.placeholder.com/400x300" class="w-full h-48 object-cover rounded-lg shadow-md" alt="Изображение 6">
        </div>
    </div>
</section>'
        ],
        [
            'name' => 'Видео YouTube',
            'icon' => 'fab fa-youtube text-danger',
            'code' => '<div class="my-8 max-w-4xl mx-auto px-4">
    <div class="relative pb-[56.25%] h-0 overflow-hidden rounded-xl shadow-lg">
        <iframe class="absolute top-0 left-0 w-full h-full" src="https://www.youtube.com/embed/VIDEO_ID" allowfullscreen></iframe>
    </div>
</div>'
        ]
    ],

    'Бизнес-элементы' => [
        [
            'name' => 'Преимущества',
            'icon' => 'fa fa-check-circle text-success',
            'code' => '<section class="py-12">
    <div class="max-w-6xl mx-auto px-4">
        <h2 class="text-3xl font-bold text-center mb-10 text-slate-900">Наши преимущества</h2>
        <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
            <div class="text-center p-6 border border-slate-200 rounded-xl shadow-sm bg-white">
                <i class="fas fa-rocket text-4xl text-blue-600 mb-4"></i>
                <h4 class="text-lg font-bold text-slate-900 mb-2">Быстрая доставка</h4>
                <p class="text-slate-600 m-0">Получите заказ в течение 24 часов</p>
            </div>
            <div class="text-center p-6 border border-slate-200 rounded-xl shadow-sm bg-white">
                <i class="fas fa-headset text-4xl text-blue-600 mb-4"></i>
                <h4 class="text-lg font-bold text-slate-900 mb-2">Поддержка 24/7</h4>
                <p class="text-slate-600 m-0">Наши специалисты всегда готовы помочь</p>
            </div>
            <div class="text-center p-6 border border-slate-200 rounded-xl shadow-sm bg-white">
                <i class="fas fa-shield-alt text-4xl text-blue-600 mb-4"></i>
                <h4 class="text-lg font-bold text-slate-900 mb-2">Гарантия качества</h4>
                <p class="text-slate-600 m-0">Все продукты проходят строгий контроль</p>
            </div>
        </div>
    </div>
</section>'
        ],
        [
            'name' => 'Отзывы клиентов',
            'icon' => 'fa fa-comment-dots',
            'code' => '<section class="py-12 bg-slate-50">
    <div class="max-w-6xl mx-auto px-4">
        <h2 class="text-3xl font-bold text-center mb-10 text-slate-900">Отзывы клиентов</h2>
        <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
            <div class="bg-white p-6 rounded-xl shadow-sm">
                <div class="flex items-center mb-4">
                    <img src="https://via.placeholder.com/60" class="w-12 h-12 rounded-full mr-3" alt="Клиент">
                    <div>
                        <h5 class="font-bold m-0 text-slate-900">Иван Иванов</h5>
                        <p class="text-slate-500 text-sm m-0">CEO, Компания XYZ</p>
                    </div>
                </div>
                <p class="text-slate-700 italic m-0">"Отличный сервис! Быстро и качественно выполнили заказ."</p>
            </div>
            <div class="bg-white p-6 rounded-xl shadow-sm">
                <div class="flex items-center mb-4">
                    <img src="https://via.placeholder.com/60" class="w-12 h-12 rounded-full mr-3" alt="Клиент">
                    <div>
                        <h5 class="font-bold m-0 text-slate-900">Мария Петрова</h5>
                        <p class="text-slate-500 text-sm m-0">Маркетолог, ABC Corp</p>
                    </div>
                </div>
                <p class="text-slate-700 italic m-0">"Сервис превзошел все наши ожидания. Обязательно будем сотрудничать снова!"</p>
            </div>
            <div class="bg-white p-6 rounded-xl shadow-sm">
                <div class="flex items-center mb-4">
                    <img src="https://via.placeholder.com/60" class="w-12 h-12 rounded-full mr-3" alt="Клиент">
                    <div>
                        <h5 class="font-bold m-0 text-slate-900">Алексей Смирнов</h5>
                        <p class="text-slate-500 text-sm m-0">Директор, StartUp Inc</p>
                    </div>
                </div>
                <p class="text-slate-700 italic m-0">"Профессиональный подход и оперативное решение всех вопросов. Рекомендую!"</p>
            </div>
        </div>
    </div>
</section>'
        ]
    ],

    'Специальные' => [
        [
            'name' => 'Таймер акции',
            'icon' => 'fa fa-clock',
            'code' => '<div class="bg-gradient-to-r from-red-600 to-pink-600 text-white p-8 text-center rounded-xl shadow-lg my-8">
    <h3 class="text-2xl font-bold mb-4">Акция заканчивается через:</h3>
    <div class="flex justify-center gap-3 mb-5">
        <div class="text-center">
            <div class="bg-slate-900 text-white text-3xl font-bold px-4 py-3 rounded-lg min-w-[60px]">03</div>
            <div class="text-xs uppercase mt-1">Дней</div>
        </div>
        <div class="text-center">
            <div class="bg-slate-900 text-white text-3xl font-bold px-4 py-3 rounded-lg min-w-[60px]">12</div>
            <div class="text-xs uppercase mt-1">Часов</div>
        </div>
        <div class="text-center">
            <div class="bg-slate-900 text-white text-3xl font-bold px-4 py-3 rounded-lg min-w-[60px]">45</div>
            <div class="text-xs uppercase mt-1">Минут</div>
        </div>
        <div class="text-center">
            <div class="bg-slate-900 text-white text-3xl font-bold px-4 py-3 rounded-lg min-w-[60px]">30</div>
            <div class="text-xs uppercase mt-1">Секунд</div>
        </div>
    </div>
    <button class="px-6 py-3 bg-white text-red-600 font-bold rounded-lg hover:bg-slate-100 transition text-lg">Успейте купить!</button>
</div>'
        ],
        [
            'name' => 'Карта',
            'icon' => 'fa fa-map-marked-alt',
            'code' => '<div class="my-8 rounded-xl overflow-hidden shadow-lg">
    <iframe src="https://yandex.ru/map-widget/v1/?ll=37.617635%2C55.755814&z=14"
            width="100%"
            height="400"
            frameborder="0"
            allowfullscreen="true"
            style="display:block">
    </iframe>
</div>'
        ]
    ]
];
