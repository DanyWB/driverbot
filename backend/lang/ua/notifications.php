<?php

return [
    'customer' => [
        'pending' => "<b>Заявку :booking отримано</b>\nТранспорт: :vehicle\nПеріод: :period\nСума: :price\nМи повідомимо, коли менеджер підтвердить бронювання.",
        'approved' => "<b>Бронювання :booking підтверджено</b>\nТранспорт: :vehicle\nПеріод: :period\nСума: :price",
        'cancelled' => "<b>Бронювання :booking скасовано</b>\nТранспорт: :vehicle\nПеріод: :period\nДля уточнення деталей зв'яжіться з менеджером.",
        'expired' => "<b>Заявку :booking не було підтверджено вчасно</b>\nНа жаль, транспорт було звільнено. Ви можете створити нову заявку або зв'язатися з нами для уточнення деталей.",
        'dates_changed' => "<b>Дати бронювання :booking змінено</b>\nБуло: :old_period\nСтало: :new_period\nАктуальна сума: :price",
        'price_changed' => "<b>Суму бронювання :booking змінено</b>\nТранспорт: :vehicle\nПеріод: :period\nНова сума: :price",
        'reminder_pickup_day' => "<b>Сьогодні починається оренда :booking</b>\nТранспорт: :vehicle\nПеріод: :period\nСума: :price",
        'reminder_pickup_one_hour' => "<b>До початку оренди :booking залишилася одна година</b>\nТранспорт: :vehicle\nЧас видачі: :period",
    ],
    'admin' => [
        'pending' => "<b>Нова заявка :booking</b>\nКлієнт: :customer\nТелефон: :phone\nTelegram: :telegram\nТранспорт: :vehicle\nПеріод: :period\nСума: :price\nШоломи: :helmets\nДоставка: :delivery\nАдреса: :address\nКоментар: :comment",
        'cancelled_by_client' => "<b>Клієнт скасував бронювання :booking</b>\nКлієнт: :customer\nТелефон: :phone\nTelegram: :telegram\nТранспорт: :vehicle\nПеріод: :period",
    ],
    'buttons' => [
        'open_admin' => 'Відкрити в адмінці',
        'booking_details' => 'Деталі бронювання',
        'create_booking' => 'Створити нову заявку',
    ],
];
