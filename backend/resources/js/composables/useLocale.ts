import { ref } from 'vue';

export type AppLocale = 'en' | 'ru';

const STORAGE_KEY = 'admin_locale';
const COOKIE_KEY = 'admin_locale';

const russianMessages: Record<string, string> = {
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
