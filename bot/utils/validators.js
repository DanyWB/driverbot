const NAME_REGEX = /^[a-zA-Zа-яА-ЯёЁ\s\-]{2,}$/;
const PHONE_REGEX = /^\+\d{10,15}$/;

const isValidName = (value = "") => NAME_REGEX.test(value.trim());
const isValidPhone = (value = "") => PHONE_REGEX.test(value.trim());

module.exports = {isValidName, isValidPhone, NAME_REGEX, PHONE_REGEX};
