const {getTimeSlots} = require("./timeSlots");

function getTimeKeyboard(kind) {
  const slots = getTimeSlots();
  const rows = [];
  for (let i = 0; i < slots.length; i += 3) {
    rows.push(
      slots.slice(i, i + 3).map((time) => ({
        text: time,
        callback_data: `book:time:${kind}:${time}`,
      }))
    );
  }
  return {inline_keyboard: rows};
}

module.exports = {getTimeKeyboard};
