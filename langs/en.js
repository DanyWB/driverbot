module.exports = {
  lang_prompt: "🌍 Оберіть мову / Choose your language / Выберите язык",
  lang_saved: "✅ Language saved.",
  lang_unsupported: "Unsupported language.",
  lang_save_error: "Failed to save language. Please try again later.",

  cmd_start: "🏠 Main menu",
  cmd_book: "📅 Start booking",
  cmd_add_name: "✏️ Update name",
  cmd_add_tel: "📞 Update phone",
  cmd_add_passport: "🪪 Upload passport",

  welcome_new:
    "👋 Welcome, {name}!\n\nTo continue, please complete a short registration.",
  welcome_back: "👋 Welcome back, {name}!",

  enter_name: "✏️ Please enter your name:",
  enter_phone:
    "📞 Please enter your phone number in international format (e.g. +79995551234):",
  enter_passport: "🪪 Please send a photo of your passport:",

  update_name_prompt: "✏️ Enter a new name:",
  update_tel_prompt:
    "📞 Enter a new phone number in international format (e.g. +79995551234):",
  update_passport_prompt: "🪪 Send a new passport photo:",

  menu_title: "🏠 Main menu:",
  menu_book: "📅 Book a bike",
  menu_rental: "🧾 My rental",
  menu_update_name: "✏️ Update name",
  menu_update_tel: "📞 Update phone",
  menu_update_passport: "🪪 Upload passport",

  name_invalid:
    "Name must contain only letters and be at least 2 characters long. Try again.",
  name_save_error: "Failed to save name. Try again.",
  name_saved: "Name saved successfully.",

  phone_invalid: "Invalid phone format. Example: +12345678900",
  phone_save_error: "Failed to save phone. Try again.",
  phone_saved: "Phone saved successfully.",

  passport_missing: "Please send a photo (not a document, not text).",
  passport_save_error: "Failed to save the photo. Try again.",
  passport_saved: "Passport photo saved successfully.",
  passport_download_error:
    "An error occurred while downloading the photo. Try again.",

  user_not_found: "Error: user not found.",
  not_registered: "You are not registered.",

  booking_intro:
    "🚲 <b>Bike rental</b>\n\nChoose a convenient booking method:\n- first choose <b>dates</b>, then available bikes;\n- or first choose a <b>bike</b>, then available dates.\n\n📌 Choose an option below:",
  booking_btn_date_first: "📅 Choose date first",
  booking_btn_bike_first: "🏍️ Choose bike first",

  booking_choose_start_date: "📅 Choose the rental start date.",
  booking_choose_end_date: "📅 Choose the rental end date:",
  booking_choose_category: "🏍️ First choose a bike category.",
  booking_choose_bike: "🏍️ Choose a bike:",
  booking_no_bikes_in_category: "😔 No bikes in this category yet.",

  booking_category_light: "🌿 Light (110-125cc)",
  booking_category_comfort: "✨ Comfort (150-160cc)",
  booking_category_maxy: "🏎️ Maxy (300-350cc)",

  booking_dates_not_selected: "Rental dates are not selected.",
  booking_no_available_bikes:
    "😔 Unfortunately, no bikes are available for the selected dates.",
  booking_available_bikes_title: "🏍️ Available bikes:",

  booking_range_conflict:
    "There are already booked dates in the selected range. Try again.\n\n📅 Choose the rental start date:",
  booking_range_conflict_bike:
    "The selected range includes booked dates for this bike. Choose another period.",
  booking_end_before_start: "End date cannot be earlier than start date.",

  booking_invalid_bike_id: "Invalid bike ID format.",
  booking_bike_not_found: "Bike not found.",

  booking_period_label: "Rental period:",
  booking_price_label: "Price:",
  days_label: "days",

  booking_add_to_rental_btn: "✅ Add to rental",

  booking_not_enough_data: "Not enough data to create a rental.",
  booking_current_title: "🧾 <b>Current rental:</b>\n\n",
  booking_item:
    "🏍️ <b>{name}</b>\n{start} - {end} ({days} {days_label})\nPrice: {price} THB\n\n",
  booking_confirm_btn: "✅ Confirm booking",
  booking_add_bike_btn: "➕ Add bike",
  booking_delete_bike_btn: "🗑️ Remove bike",
  booking_reset_btn: "♻️ Reset",
  booking_comment_btn: "💬 Notes",
  booking_add_error: "⚠️ Error while saving the rental.",

  booking_confirmed:
    "✅ Your rental is confirmed!\nPlease wait for admin confirmation.",
  booking_no_bookings_to_confirm: "No bookings to confirm.",
  booking_bike_busy:
    "Bike \"{name}\" is already booked for these dates. Please choose another period.",
  booking_admin_missing:
    "Sorry. There is no admin in the system right now. Please cancel this rental and try again later.",
  booking_admin_new:
    "🚨 <b>New rental</b>\n\nUser: {user} Telegram: @{username}\nBike: <b>{bike}</b>\nDates: {start} - {end}\nTotal: {price} THB\nComment: {comment}",

  booking_delete_failed: "Failed to remove the bike.",
  booking_bike_removed: "🗑️ Bike removed from your rental.",
  booking_no_bikes_in_process: "You have no bikes in the rental process.",
  booking_delete_prompt: "🗑️ Choose a bike to remove from your rental:",
  booking_delete_bike_item: "Remove {name}",
  booking_reset_done: "♻️ Rental reset. You can start again.",
  booking_comment_prompt:
    "💬 Enter your notes (e.g. “double helmet”, “hotel delivery”):",
  booking_comment_saved: "💬 Your notes have been saved.",
  booking_choose_bike_btn: "Choose a bike",
  booking_period_selected:
    "📅 You selected the rental period:\n{start} - {end}\n\nNow choose a bike.",
  booking_start_date_selected:
    "📅 Start date: {date}\n\nNow choose the end date.",
  booking_add_bike_to_rental_btn: "➕ Add a bike to the rental",
  booking_bike_summary:
    "🏍️ <b>{name}</b>\n\n<b>Rental period:</b> {start} - {end} ({days} {days_label})\n<b>Price:</b> {price} THB\n\n{desc}",
  booking_season_not_found:
    "Failed to determine season for the selected date.",

  btn_back: "⬅️ Back",
  btn_home: "🏠 Home",

  cal_prev: "◀️",
  cal_next: "▶️",
  cal_back: "⬅️ Back",
  cal_blocked: "⛔",
  calendar_no_scenario: "Error: no active booking flow.",

  admin_rental_not_found: "Rental not found.",
  admin_status_approved: "approved",
  admin_status_cancelled: "cancelled",
  admin_request_status: "Request #{id} {status}.",
  admin_btn_approve: "Approve",
  admin_btn_cancel: "Reject",

  user_no_name: "(no name)",
  user_default_name: "user",

  start_registration: "Let's start with your name.",
  skip_registration: "You can complete registration later.",
  skip_registration_reply: "Okay. If you change your mind, send /start again.",
};
