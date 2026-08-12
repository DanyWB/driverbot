<?php

return [
    'customer' => [
        'pending' => "<b>Request :booking received</b>\nVehicle: :vehicle\nRental period: :period\nTotal: :price\nWe will notify you when the manager confirms the booking.",
        'approved' => "<b>Booking :booking confirmed</b>\nVehicle: :vehicle\nRental period: :period\nTotal: :price",
        'cancelled' => "<b>Booking :booking cancelled</b>\nVehicle: :vehicle\nRental period: :period\nPlease contact the manager if you need more details.",
        'cancelled_with_reason' => "<b>Booking :booking cancelled</b>\nVehicle: :vehicle\nRental period: :period\nReason: :reason\nPlease contact the manager if you need more details.",
        'expired' => "<b>Request :booking was not confirmed in time</b>\nUnfortunately, the vehicle has been released. You can create a new request or contact us for more details.",
        'dates_changed' => "<b>Booking :booking dates changed</b>\nPrevious: :old_period\nNew: :new_period\nCurrent total: :price",
        'price_changed' => "<b>Booking :booking total changed</b>\nVehicle: :vehicle\nRental period: :period\nNew total: :price",
        'reminder_pickup_day' => "<b>Your rental :booking starts today</b>\nVehicle: :vehicle\nRental period: :period\nTotal: :price",
        'reminder_pickup_one_hour' => "<b>Your rental :booking starts in one hour</b>\nVehicle: :vehicle\nPickup: :period",
    ],
    'admin' => [
        'pending' => "<b>New request :booking</b>\nCustomer: :customer\nPhone: :phone\nTelegram: :telegram\nVehicle: :vehicle\nRental period: :period\nTotal: :price\nHelmets: :helmets\nDelivery: :delivery\nAddress: :address\nComment: :comment",
        'cancelled_by_client' => "<b>Customer cancelled booking :booking</b>\nCustomer: :customer\nPhone: :phone\nTelegram: :telegram\nVehicle: :vehicle\nRental period: :period",
        'expired' => "<b>Booking request :booking expired</b>\nCustomer: :customer\nPhone: :phone\nTelegram: :telegram\nVehicle: :vehicle\nRental period: :period\nThe request was not confirmed before the deadline and the vehicle was released.",
    ],
    'buttons' => [
        'open_admin' => 'Open admin',
        'booking_details' => 'Booking details',
        'create_booking' => 'Create a new request',
    ],
];
