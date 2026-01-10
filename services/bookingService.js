const dayjs = require("dayjs");

const createEmptyBooking = () => ({
  scenario: null,
  step: null,
  selectedBikeId: null,
  startDate: null,
  startTime: null,
  endDate: null,
  endTime: null,
  timeSource: null,
  totalPrice: null,
  pricePerDay: null,
  priceUnknown: false,
  calendarMonth: dayjs().month() + 1, // 1-based month for UI
  calendarYear: dayjs().year(),
  helmets: 0,
  deliveryRequired: false,
  deliveryAddress: null,
  notes: null,
});

function ensureBooking(ctx) {
  if (!ctx.session.booking) {
    ctx.session.booking = createEmptyBooking();
  }
  return ctx.session.booking;
}

function resetBooking(ctx) {
  ctx.session.booking = null;
}

module.exports = {ensureBooking, resetBooking, createEmptyBooking};
