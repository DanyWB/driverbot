const db = require("../connect");

async function updateUserPhone(userId, phone) {
  try {
    const result = await db("users")
      .insert({telegram_id: userId, phone})
      .onConflict("telegram_id")
      .merge({phone});

    return true; // Успех
  } catch (err) {
    console.error("Ошибка при сохранении телефона:", err);
    return false;
  }
}

async function updateUserName(userId, name) {
  try {
    if (!name) return false; // не обновляем пустое
    await db("users")
      .insert({telegram_id: userId, name})
      .onConflict("telegram_id")
      .merge({name});
    return true;
  } catch (error) {
    console.error("Ошибка при сохранении имени:", error);
    return false;
  }
}

async function updateUserPassportPhoto(userId, filename) {
  try {
    await db("users")
      .insert({telegram_id: userId, passport_photo_file_id: filename})
      .onConflict("telegram_id")
      .merge({passport_photo_file_id: filename});
    return true;
  } catch (error) {
    console.error("Ошибка при сохранении фото паспорта:", error);
    return false;
  }
}

async function registerUser(user) {
  const existing = await db("users").where({telegram_id: user.id}).first();
  if (!existing) {
    await db("users").insert({
      telegram_id: user.id,
      telegram_name: user.username || null,
    });
  }
}

async function getUserProfile(userId) {
  return await db("users").where({telegram_id: userId}).first();
}

async function getUserByTelegramId(userId) {
  return db("users").where({telegram_id: userId}).first();
}

module.exports = {
  updateUserPhone,
  updateUserName,
  updateUserPassportPhoto,
  registerUser,
  getUserProfile,
  getUserByTelegramId,
};
