<?php

return [
    'customer' => [
        'pending' => "<b>Заявка :booking получена</b>\nКлиент: :customer\nТехника: :vehicle\nПериод: :period\nДней аренды: :days\nСумма: :price\nОбработаем заявку максимум в течение 48 часов, но не позднее начала аренды. До подтверждения техника остаётся доступной другим клиентам.",
        'approved' => "<b>Бронирование :booking подтверждено</b>\nТехника: :vehicle\nПериод: :period\nДней аренды: :days\nСумма: :price",
        'cancelled' => "<b>Бронирование :booking отменено</b>\nТехника: :vehicle\nПериод: :period\nДней аренды: :days\nДля уточнения деталей свяжитесь с менеджером.",
        'cancelled_with_reason' => "<b>Бронирование :booking отменено</b>\nТехника: :vehicle\nПериод: :period\nДней аренды: :days\nПричина: :reason\nДля уточнения деталей свяжитесь с менеджером.",
        'expired' => "<b>Срок заявки :booking истёк</b>\nЗаявка не была подтверждена вовремя. Пожалуйста, выберите другие даты или технику либо свяжитесь с менеджером.",
        'cancelled_unavailable' => "<b>Заявка :booking закрыта</b>\nИзвините, скутер уже недоступен на ваши даты. Пожалуйста, выберите другие даты или технику.",
        'dates_changed' => "<b>Даты бронирования :booking изменены</b>\nБыло: :old_period\nСтало: :new_period\nАктуальная сумма: :price",
        'price_changed' => "<b>Сумма бронирования :booking изменена</b>\nТехника: :vehicle\nПериод: :period\nДней аренды: :days\nНовая сумма: :price",
        'reminder_pickup_day' => "<b>Сегодня начинается аренда :booking</b>\nТехника: :vehicle\nПериод: :period\nДней аренды: :days\nСумма: :price",
        'reminder_pickup_one_hour' => "<b>До начала аренды :booking остался один час</b>\nТехника: :vehicle\nВремя выдачи: :period",
    ],
    'admin' => [
        'pending_reminder' => "<b>Заявка :booking ожидает решения</b>\nКлиент: :customer\nТехника: :vehicle\nПериод: :period\nДней аренды: :days\nСумма: :price",
        'pending' => "<b>Новая заявка :booking</b>\nКлиент: :customer\nТелефон: :phone\nTelegram: :telegram\nТехника: :vehicle\nПериод: :period\nДней аренды: :days\nСумма: :price\nШлемы: :helmets\nДоставка: :delivery\nАдрес: :address\nКомментарий: :comment",
        'cancelled_by_client' => "<b>Клиент отменил бронирование :booking</b>\nКлиент: :customer\nТелефон: :phone\nTelegram: :telegram\nТехника: :vehicle\nПериод: :period",
        'expired' => "<b>Срок заявки :booking истек</b>\nКлиент: :customer\nТелефон: :phone\nTelegram: :telegram\nТехника: :vehicle\nПериод: :period\nДней аренды: :days\nЗаявка не была подтверждена вовремя, техника освобождена.",
    ],
    'buttons' => [
        'open_admin' => 'Открыть в админке',
        'booking_details' => 'Детали бронирования',
        'create_booking' => 'Создать новую заявку',
    ],
];
