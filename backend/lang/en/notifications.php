<?php

return [
    'customer' => [
        'pending' => "<b>Request :booking received</b>\nCustomer: :customer\nVehicle: :vehicle\nRental period: :period\nRental days: :days\nTotal: :price\nWe will process your request within 48 hours and before the rental starts. Until confirmation, the vehicle remains available to other customers.",
        'approved' => "<b>Booking :booking confirmed</b>\nVehicle: :vehicle\nRental period: :period\nRental days: :days\nTotal: :price",
        'cancelled' => "<b>Booking :booking cancelled</b>\nVehicle: :vehicle\nRental period: :period\nRental days: :days\nPlease contact the manager if you need more details.",
        'cancelled_with_reason' => "<b>Booking :booking cancelled</b>\nVehicle: :vehicle\nRental period: :period\nRental days: :days\nReason: :reason\nPlease contact the manager if you need more details.",
        'expired' => "<b>Request :booking expired</b>\nThe request was not confirmed before its deadline. Please choose different dates or a vehicle, or contact the manager.",
        'cancelled_unavailable' => "<b>Request :booking closed</b>\nSorry, the scooter is no longer available for your dates. Please choose different dates or a vehicle.",
        'dates_changed' => "<b>Booking :booking dates changed</b>\nPrevious: :old_period\nNew: :new_period\nCurrent total: :price",
        'price_changed' => "<b>Booking :booking total changed</b>\nVehicle: :vehicle\nRental period: :period\nRental days: :days\nNew total: :price",
        'reminder_pickup_day' => "<b>Your rental :booking starts today</b>\nVehicle: :vehicle\nRental period: :period\nRental days: :days\nTotal: :price",
        'reminder_pickup_one_hour' => "<b>Your rental :booking starts in one hour</b>\nVehicle: :vehicle\nPickup: :period",
    ],
    'admin' => [
        'pending_reminder' => "<b>Request :booking needs a decision</b>\nCustomer: :customer\nVehicle: :vehicle\nRental period: :period\nRental days: :days\nTotal: :price",
        'pending' => "<b>New request :booking</b>\nCustomer: :customer\nPhone: :phone\nTelegram: :telegram\nVehicle: :vehicle\nRental period: :period\nRental days: :days\nTotal: :price\nHelmets: :helmets\nDelivery: :delivery\nAddress: :address\nComment: :comment",
        'cancelled_by_client' => "<b>Customer cancelled booking :booking</b>\nCustomer: :customer\nPhone: :phone\nTelegram: :telegram\nVehicle: :vehicle\nRental period: :period",
        'expired' => "<b>Booking request :booking expired</b>\nCustomer: :customer\nPhone: :phone\nTelegram: :telegram\nVehicle: :vehicle\nRental period: :period\nRental days: :days\nThe request was not confirmed before the deadline and the vehicle was released.",
    ],
    'buttons' => [
        'open_admin' => 'Open admin',
        'booking_details' => 'Booking details',
        'create_booking' => 'Create a new request',
    ],
];
