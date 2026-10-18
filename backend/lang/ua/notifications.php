<?php

return [
    'customer' => [
        'pending' => "<b>Заявку :booking отримано</b>\nТранспорт: :vehicle\nПеріод: :period\nДнів оренди: :days\nСума: :price\nОпрацюємо заявку протягом 48 годин, але не пізніше початку оренди. До підтвердження транспорт залишається доступним іншим клієнтам.",
        'approved' => "<b>Бронювання :booking підтверджено</b>\nТранспорт: :vehicle\nПеріод: :period\nДнів оренди: :days\nСума: :price",
        'cancelled' => "<b>Бронювання :booking скасовано</b>\nТранспорт: :vehicle\nПеріод: :period\nДнів оренди: :days\nДля уточнення деталей зв'яжіться з менеджером.",
        'cancelled_with_reason' => "<b>Бронювання :booking скасовано</b>\nТранспорт: :vehicle\nПеріод: :period\nДнів оренди: :days\nПричина: :reason\nДля уточнення деталей зв'яжіться з менеджером.",
        'expired' => "<b>Термін заявки :booking минув</b>\nЗаявку не підтверджено вчасно. Будь ласка, оберіть інші дати або транспорт чи зверніться до менеджера.",
        'cancelled_unavailable' => "<b>Заявку :booking закрито</b>\nВибачте, скутер уже недоступний на ваші дати. Будь ласка, оберіть інші дати або транспорт.",
        'dates_changed' => "<b>Дати бронювання :booking змінено</b>\nБуло: :old_period\nСтало: :new_period\nАктуальна сума: :price",
        'price_changed' => "<b>Суму бронювання :booking змінено</b>\nТранспорт: :vehicle\nПеріод: :period\nДнів оренди: :days\nНова сума: :price",
        'reminder_pickup_day' => "<b>Сьогодні починається оренда :booking</b>\nТранспорт: :vehicle\nПеріод: :period\nДнів оренди: :days\nСума: :price",
        'reminder_pickup_one_hour' => "<b>До початку оренди :booking залишилася одна година</b>\nТранспорт: :vehicle\nЧас видачі: :period",
    ],
    'admin' => [
        'pending_reminder' => "<b>Заявка :booking очікує рішення</b>\nКлієнт: :customer\nТранспорт: :vehicle\nПеріод: :period\nДнів оренди: :days\nСума: :price",
        'pending' => "<b>Нова заявка :booking</b>\nКлієнт: :customer\nТелефон: :phone\nTelegram: :telegram\nТранспорт: :vehicle\nПеріод: :period\nДнів оренди: :days\nСума: :price\nШоломи: :helmets\nДоставка: :delivery\nАдреса: :address\nКоментар: :comment",
        'cancelled_by_client' => "<b>Клієнт скасував бронювання :booking</b>\nКлієнт: :customer\nТелефон: :phone\nTelegram: :telegram\nТранспорт: :vehicle\nПеріод: :period",
        'expired' => "<b>Термін заявки :booking минув</b>\nКлієнт: :customer\nТелефон: :phone\nTelegram: :telegram\nТранспорт: :vehicle\nПеріод: :period\nДнів оренди: :days\nЗаявку не було підтверджено вчасно, транспорт звільнено.",
    ],
    'buttons' => [
        'open_admin' => 'Відкрити в адмінці',
        'booking_details' => 'Деталі бронювання',
        'create_booking' => 'Створити нову заявку',
    ],
];
