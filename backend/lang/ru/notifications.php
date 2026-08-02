<?php

return [
    'customer' => [
        'pending' => "<b>Заявка :booking получена</b>\nТехника: :vehicle\nПериод: :period\nСумма: :price\nМы сообщим, когда менеджер подтвердит бронирование.",
        'approved' => "<b>Бронирование :booking подтверждено</b>\nТехника: :vehicle\nПериод: :period\nСумма: :price",
        'cancelled' => "<b>Бронирование :booking отменено</b>\nТехника: :vehicle\nПериод: :period\nДля уточнения деталей свяжитесь с менеджером.",
        'expired' => "<b>Заявка :booking не была подтверждена вовремя</b>\nК сожалению, техника была освобождена. Вы можете создать новую заявку или связаться с нами для уточнения деталей.",
        'dates_changed' => "<b>Даты бронирования :booking изменены</b>\nБыло: :old_period\nСтало: :new_period\nАктуальная сумма: :price",
        'price_changed' => "<b>Сумма бронирования :booking изменена</b>\nТехника: :vehicle\nПериод: :period\nНовая сумма: :price",
        'reminder_pickup_day' => "<b>Сегодня начинается аренда :booking</b>\nТехника: :vehicle\nПериод: :period\nСумма: :price",
        'reminder_pickup_one_hour' => "<b>До начала аренды :booking остался один час</b>\nТехника: :vehicle\nВремя выдачи: :period",
    ],
    'admin' => [
        'pending' => "<b>Новая заявка :booking</b>\nКлиент: :customer\nТелефон: :phone\nTelegram: :telegram\nТехника: :vehicle\nПериод: :period\nСумма: :price\nШлемы: :helmets\nДоставка: :delivery\nАдрес: :address\nКомментарий: :comment",
        'cancelled_by_client' => "<b>Клиент отменил бронирование :booking</b>\nКлиент: :customer\nТелефон: :phone\nTelegram: :telegram\nТехника: :vehicle\nПериод: :period",
    ],
    'buttons' => [
        'open_admin' => 'Открыть в админке',
        'booking_details' => 'Детали бронирования',
        'create_booking' => 'Создать новую заявку',
    ],
];
