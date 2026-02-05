const ROUNDING_MODE = process.env.BIKE_PRICE_ROUNDING || "floor";
const ROUNDING_STEP = Number(process.env.BIKE_PRICE_ROUNDING_STEP) || 100;
const ROUNDING_MIN_DAYS = Number(process.env.BIKE_PRICE_ROUNDING_MIN_DAYS) || 7;

const DAYS = {
  "1d": {label: "1 day", days: 1},
  "7d": {label: "7 days", days: 7},
  "14d": {label: "14 days", days: 14},
  "21d": {label: "21 days", days: 21},
  month: {label: "Month", days: 30},
};

const SEASONS = [
  {key: "high", id: 3, label: "High"},
  {key: "middle", id: 2, label: "Middle"},
  {key: "low", id: 1, label: "Low"},
];

const PROFILES = {
  click: {
    label: "Click / Light",
    coefficients: {
      "1d": 1,
      "7d": 0.9937,
      "14d": 0.9316,
      "21d": 0.8281,
      month: 0.6376,
    },
  },
  aerox: {
    label: "Aerox / Comfort",
    coefficients: {
      "1d": 1,
      "7d": 0.9047,
      "14d": 0.7857,
      "21d": 0.6984,
      month: 0.5555,
    },
  },
  adv160: {
    label: "ADV 160",
    coefficients: {
      "1d": 1,
      "7d": 0.8888,
      "14d": 0.7777,
      "21d": 0.7407,
      month: 0.59255,
    },
  },
  pcx160: {
    label: "PCX 160",
    coefficients: {
      "1d": 1,
      "7d": 0.96,
      "14d": 0.888,
      "21d": 0.777,
      month: 0.69,
    },
  },
  price: {
    label: "Cars",
    coefficients: {
      "1d": 1,
      "7d": 0.8888,
      "14d": 0.7777,
      "21d": 0.6666,
      month: 0.5555,
    },
  },
};

function roundTotal(value, days = null) {
  if (!Number.isFinite(value)) return 0;
  if (days !== null && days < ROUNDING_MIN_DAYS) {
    return Math.round(value);
  }
  const step = ROUNDING_STEP > 0 ? ROUNDING_STEP : 100;
  if (ROUNDING_MODE === "ceil") {
    return Math.ceil(value / step) * step;
  }
  if (ROUNDING_MODE === "round") {
    return Math.round(value / step) * step;
  }
  return Math.floor(value / step) * step;
}

function roundToCents(value) {
  return Math.round(value * 100) / 100;
}

function calculatePriceRows({profileKey, basePrices}) {
  const profile = PROFILES[profileKey];
  if (!profile) return [];

  const rows = [];
  for (const season of SEASONS) {
    const base = Number(basePrices?.[season.key]);
    if (!Number.isFinite(base)) continue;

    for (const [daysType, info] of Object.entries(DAYS)) {
      const coef = profile.coefficients[daysType] ?? 1;
      const rawTotal = base * info.days * coef;
      const total = roundTotal(rawTotal, info.days);
      const perDay = roundToCents(total / info.days);
      rows.push({
        season_id: season.id,
        days_type: daysType,
        price_per_day: perDay,
        total,
        days: info.days,
      });
    }
  }

  return rows;
}

module.exports = {
  PROFILES,
  DAYS,
  SEASONS,
  ROUNDING_MODE,
  ROUNDING_STEP,
  ROUNDING_MIN_DAYS,
  calculatePriceRows,
  roundTotal,
};
