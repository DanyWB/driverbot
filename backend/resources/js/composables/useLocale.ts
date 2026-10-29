import { ref } from 'vue';

export type AppLocale = 'en' | 'ru';

const STORAGE_KEY = 'admin_locale';
const COOKIE_KEY = 'admin_locale';

const russianMessages: Record<string, string> = {
    'Compact vehicle column': 'Узкая колонка техники',
    'Restore vehicle column width': 'Обычная ширина колонки',
    'All models': 'Все модели',
    Model: 'Модель',
    'Search by name': 'Поиск по названию',
    'Search by name or model': 'Поиск по названию или модели',
    'Timeline zoom': 'Масштаб календаря',
    'Zoom out': 'Уменьшить масштаб',
    'Zoom in': 'Увеличить масштаб',
    'Reset zoom': 'Сбросить масштаб',
    'Timeline density': 'Плотность календаря',
    'Show more days': 'Показать больше дней',
    'Show fewer days': 'Показать меньше дней',
    'Reset day width': 'Обычная ширина дня',
    ':count pixels per day': ':count пикселей на день',
    'Details for :vehicle': 'Сведения: :vehicle',
    'Vehicle details': 'Сведения о технике',
    'Edit :vehicle': 'Редактировать: :vehicle',
    'Forgot password': 'Восстановление пароля',
    'Reset password': 'Задать новый пароль',
    'Please enter your new password below':
        'Введите новый пароль администратора',
    'Enter your administrator email. The reset link will arrive in your linked Telegram, not by email.':
        'Введите email администратора. Ссылка придёт в привязанный Telegram, а не на почту.',
    'Send link to Telegram': 'Отправить ссылку в Telegram',
    'The one-time link expires in 15 minutes. Use the Telegram account previously linked in Settings. If you no longer have access to it, contact the owner. Never send your password to the bot.':
        'Одноразовая ссылка действует 15 минут. Нужен Telegram, ранее привязанный в настройках. Если доступа к нему нет, обратитесь к владельцу. Никогда не отправляйте пароль боту.',
    'Or, return to': 'Или вернуться ко',
    'log in': 'входу',
    high: 'Высокий',
    middle: 'Средний',
    low: 'Низкий',
    'Prices saved.': 'Цены сохранены.',
    Currency: 'Валюта',
    'Active for operations': 'Активен для работы',
    'Publish vehicle': 'Опубликовать байк',
    'Hidden from clients.': 'Скрыт от клиентов.',
    'Activate the vehicle before publishing.':
        'Перед публикацией активируйте байк.',
    'Select an active category before publishing.':
        'Перед публикацией выберите активную категорию.',
    'Complete all 15 prices before publishing.':
        'Перед публикацией заполните все 15 тарифов.',
    'Unsaved changes. Save to apply publication and details.':
        'Есть несохранённые изменения. Сохраните данные, чтобы применить публикацию.',
    'Operational state is separate from publication and availability on selected dates.':
        'Рабочее состояние не зависит от публикации и доступности на выбранные даты.',
    'Publication changes apply when you save details.':
        'Изменение публикации применяется после сохранения данных.',
    'All requests and blocks': 'Все заявки и блокировки',
    'Booking contacts and payments': 'Контакты и платежи бронирования',
    'These contacts apply only to this booking. The customer profile and Telegram delivery address do not change.':
        'Контакты относятся только к этой брони. Профиль клиента и получатель сообщений Telegram не изменятся.',
    Prepayment: 'Предоплата',
    'Security deposit': 'Залог',
    'Required amount': 'Сумма к внесению',
    Received: 'Получено',
    'Save booking details': 'Сохранить контакты и платежи',
    'Booking details saved.': 'Контакты и платежи сохранены.',
    'Booking details changed. Reload before saving; your draft has not been applied.':
        'Другой администратор изменил данные. Обновите страницу перед сохранением; ваш черновик не применён.',
    'Changing an amount or currency clears its previous receipt mark. Save, then mark the corrected amount as received if appropriate. No automatic payment or booking confirmation.':
        'При изменении суммы или валюты прежняя отметка получения снимается. Сохраните, затем при необходимости отметьте исправленную сумму как полученную. Оплата и подтверждение брони автоматически не выполняются.',
    'Shared preset': 'Общий пресет',
    'Individual vehicle prices': 'Индивидуальные цены байка',
    'Individual prices for this vehicle': 'Индивидуальные цены этого байка',
    'Saving will update :count linked vehicles.':
        'Сохранение изменит цены у связанных байков: :count.',
    'Package totals in THB. No automatic rounding.':
        'Итоговые цены за период в THB. Без автоматического округления.',
    'New individual prices must be multiples of 50 THB; existing exact prices are preserved.':
        'Новые индивидуальные цены кратны 50 THB; ранее введённые точные значения сохраняются.',
    'Save or close the price editor before leaving this page.':
        'Перед переходом сохраните цены или закройте редактор, отменив изменения.',
    'Unable to load prices.': 'Не удалось загрузить цены.',
    'Unable to save prices.':
        'Не удалось сохранить цены. Черновик сохранён на экране.',
    'Enter positive whole prices for all enabled tariffs.':
        'Укажите положительные целые цены для всех включённых тарифов.',
    'Only this vehicle will be changed.':
        'Будут изменены цены только этого байка.',
    'Existing booking price snapshots will not change.':
        'Стоимость уже созданных бронирований не изменится.',
    'Discard unsaved prices?': 'Отменить несохранённые цены?',
    'Your unsaved price changes will be lost.':
        'Несохранённые изменения цен будут потеряны.',
    'Continue editing': 'Продолжить редактирование',
    'Discard changes': 'Отменить изменения',
    'Unavailable on requested dates': 'Недоступен на выбранные даты',
    Archive: 'Архив',
    'Fleet sections': 'Разделы техники',
    'Move to archive': 'В архив',
    'Restore vehicle': 'Восстановить',
    'Delete permanently': 'Удалить окончательно',
    'Unpublish vehicle': 'Снять с публикации',
    'This vehicle is archived': 'Техника в архиве',
    'Enter the exact vehicle name': 'Введите точное название техники',
    'The vehicle will leave the working fleet and disappear from booking. Photos, prices and history are preserved.':
        'Техника будет убрана из рабочего списка и бронирования. Фотографии, цены и история сохранятся.',
    'The vehicle will return to the fleet but remain unpublished. Check its prices and publish it when ready.':
        'Техника вернётся в рабочий список, но останется снятой с публикации. Проверьте цены и опубликуйте её, когда будете готовы.',
    'This permanently removes the vehicle, its prices and photos. This cannot be undone.':
        'Техника, её цены и фотографии будут удалены окончательно. Это действие нельзя отменить.',
    'Customers will no longer see this vehicle for new bookings. Existing bookings remain unchanged.':
        'Клиенты больше не увидят эту технику для новых бронирований. Существующие брони останутся без изменений.',
    'Archive is unavailable while bookings are unfinished. Unpublish the vehicle and complete or cancel those bookings first.':
        'Пока есть незавершённые бронирования, архив недоступен. Снимите технику с публикации и сначала завершите или отмените эти бронирования.',
    'Rental history or occupancy records are preserved. Permanent deletion is unavailable.':
        'История аренд и записи занятости сохраняются. Окончательное удаление недоступно.',
    Saved: 'Сохранено',
    'Minimum rental duration': 'Минимальный срок аренды',
    'General minimum in days': 'Общий минимум в днях',
    'Save minimum duration': 'Сохранить минимальный срок',
    'Category values override the general minimum. Leave blank to inherit. Existing bookings do not change.':
        'Значение категории заменяет общий минимум. Оставьте поле пустым, чтобы использовать общий. Существующие бронирования не изменятся.',
    Dashboard: 'Обзор',
    Timeline: 'Календарь',
    Bookings: 'Бронирования',
    Fleet: 'Техника',
    Customers: 'Клиенты',
    Platform: 'Управление',
    Settings: 'Настройки',
    'Log out': 'Выйти',
    Language: 'Язык',
    Theme: 'Тема',
    Light: 'Светлая',
    Dark: 'Тёмная',
    System: 'Системная',
    English: 'Английский',
    Russian: 'Русский',
    Operations: 'Управление арендой',
    'New booking': 'Новая бронь',
    'Pending review': 'Ожидают проверки',
    'Pickups today': 'Выдачи сегодня',
    'Active rentals': 'Активные аренды',
    'Available today': 'Свободно сегодня',
    'Current bookings': 'Текущие бронирования',
    'Pending requests and upcoming rentals.':
        'Новые заявки и ближайшие аренды.',
    'View all': 'Смотреть все',
    'No current bookings': 'Текущих бронирований нет',
    'New requests will appear here.': 'Новые заявки появятся здесь.',
    Draft: 'Черновик',
    Pending: 'Ожидает',
    Approved: 'Подтверждена',
    'Active rental': 'Активная аренда',
    Completed: 'Завершена',
    Cancelled: 'Отменена',
    'Client cancelled': 'Отменена клиентом',
    Expired: 'Истекла',
    'No-show': 'Неявка',
    Maintenance: 'Обслуживание',
    'Telegram bot': 'Telegram-бот',
    Phone: 'Телефон',
    Manual: 'Вручную',
    Website: 'Сайт',
    Profile: 'Профиль',
    Security: 'Безопасность',
    Appearance: 'Оформление',
    'Manage your profile and account settings':
        'Управление профилем и настройками аккаунта',
    Previous: 'Назад',
    Next: 'Далее',
    Back: 'Назад',
    Close: 'Закрыть',
    Cancel: 'Отмена',
    Save: 'Сохранить',
    Apply: 'Применить',
    Reset: 'Сбросить',
    Search: 'Поиск',
    Active: 'Активна',
    Inactive: 'Неактивна',
    Published: 'Опубликована',
    Hidden: 'Скрыта',
    Edit: 'Редактировать',
    Delete: 'Удалить',
    Download: 'Скачать',
    Upload: 'Загрузить',
    Category: 'Категория',
    State: 'Состояние',
    Pricing: 'Цены',
    Updated: 'Обновлено',
    Source: 'Источник',
    Vehicle: 'Техника',
    Customer: 'Клиент',
    Contacts: 'Контакты',
    Documents: 'Документы',
    Notes: 'Заметки',
    Reason: 'Причина',
    From: 'С',
    To: 'По',
    Type: 'Тип',
    Name: 'Название',
    Description: 'Описание',
    'Description — English': 'Описание — английский',
    'Description — Russian': 'Описание — русский',
    'Description — Ukrainian': 'Описание — украинский',
    'The bot uses the customer language. Empty translations fall back to English. Translations are entered manually.':
        'Бот показывает описание на языке клиента. Если перевод не заполнен, используется английское описание. Переводы вводятся вручную.',
    Photo: 'Фото',
    Other: 'Другое',
    Passport: 'Паспорт',
    of: 'из',
    Pages: 'Страницы',
    'Booking pages': 'Страницы бронирований',
    Repository: 'Репозиторий',
    Documentation: 'Документация',
    'Appearance settings': 'Настройки оформления',
    'Update the appearance settings for your account':
        'Настройте оформление своего аккаунта',
    'Profile settings': 'Настройки профиля',
    'Update your name and email address':
        'Измените имя и адрес электронной почты',
    'Security settings': 'Настройки безопасности',
    'Update password': 'Изменить пароль',
    'Ensure your account is using a long, random password to stay secure':
        'Используйте длинный уникальный пароль для защиты аккаунта',
    'Catalog and pricing': 'Каталог и цены',
    Categories: 'Категории',
    'Add vehicle': 'Добавить технику',
    Total: 'Всего',
    'Incomplete prices': 'Неполные цены',
    'Search fleet': 'Поиск по технике',
    'Name, catalog or inventory code':
        'Название, код каталога или инвентарный код',
    'Vehicle type': 'Тип техники',
    'All types': 'Все типы',
    'All categories': 'Все категории',
    'Active state': 'Статус активности',
    'Any state': 'Любое состояние',
    Visibility: 'Видимость',
    'Any visibility': 'Любая видимость',
    'Pricing completeness': 'Заполненность цен',
    'Any pricing': 'Любые цены',
    'Complete prices': 'Цены заполнены',
    'Reset filters': 'Сбросить фильтры',
    'No vehicles found': 'Техника не найдена',
    History: 'История',
    Actions: 'Действия',
    Uncategorized: 'Без категории',
    scooter: 'Скутер',
    car: 'Автомобиль',
    'Photos count': ':count фото',
    ':count photos': ':count фото',
    'The timeline range cannot exceed 186 days.':
        'Период календаря не может превышать 186 дней.',
    'Schedule settings': 'Параметры расписания',
    'Collapse filters': 'Свернуть',
    'Expand filters': 'Развернуть',
    'Other period': 'Другой период',
    'Hide vehicle details': 'Скрыть категории и инвентарные коды',
    'Show vehicle details': 'Показать категории и инвентарные коды',
    ':count days': ':count дней',
    bookings: 'бронирований',
    'Edit vehicle': 'Редактировать технику',
    'Fleet pages': 'Страницы каталога',
    'Catalog details': 'Данные каталога',
    'Catalog code': 'Код каталога',
    'Generated when left empty':
        'Создаётся автоматически, если оставить пустым',
    'Stable technical code for imports and integrations.':
        'Постоянный технический код для импорта и интеграций.',
    'e.g. Toyota Yaris Automatic': 'например, Toyota Yaris Automatic',
    'e.g. Honda Click 160 ABS Black': 'например, Honda Click 160 ABS Black',
    Manage: 'Управлять',
    'No category': 'Без категории',
    'Groups vehicles in the bot and client catalog.':
        'Группирует технику в боте и клиентском каталоге.',
    inactive: 'неактивна',
    'Inventory code': 'Инвентарный код',
    'e.g. SC-017 or CAR-05': 'например, SC-017 или CAR-05',
    'Unique code of this physical vehicle.':
        'Уникальный код этой физической единицы техники.',
    Year: 'Год',
    'Sort order': 'Порядок сортировки',
    'Lower values appear first.': 'Меньшие значения отображаются первыми.',
    'Pricing profile': 'Профиль цен',
    'Bot label': 'Метка для бота',
    'Bot icon': 'Иконка в боте',
    'Optional emoji; selected automatically when empty.':
        'Необязательный эмодзи; если оставить пустым, выберется автоматически.',
    'Customer-facing content': 'Контент для клиентов',
    'Short customer-facing description of the vehicle and its condition.':
        'Краткое описание техники и её состояния для клиента.',
    Characteristics: 'Характеристики',
    'e.g. Automatic, 5 seats, air conditioning, fuel type':
        'например, автомат, 5 мест, кондиционер, тип топлива',
    'e.g. 160 cc, ABS, 2 seats, helmet included':
        'например, 160 куб. см, ABS, 2 места, шлем включён',
    'Availability controls': 'Доступность',
    'Available to operations.': 'Доступна для работы в админке.',
    'Visible for booking': 'Доступна для бронирования',
    'Published to client channels.': 'Показывается клиентам.',
    'Save details': 'Сохранить данные',
    'Create vehicle': 'Создать технику',
    Media: 'Медиа',
    Photos: 'Фотографии',
    'Uploaded: :count': 'Загружено: :count',
    Image: 'Изображение',
    'Alt text': 'Описание изображения',
    Primary: 'Главное',
    'Move up': 'Переместить выше',
    'Move down': 'Переместить ниже',
    'Set as primary': 'Сделать главным',
    'Open original': 'Открыть оригинал',
    'Delete photo': 'Удалить фото',
    'No photos': 'Фотографии не загружены',
    'Save photo order': 'Сохранить порядок фото',
    'Delete this photo from the vehicle?': 'Удалить это фото техники?',
    'High season': 'Высокий сезон',
    'Middle season': 'Средний сезон',
    'Low season': 'Низкий сезон',
    '1 day': '1 день',
    '7 days': '7 дней',
    '14 days': '14 дней',
    '21 days': '21 день',
    '30+ days': '30+ дней',
    'THB package totals': 'Пакетные цены в THB',
    'Seasonal pricing': 'Сезонные цены',
    'Price generator': 'Генератор цен',
    'The 1-day tariff equals the seasonal base price. Packages from 7 days are calculated by the discount formula and rounded down to 50 THB.':
        'Тариф на 1 день равен базовой цене сезона. Пакеты от 7 дней рассчитываются по формуле скидок и округляются вниз до 50 бат.',
    'Price must be a multiple of 50 THB.': 'Цена должна быть кратна 50 бат.',
    'Price must be a positive whole number.':
        'Цена должна быть положительным целым числом.',
    'Price must be a positive whole number divisible by 50 THB.':
        'Цена должна быть положительным целым числом и кратна 50 THB.',
    'Enter a price.': 'Укажите цену.',
    'Discount template': 'Шаблон скидок',
    'Light scooters / Click': 'Лёгкие скутеры / Click',
    'Comfort scooters / Aerox': 'Комфортные скутеры / Aerox',
    Cars: 'Машины',
    'THB per day': 'THB за день',
    'Fill table': 'Заполнить таблицу',
    'Select a template and enter all three base prices.':
        'Выберите шаблон и укажите все три базовые цены.',
    'Replace the current table with calculated package prices?':
        'Заменить текущую таблицу рассчитанными пакетными ценами?',
    'Price generation failed.': 'Не удалось рассчитать цены.',
    'Price generation is unavailable.': 'Генератор цен сейчас недоступен.',
    'The generator only fills the draft table. Review the amounts and save them manually.':
        'Генератор заполняет только черновик таблицы. Проверьте суммы и сохраните их вручную.',
    'active prices': 'активно',
    Season: 'Сезон',
    'Save prices': 'Сохранить цены',
    'Saved price preview': 'Проверка сохранённых цен',
    'Start date': 'Дата начала',
    'End date': 'Дата окончания',
    Calculate: 'Рассчитать',
    'Rounded total': 'Итог с округлением',
    'Days count': ':count дн.',
    'Price preview failed.': 'Не удалось рассчитать цену.',
    'Price preview is unavailable.': 'Расчёт цены временно недоступен.',
    'Calculation rules': 'Правила расчёта',
    'Interpolation mode': 'Способ интерполяции',
    'Linear between packages': 'Линейно между пакетами',
    'Delayed discount': 'Отложенная скидка',
    'Rounding increment, THB': 'Шаг округления, THB',
    'Days before discount starts': 'Дней до начала скидки',
    'Cap at next package': 'Ограничивать следующим пакетом',
    'Check average daily rate': 'Проверять среднюю цену за сутки',
    'Apply monthly rate early': 'Применять месячную цену раньше',
    'Monthly rate start day': 'Месячная цена начиная с дня',
    'Package totals remain exact. Intermediate days use interpolation and the selected rounding increment. A full calendar month uses the monthly rate.':
        'Пакетные суммы сохраняются точно. Между пакетами применяется интерполяция с выбранным шагом округления. Полный календарный месяц оплачивается по месячному тарифу.',
    'Seasonal discounts and package rounding':
        'Сезонные скидки и округление пакетов',
    'Calculate middle season from high season':
        'Считать средний сезон от высокого',
    'Calculate low season from high season': 'Считать низкий сезон от высокого',
    'Rounding, THB': 'Округление, THB',
    'Discount, %': 'Скидка, %',
    'Season calendar': 'Календарь сезонов',
    'The season with most rental days applies to the whole rental. Ties use the cheaper monthly package. The return day is excluded.':
        'На всю аренду действует сезон с наибольшим числом дней. При равенстве выбирается меньшая месячная цена. День возврата не учитывается.',
    'Daily price overrides': 'Ручные цены по дням',
    'Reset season overrides': 'Сбросить ручные цены сезона',
    'Leave an override empty to use the formula. Overrides for days 30 and 31 also set calendar-month and preliminary-month rates respectively.':
        'Пустое поле означает расчёт по формуле. Ручные цены за 30 и 31 день также задают тариф календарного месяца и предварительного месячного блока соответственно.',
    'Long-period overrides are retained in this preset and its JSON export.':
        'Ручные цены для длинных периодов сохраняются в пресете и JSON-экспорте.',
    'Could not complete pricing operation.':
        'Не удалось выполнить операцию с ценами.',
    'Save :count edited presets and update their linked vehicles for future quotes?':
        'Сохранить изменённые пресеты (:count) и обновить связанные машины для будущих расчётов?',
    'Pricing presets saved.': 'Пресеты цен сохранены.',
    'Assign :preset to :count vehicles for future quotes?':
        'Назначить пресет «:preset» выбранным машинам (:count) для будущих расчётов?',
    'Fleet assignments saved.': 'Назначения пресетов сохранены.',
    'Settings file must not exceed 2 MB.':
        'Файл настроек не должен превышать 2 МБ.',
    'Import :count presets with the mappings below? Existing bookings will not change.':
        'Импортировать пресеты (:count) с указанными сопоставлениями? Существующие брони не изменятся.',
    'Import complete. Assigned: :assigned. Skipped: :skipped.':
        'Импорт завершён. Назначено: :assigned. Пропущено: :skipped.',
    'Your draft is retained. Refresh the page or preview after a conflict before trying again.':
        'Черновик сохранён. При конфликте обновите страницу или предпросмотр перед повторной попыткой.',
    'Bike group price presets': 'Пресеты цен по моделям',
    'Edit prices directly in the table': 'Редактировать цены в таблице',
    'Discard table edits': 'Отменить правки таблицы',
    'Export settings to JSON': 'Экспорт настроек в JSON',
    'Import settings from JSON': 'Импорт настроек из JSON',
    'Select a model to see its daily matrix and rental quote. Hover a package price to see its daily rate.':
        'Выберите модель для матрицы по дням и расчёта аренды. Наведите курсор на пакетную цену, чтобы увидеть цену за сутки.',
    'Bike model': 'Модель техники',
    'Calculator rules': 'Правила калькулятора',
    'Legacy calculation': 'Прежний расчёт',
    'Import preview and explicit mapping':
        'Предпросмотр импорта и сопоставление',
    'No changes have been saved. Source IDs are labels from the file. Choose real vehicles explicitly or leave them skipped. No vehicles are created.':
        'Изменения ещё не сохранены. Исходные ID взяты из файла. Явно выберите реальные машины или оставьте позиции пропущенными. Новые машины не создаются.',
    'Create new preset': 'Создать новый пресет',
    'linked vehicles': 'связанных машин',
    'Skip this vehicle': 'Пропустить эту машину',
    'Preview expires in 20 minutes. Existing preset targets keep their vehicle-type restriction and update all linked vehicles. Review every target before confirming.':
        'Предпросмотр действует 20 минут. При обновлении существующего пресета сохраняется ограничение типа техники и обновляются все связанные машины. Проверьте каждое сопоставление.',
    'Confirm import': 'Подтвердить импорт',
    'Daily prices and rental period calculator': 'Цены по дням и расчёт аренды',
    'Price preset': 'Пресет цен',
    'This preset uses legacy calculation. Enable calculator rules in Edit to use the matrix and preview. Existing vehicles are not switched automatically.':
        'Этот пресет использует прежний расчёт. Включите правила калькулятора в редакторе для матрицы и предпросмотра. Автоматического переключения машин нет.',
    'Daily prices — all seasons': 'Цены по дням: все сезоны',
    'Season / metric': 'Сезон / показатель',
    'Per day': 'За сутки',
    'Download CSV': 'Скачать CSV',
    'Calculation mode': 'Режим расчёта',
    'Calendar dates': 'Календарные даты',
    'Preliminary: days only': 'Предварительно: только дни',
    'Number of days': 'Количество дней',
    'Full calendar months plus remaining days. The return day is excluded.':
        'Полные календарные месяцы и остаток дней. День возврата не учитывается.',
    'Preliminary only: uses 31-day blocks. Enter calendar dates for an actual booking; the total can differ.':
        'Предварительная оценка с блоками по 31 дню. Для реальной брони укажите календарные даты: сумма может отличаться.',
    Average: 'В среднем',
    'calendar months / blocks': 'календарных месяцев / блоков',
    'Manual total override': 'Ручная сумма за весь период',
    'Tie: cheaper monthly package selected':
        'Равенство дней: выбрана меньшая месячная цена',
    'Current fleet and preset assignments':
        'Текущий автопарк и назначения пресетов',
    'Real fleet records only. Legacy vehicles keep their existing calculation until an explicit calculator preset assignment. Existing bookings are not recalculated.':
        'Только реальные записи автопарка. Машины с прежним расчётом сохраняют его до явного назначения нового пресета. Существующие брони не пересчитываются.',
    'Choose preset for selected vehicles': 'Пресет для выбранных машин',
    'Assign to selected vehicles': 'Назначить выбранным машинам',
    'Model / description': 'Модель / описание',
    'Select a preset': 'Выберите пресет',
    'No preset assigned': 'Пресет не назначен',
    'Fleet price sheet': 'Прайс-лист автопарка',
    'Package totals from the real fleet. Hover a price for its daily rate. Inactive or missing prices are shown as a dash.':
        'Пакетные суммы реального автопарка. Наведите курсор для цены за сутки. Неактивные и отсутствующие цены показаны прочерком.',
    'Confirm pricing changes': 'Подтвердите изменения цен',
    'Existing bookings are not recalculated. Future quotes use the saved calculation rules.':
        'Существующие брони не пересчитываются. Будущие расчёты используют сохранённые правила.',
    'Manage package prices, calculation rules and assignments to your real fleet. Save a preset before checking its daily matrix or rental quote.':
        'Настройте пакетные цены, правила расчёта и назначения реальным машинам. Сохраните пресет перед проверкой матрицы и расчётом аренды.',
    'Calculator rules are enabled explicitly': 'Новые правила включаются явно',
    'New presets use interpolation and their own rounding rules. Existing vehicles keep the legacy calculation until you explicitly assign or update a calculator preset.':
        'Новые пресеты используют интерполяцию и собственное округление. Текущие машины сохраняют прежний расчёт до явного назначения или обновления пресета с новыми правилами.',
    'Use calculator rules for future quotes':
        'Использовать правила калькулятора для будущих расчётов',
    'Legacy mode: seasonal day splitting and final rounding to 100 THB. No change to existing bookings.':
        'Прежний режим: разбивка дней по сезонам и итоговое округление до 100 THB. Существующие брони не меняются.',
    'Long-period override day': 'День для ручной цены',
    'Long-period override, THB': 'Ручная цена за период, THB',
    day: 'сутки',
    days: 'дней',
    Auto: 'Авто',
    'Save preset order?': 'Сохранить порядок пресетов?',
    'Delete unused preset :name?': 'Удалить неиспользуемый пресет «:name»?',
    'Preset details': 'Карточки пресетов',
    'Select date': 'Выберите дату',
    'Select return date': 'Выберите дату возврата',
    'Select pickup date': 'Выберите дату выдачи',
    'Previous month': 'Предыдущий месяц',
    'Next month': 'Следующий месяц',
    'The return day is excluded.': 'День возврата не учитывается.',
    'Clear dates': 'Очистить даты',
    'Saved calculator rules apply to this preview. Vehicles in legacy mode keep final rounding to 100 THB.':
        'Предпросмотр использует сохранённые правила калькулятора. Для машин с прежним расчётом сохраняется итоговое округление до 100 THB.',
    'Enter exact positive whole THB package totals. Intermediate days and linked seasonal prices follow the saved rounding rules.':
        'Укажите точные положительные пакетные суммы в целых THB. Промежуточные дни и связанные сезонные цены рассчитываются с сохранённым округлением.',
    Month: 'Месяц',
    'This preset changed. Refresh the page before calculating.':
        'Пресет изменился. Обновите страницу перед расчётом.',
    'Monthly package applies from day :day, except exact base-package anchors. Calendar months are calculated separately.':
        'Месячный пакет применяется с :day-го дня, кроме точных опорных пакетов. Календарные месяцы рассчитываются отдельно.',
    'Pricing presets': 'Шаблоны цен',
    'Create preset': 'Создать шаблон',
    'Create pricing preset': 'Создание шаблона цен',
    'Edit pricing preset': 'Редактирование шаблона цен',
    'Copy pricing preset': 'Копирование шаблона цен',
    'Create a separate preset with the same exact prices.':
        'Создайте отдельный шаблон с теми же точными ценами.',
    'Enter exact package totals for all seasons and rental periods.':
        'Укажите точные пакетные суммы для всех сезонов и сроков аренды.',
    'Reuse one exact 3 × 5 price matrix across multiple vehicles. Preset totals are stored as whole THB without rounding or a 50 THB step.':
        'Используйте одну точную матрицу цен 3 × 5 для нескольких единиц техники. Суммы шаблона хранятся в целых батах без округления и шага 50 бат.',
    'Booking rounding is unchanged': 'Округление бронирования не изменилось',
    'Preset package totals stay exact. The final booking quote still follows the existing 100 THB rounding rule.':
        'Пакетные суммы шаблона остаются точными. Итог бронирования по-прежнему округляется по действующему правилу до 100 бат.',
    'Changing a preset updates linked vehicles for future quotes. Existing bookings are never recalculated.':
        'Изменение шаблона обновляет связанную технику для будущих расчётов. Существующие бронирования никогда не пересчитываются.',
    'Version :version': 'Версия :version',
    ':count linked vehicles': 'Связано техники: :count',
    Copy: 'Копировать',
    'Linked vehicles': 'Связанная техника',
    'No pricing presets yet': 'Шаблонов цен пока нет',
    'Create a preset manually, then assign it from a vehicle price section.':
        'Создайте шаблон вручную, затем назначьте его в разделе цен техники.',
    ':name copy': ':name — копия',
    'Enter a preset name.': 'Введите название шаблона.',
    'Enter a positive whole THB amount.':
        'Введите положительную целую сумму в батах.',
    'Price must not exceed 100,000,000 THB.':
        'Цена не должна превышать 100 000 000 бат.',
    'The pricing preset could not be saved.':
        'Не удалось сохранить шаблон цен.',
    'This preset changed after you opened it. Your edits are still here. Refresh the preset state, review, and try again.':
        'Шаблон изменился после открытия. Ваши правки сохранены в форме. Обновите состояние шаблона, проверьте данные и повторите попытку.',
    'The pricing preset service is unavailable. Try again.':
        'Сервис шаблонов цен недоступен. Повторите попытку.',
    'Pricing preset saved.': 'Шаблон цен сохранён.',
    'Preset state refreshed. Your edited fields were not replaced.':
        'Состояние шаблона обновлено. Ваши правки в форме не заменены.',
    'Latest preset state is ready. Your edited fields are still here. Load the latest version only if you want to discard this draft.':
        'Актуальное состояние шаблона загружено. Ваши правки всё ещё в форме. Загрузите последнюю версию, только если хотите удалить этот черновик.',
    'Load latest and discard my draft':
        'Загрузить последнюю версию и удалить мой черновик',
    'Could not refresh the preset state.':
        'Не удалось обновить состояние шаблона.',
    'Preset name': 'Название шаблона',
    'Enter exact positive whole THB totals. Presets do not round and do not require multiples of 50.':
        'Укажите точные положительные целые суммы в батах. В шаблонах нет округления и требования кратности 50.',
    'Refresh preset state': 'Обновить состояние шаблона',
    'Create copy': 'Создать копию',
    'Save preset': 'Сохранить шаблон',
    'Update linked vehicles?': 'Обновить связанную технику?',
    'This preset is linked to :count vehicles. Saving will replace their current matrices for future quotes.':
        'Этот шаблон связан с :count ед. техники. Сохранение заменит их текущие матрицы для будущих расчётов.',
    'Existing bookings are not recalculated. Final booking rounding remains unchanged.':
        'Существующие бронирования не пересчитываются. Округление итоговой суммы бронирования не меняется.',
    Review: 'Вернуться к проверке',
    'Update :count vehicles': 'Обновить технику: :count',
    'Shared pricing preset': 'Общий шаблон цен',
    'Assigning a preset replaces this vehicle price matrix for future quotes. Existing bookings are not recalculated.':
        'Назначение шаблона заменяет матрицу цен этой техники для будущих расчётов. Существующие бронирования не пересчитываются.',
    'Pricing preset': 'Шаблон цен',
    'Select a pricing preset': 'Выберите шаблон цен',
    'Assign preset': 'Назначить шаблон',
    'Replace preset': 'Заменить шаблон',
    'Create a compatible preset': 'Создать подходящий шаблон',
    'Edit preset': 'Редактировать шаблон',
    'Detach to individual pricing': 'Перейти на индивидуальные цены',
    'Linked preset matrix': 'Матрица связанного шаблона',
    'This matrix is read-only here. Edit the shared preset to update all linked vehicles.':
        'Здесь матрица доступна только для чтения. Измените общий шаблон, чтобы обновить всю связанную технику.',
    'The final booking quote still follows the existing 100 THB rounding rule.':
        'Итог бронирования по-прежнему округляется по действующему правилу до 100 бат.',
    'Detach shared preset?': 'Отвязать общий шаблон?',
    'The current exact matrix will be preserved on this vehicle and become individually editable.':
        'Текущая точная матрица сохранится у этой техники и станет доступна для индивидуального редактирования.',
    'Future preset changes will no longer affect this vehicle. Existing bookings are not recalculated.':
        'Будущие изменения шаблона больше не затронут эту технику. Существующие бронирования не пересчитываются.',
    'Detach and keep current prices': 'Отвязать и сохранить текущие цены',
    'Apply shared pricing preset?': 'Применить общий шаблон цен?',
    'Apply :preset to :vehicle?': 'Применить «:preset» к «:vehicle»?',
    'The selected preset will replace the current vehicle matrix for future quotes. Existing bookings are not recalculated.':
        'Выбранный шаблон заменит текущую матрицу техники для будущих расчётов. Существующие бронирования не пересчитываются.',
    'Unsaved individual price changes will be discarded when the preset is applied.':
        'Несохранённые изменения индивидуальных цен будут удалены при применении шаблона.',
    'Apply preset': 'Применить шаблон',
    'Exact prices preserved from a detached preset may stay unchanged. Any individual price you change must be a multiple of 50 THB.':
        'Точные цены, сохранённые после отвязки шаблона, можно оставить без изменений. Любая изменённая индивидуальная цена должна быть кратна 50 бат.',
    '1d': '1 день',
    '7d': '7 дней',
    '14d': '14 дней',
    '21d': '21 день',
    month: '30+ дней',
    Days: 'Дни',
    'Daily rate': 'Цена за день',
    Subtotal: 'Подытог',
    'high season': 'высокий сезон',
    'middle season': 'средний сезон',
    'low season': 'низкий сезон',
    'New vehicle': 'Новая техника',
    'Back to fleet': 'Назад к каталогу',
    'Fleet catalog': 'Каталог техники',
    Details: 'Данные',
    Prices: 'Цены',
    'Vehicle sections': 'Разделы техники',
    'Add category': 'Добавить категорию',
    'No categories': 'Категорий пока нет',
    Vehicles: 'Техника',
    Any: 'Любой',
    published: 'опубликовано',
    vehicles: 'ед. техники',
    'Edit category': 'Редактировать категорию',
    'New category': 'Новая категория',
    'Assigned vehicles: :count': 'Назначено техники: :count',
    'Catalog grouping for vehicles': 'Группа техники в каталоге',
    Code: 'Код',
    'Any type': 'Любой тип',
    'Save category': 'Сохранить категорию',
    'Create category': 'Создать категорию',
    'Contacts and rental history': 'Контакты и история аренды',
    'With bookings': 'С бронированиями',
    'With documents': 'С документами',
    'No contact': 'Нет контактов',
    'Search customers': 'Поиск клиентов',
    'Name, phone, Telegram or email': 'Имя, телефон, Telegram или email',
    'Any documents': 'Любые документы',
    'Has documents': 'Есть документы',
    'No documents': 'Нет документов',
    'Sort customers': 'Сортировка клиентов',
    'Recently updated': 'Недавно обновлённые',
    'Recently added': 'Недавно добавленные',
    'No customers found': 'Клиенты не найдены',
    'Latest booking': 'Последняя бронь',
    'More contacts: :count': 'Ещё контактов: :count',
    'Bookings count': 'Броней: :count',
    'Customer pages': 'Страницы клиентов',
    'Delete this private document?': 'Удалить этот приватный документ?',
    'Unknown size': 'Размер неизвестен',
    'Back to customers': 'Назад к клиентам',
    primary: 'основной',
    'No contacts.': 'Контактов нет.',
    'Passport number': 'Номер паспорта',
    'Channel identities': 'Аккаунты в каналах',
    'Internal note': 'Внутренняя заметка',
    'Private documents': 'Приватные документы',
    'Driver license': 'Водительское удостоверение',
    File: 'Файл',
    'Upload document': 'Загрузить документ',
    Booking: 'Бронирование',
    'Download document': 'Скачать документ',
    'Delete document': 'Удалить документ',
    'No documents.': 'Документов нет.',
    'Booking history': 'История бронирований',
    'Total count': 'Всего: :count',
    'No bookings.': 'Бронирований нет.',
    'Rental dates': 'Даты аренды',
    'Final price': 'Итоговая цена',
    'Customer booking pages': 'Страницы бронирований клиента',
    'Availability timeline': 'Календарь доступности',
    'Fleet availability': 'Доступность техники',
    'Previous period': 'Предыдущий период',
    Today: 'Сегодня',
    'Next period': 'Следующий период',
    'All vehicles': 'Вся техника',
    'All active': 'Все активные',
    'Client-visible': 'Видимые клиентам',
    'Internal only': 'Только внутренние',
    'All records': 'Все записи',
    Occupancy: 'Занятость',
    'All blocking': 'Все блокирующие статусы',
    'Available only': 'Только свободные',
    Filter: 'Фильтр',
    Available: 'Свободно',
    'Vehicle availability': 'Доступность техники',
    'No vehicles match these filters': 'Нет техники, соответствующей фильтрам',
    'The timeline could not be loaded.': 'Не удалось загрузить календарь.',
    'Select both dates.': 'Выберите обе даты.',
    'End date must be on or after the start date.':
        'Дата окончания должна быть не раньше даты начала.',
    'The timeline range cannot exceed 93 days.':
        'Период календаря не может превышать 93 дня.',
    'Vehicles count': 'Техники: :count',
    'Fully free count': 'Полностью свободно: :count',
    'Blocks count': 'Блокировок: :count',
    'Expand calendar': 'Развернуть календарь',
    'Collapse calendar': 'Свернуть календарь',
    'Calendar range': 'Период календаря',
    'Show :count days from today': 'Показать :count дней от сегодня',
    'Rental operations': 'Управление арендой',
    'Export CSV': 'Экспорт CSV',
    active: 'активные',
    all: 'все',
    archive: 'архив',
    'Results count': 'Найдено: :count',
    'Search bookings': 'Поиск бронирований',
    'ID, customer, phone or vehicle': 'ID, клиент, телефон или техника',
    Status: 'Статус',
    'All statuses': 'Все статусы',
    'All sources': 'Все источники',
    'All vehicle types': 'Все типы техники',
    'Rows per page': 'Строк на странице',
    'Rows count': ':count строк',
    'No bookings found': 'Бронирования не найдены',
    'Change the filters or create a manual booking.':
        'Измените фильтры или создайте бронь вручную.',
    Records: 'Записи',
    'Booking could not be updated.': 'Не удалось обновить бронирование.',
    'No phone': 'Нет телефона',
    Adjusted: 'Скорректировано',
    Calculated: 'Расчётная цена',
    Client: 'Клиент',
    Internal: 'Внутренняя',
    Payment: 'Оплата',
    Created: 'Создано',
    yes: 'да',
    no: 'нет',
    'Open booking': 'Открыть бронирование',
    Open: 'Открыть',
    'Approve booking': 'Подтвердить бронирование',
    Approve: 'Подтвердить',
    'Cancel booking': 'Отменить бронирование',
    'Keep booking': 'Не отменять',
    'Back to timeline': 'Назад к календарю',
    'Back to bookings': 'Назад к бронированиям',
    'Manual reservation': 'Ручное бронирование',
    'Quick booking': 'Быстрое бронирование',
    'Start selected: :vehicle, :date': 'Начало выбрано: :vehicle, :date',
    'Choose the rental end date in the same row.':
        'Выберите дату завершения аренды в той же строке.',
    'Cancel selection': 'Отменить выбор',
    'The selected range includes occupied dates.':
        'В выбранный период входят занятые даты.',
    'Select :date as rental start for :vehicle':
        'Выбрать :date как начало аренды для :vehicle',
    'Select :date as rental end for :vehicle':
        'Выбрать :date как завершение аренды для :vehicle',
    'Inactive vehicles cannot be booked.':
        'Неактивную технику нельзя забронировать.',
    'new customer': 'Новый клиент',
    'existing customer': 'Существующий клиент',
    'Change customer': 'Сменить клиента',
    'Name, phone or Telegram': 'Имя, телефон или Telegram',
    'Searching...': 'Поиск...',
    Retry: 'Повторить',
    'No customers found.': 'Клиенты не найдены.',
    'Full name': 'Полное имя',
    'Telegram username': 'Имя пользователя Telegram',
    Rental: 'Аренда',
    'Select vehicle': 'Выберите технику',
    internal: 'внутренняя',
    'incomplete price': 'цены не заполнены',
    'Pickup time': 'Время выдачи',
    'Return time': 'Время возврата',
    'Initial status': 'Начальный статус',
    'Customer comment': 'Комментарий клиента',
    'Payment / deposit note': 'Заметка об оплате / депозите',
    'Notes and manual price': 'Заметки и ручная цена',
    'Price preview': 'Предварительный расчёт',
    Calculating: 'Расчёт...',
    'Automatic total': 'Автоматический итог',
    'Available for these dates': 'Свободна на эти даты',
    'Dates are already occupied': 'Даты уже заняты',
    'Select a vehicle and rental dates.': 'Выберите технику и даты аренды.',
    'Set final price manually': 'Установить итоговую цену вручную',
    'Final total, THB': 'Итоговая сумма, THB',
    'Saving…': 'Сохранение…',
    'Create booking': 'Создать бронирование',
    'Price could not be calculated.': 'Не удалось рассчитать цену.',
    'Price preview is temporarily unavailable.':
        'Предварительный расчёт временно недоступен.',
    'Customer search is unavailable.': 'Поиск клиентов временно недоступен.',
    'Start rental': 'Начать аренду',
    Complete: 'Завершить',
    Dates: 'Даты',
    Recalculate: 'Пересчитать',
    Price: 'Цена',
    'Rental details': 'Данные аренды',
    'Rental period': 'Период аренды',
    'Calendar days count': ':count календарных дн.',
    Time: 'Время',
    'Not set': 'Не указано',
    Helmets: 'Шлемы',
    Delivery: 'Доставка',
    Required: 'Нужна',
    Pickup: 'Самовывоз',
    'Rental terms': 'Условия аренды',
    'Not recorded': 'Не зафиксированы',
    Accepted: 'Приняты',
    by: 'создал',
    'Pending expires': 'Срок ожидания',
    Version: 'Версия',
    'Final total': 'Итоговая сумма',
    'Rate tier': 'Тариф',
    'Manual adjustment': 'Ручная корректировка',
    'Adjustment reason': 'Причина корректировки',
    'Previous price versions': 'Предыдущие версии цены',
    'No price snapshot.': 'Расчёт цены отсутствует.',
    'Status history': 'История статусов',
    'Edit internal note': 'Редактировать внутреннюю заметку',
    'Payment / deposit': 'Оплата / депозит',
    'Closure reason': 'Причина закрытия',
    'No notes.': 'Заметок нет.',
    'Document type': 'Тип документа',
    'Change rental dates': 'Изменить даты аренды',
    'Availability and price will be checked again before saving.':
        'Перед сохранением доступность и цена будут проверены повторно.',
    'Calculating…': 'Расчёт…',
    'Vehicle available': 'Техника свободна',
    'Dates occupied': 'Даты заняты',
    'Save dates': 'Сохранить даты',
    'Set final price': 'Установить итоговую цену',
    'The automatic calculation remains in history.':
        'Автоматический расчёт останется в истории.',
    'Save price': 'Сохранить цену',
    'Recalculate price': 'Пересчитать цену',
    'A new automatic price version will be created from the current dates and tariffs. Any manual final price will remain in history but will no longer be current.':
        'По текущим датам и тарифам будет создана новая версия автоматической цены. Ручная цена останется в истории, но перестанет быть текущей.',
    'Keep current price': 'Оставить текущую цену',
    'This note is visible to administrators only.':
        'Эта заметка видна только администраторам.',
    Note: 'Заметка',
    'Save note': 'Сохранить заметку',
    'The vehicle will be released immediately. This action is recorded in audit history.':
        'Техника будет освобождена сразу. Действие сохранится в истории аудита.',
    'Mark as no-show': 'Отметить неявку',
    'The booking must be approved and its pickup time must have passed.':
        'Бронирование должно быть подтверждено, а время выдачи уже пройти.',
    'Reason (optional)': 'Причина (необязательно)',
    'Confirm no-show': 'Подтвердить неявку',
    'Email address': 'Адрес электронной почты',
    'Your email address is unverified.':
        'Ваш адрес электронной почты не подтверждён.',
    'Click here to re-send the verification email.':
        'Нажмите здесь, чтобы отправить письмо повторно.',
    'A new verification link has been sent to your email address.':
        'Новая ссылка для подтверждения отправлена на вашу почту.',
    'Current password': 'Текущий пароль',
    'New password': 'Новый пароль',
    'Confirm password': 'Подтвердите пароль',
    'Telegram notifications': 'Уведомления Telegram',
    'Connect your personal Telegram account to receive administrator alerts':
        'Подключите личный аккаунт Telegram для получения уведомлений администратора',
    'Legacy recipient is active': 'Активен прежний получатель',
    'The previous server recipient still receives alerts. It will be replaced as soon as the first administrator connects Telegram here.':
        'Прежний получатель с сервера пока получает уведомления. Он будет заменён, как только первый администратор подключит здесь Telegram.',
    'Your Telegram account': 'Ваш аккаунт Telegram',
    'Telegram account': 'Аккаунт Telegram',
    Connected: 'Подключён',
    'Not connected': 'Не подключён',
    'Each administrator connects a separate Telegram account. Alerts are sent independently to every active recipient.':
        'Каждый администратор подключает отдельный аккаунт Telegram. Уведомления отправляются независимо каждому активному получателю.',
    'Active recipients: :count': 'Активных получателей: :count',
    'Connected account': 'Подключённый аккаунт',
    Connection: 'Подключение',
    'Last successful test': 'Последняя успешная проверка',
    'Not yet': 'Ещё не выполнялась',
    'Send test message': 'Отправить тестовое сообщение',
    'Sending…': 'Отправка…',
    'Replace Telegram account': 'Заменить аккаунт Telegram',
    'Disconnect Telegram': 'Отключить Telegram',
    'Disconnect Telegram?': 'Отключить Telegram?',
    'This administrator will stop receiving alerts. Other connected administrators will not be affected.':
        'Этот администратор перестанет получать уведомления. Подключения других администраторов не изменятся.',
    Disconnect: 'Отключить',
    'Finish connecting in Telegram': 'Завершите подключение в Telegram',
    'Open the bot and send this one-time command. Do not share it with anyone.':
        'Откройте бота и отправьте эту одноразовую команду. Никому её не передавайте.',
    'Your current account remains connected until the new command is accepted.':
        'Текущий аккаунт останется подключённым, пока новая команда не будет принята.',
    Copied: 'Скопировано',
    'Copy command': 'Скопировать команду',
    'Copy failed. Select the command and copy it manually.':
        'Не удалось скопировать. Выделите команду и скопируйте её вручную.',
    'Code expires in :time': 'Код истечёт через :time',
    'Open Telegram bot': 'Открыть Telegram-бота',
    'Bot username is not configured': 'Имя Telegram-бота не настроено',
    'You can still copy the command, but the direct bot link is unavailable.':
        'Команду по-прежнему можно скопировать, но прямая ссылка на бота недоступна.',
    'Generating…': 'Создание…',
    'Generate a new code': 'Создать новый код',
    'Connect Telegram': 'Подключить Telegram',
    'How multiple administrators work':
        'Как работают несколько администраторов',
    'Every administrator signs in to the web panel and connects their own Telegram account here. Disconnecting one account never disables notifications for the others.':
        'Каждый администратор входит в веб-панель и подключает здесь свой аккаунт Telegram. Отключение одного аккаунта не выключает уведомления у остальных.',
    'Telegram binding code created.': 'Код подключения Telegram создан.',
    'Telegram test message sent.': 'Тестовое сообщение отправлено в Telegram.',
    'Telegram account disconnected.': 'Аккаунт Telegram отключён.',
    'Connect a Telegram account before sending a test message.':
        'Подключите аккаунт Telegram перед отправкой тестового сообщения.',
    'The test message could not be delivered. Check the bot connection and try again.':
        'Не удалось доставить тестовое сообщение. Проверьте подключение бота и повторите попытку.',
};

function isLocale(value: string | null): value is AppLocale {
    return value === 'en' || value === 'ru';
}

function browserLocale(): AppLocale {
    if (typeof navigator === 'undefined') {
        return 'en';
    }

    return navigator.language.toLowerCase().startsWith('ru') ? 'ru' : 'en';
}

function storedLocale(): AppLocale {
    if (typeof window === 'undefined') {
        return 'en';
    }

    const stored = localStorage.getItem(STORAGE_KEY);

    return isLocale(stored) ? stored : browserLocale();
}

const locale = ref<AppLocale>('en');

function applyDocumentLocale(value: AppLocale): void {
    if (typeof document === 'undefined') {
        return;
    }

    document.documentElement.lang = value;
}

export function initializeLocale(): void {
    locale.value = storedLocale();
    applyDocumentLocale(locale.value);
}

export function getActiveLocale(): AppLocale {
    return locale.value;
}

export function useLocale() {
    function updateLocale(value: AppLocale): void {
        locale.value = value;
        localStorage.setItem(STORAGE_KEY, value);
        document.cookie = `${COOKIE_KEY}=${value};path=/;max-age=31536000;SameSite=Lax`;
        applyDocumentLocale(value);
    }

    function t(
        message: string,
        replacements: Record<string, string | number> = {},
    ): string {
        const minimumDuration =
            /^Minimum rental duration is (\d+) days\.$/.exec(message);

        if (locale.value === 'ru' && minimumDuration) {
            return `Минимальный срок аренды: ${minimumDuration[1]} дн.`;
        }

        const translated =
            locale.value === 'ru'
                ? (russianMessages[message] ?? message)
                : message;

        return Object.entries(replacements).reduce(
            (result, [key, value]) =>
                result.replaceAll(`:${key}`, String(value)),
            translated,
        );
    }

    return {
        locale,
        t,
        updateLocale,
    };
}
