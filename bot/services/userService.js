const db = require("../connect");
const {isLaravelMode} = require("../config/runtime");
const gateway = require("./laravelGateway");

async function updateProfile(userId, changes, label) {
  if (isLaravelMode()) {
    try {
      await gateway.updateProfile(userId, changes);
      return true;
    } catch (error) {
      console.error(`Could not update ${label} through Laravel:`, error);
      return false;
    }
  }
  return null;
}

async function updateUserPhone(userId, phone) {
  const apiResult = await updateProfile(userId, {phone}, "phone");
  if (apiResult !== null) return apiResult;
  try {
    await db("users").insert({telegram_id: userId, phone}).onConflict("telegram_id").merge({phone});
    return true;
  } catch (error) {
    console.error("Could not save phone:", error);
    return false;
  }
}

async function updateUserName(userId, name) {
  if (!name) return false;
  const apiResult = await updateProfile(userId, {name}, "name");
  if (apiResult !== null) return apiResult;
  try {
    await db("users").insert({telegram_id: userId, name}).onConflict("telegram_id").merge({name});
    return true;
  } catch (error) {
    console.error("Could not save name:", error);
    return false;
  }
}

async function updateUserPassportPhoto(userId, filename) {
  if (isLaravelMode()) {
    throw new Error("Use uploadUserPassportDocument in Laravel mode");
  }
  try {
    await db("users")
      .insert({telegram_id: userId, passport_photo_file_id: filename})
      .onConflict("telegram_id")
      .merge({passport_photo_file_id: filename});
    return true;
  } catch (error) {
    console.error("Could not save passport photo:", error);
    return false;
  }
}

async function uploadUserPassportDocument(userId, file) {
  if (!isLaravelMode()) return false;
  try {
    await gateway.uploadPassport(userId, file);
    return true;
  } catch (error) {
    console.error("Could not upload passport document:", error);
    return false;
  }
}

async function updateUserPassportNumber(userId, passportNumber) {
  const apiResult = await updateProfile(userId, {passport_number: passportNumber}, "passport number");
  if (apiResult !== null) return apiResult;
  try {
    const user = await db("users").where({telegram_id: userId}).first();
    let meta = {};
    if (user?.meta) {
      if (typeof user.meta === "object") meta = user.meta;
      else {
        try {
          meta = JSON.parse(user.meta);
        } catch (error) {
          meta = {};
        }
      }
    }
    const nextMeta = {...meta, passport_number: passportNumber};
    await db("users").insert({telegram_id: userId, meta: nextMeta}).onConflict("telegram_id").merge({meta: nextMeta});
    return true;
  } catch (error) {
    console.error("Could not save passport number:", error);
    return false;
  }
}

async function registerUser(user) {
  if (isLaravelMode()) return gateway.syncTelegramUser(user);
  const existing = await db("users").where({telegram_id: user.id}).first();
  if (!existing) {
    await db("users").insert({
      telegram_id: user.id,
      telegram_name: user.username || null,
    });
  }
  return getUserByTelegramId(user.id);
}

async function getUserProfile(userId) {
  return getUserByTelegramId(userId);
}

async function getUserByTelegramId(userId) {
  if (isLaravelMode()) return gateway.getProfile(userId);
  return db("users").where({telegram_id: userId}).first();
}

async function updateUserLanguage(userId, lang) {
  const apiResult = await updateProfile(userId, {locale: lang}, "language");
  if (apiResult !== null) return apiResult;
  try {
    await db("users").insert({telegram_id: userId, lang}).onConflict("telegram_id").merge({lang});
    return true;
  } catch (error) {
    console.error("Could not save language:", error);
    return false;
  }
}

module.exports = {
  getUserByTelegramId,
  getUserProfile,
  registerUser,
  updateUserLanguage,
  updateUserName,
  updateUserPassportNumber,
  updateUserPassportPhoto,
  updateUserPhone,
  uploadUserPassportDocument,
};
