const fs = require("fs");
const dayjs = require("dayjs");
const {google} = require("googleapis");
const {CALENDAR_RENTAL_STATUSES} = require("./rentalStatus");

const DEFAULT_DAYS_AHEAD = 90;
const DEFAULT_SHEET_NAME = "Calendar";
const DEFAULT_SYNC_MINUTES = 60;

const STATUS_COLORS = {
  process: "#FFF2CC", // light yellow
  pending: "#FCE5CD", // light orange
  approved: "#CFE2F3", // light blue
  active: "#C6E0B4", // light green
  ready: "#D9EAD3", // pale green
  completed: "#EAD1DC", // light purple
  unknown: "#F4CCCC", // light red
};

const STATUS_PRIORITY = {
  active: 5,
  approved: 4,
  ready: 4,
  pending: 3,
  process: 2,
  completed: 1,
  unknown: 0,
};

function hexToRgb(hex) {
  const clean = hex.replace("#", "");
  const num = parseInt(clean, 16);
  return {
    red: ((num >> 16) & 255) / 255,
    green: ((num >> 8) & 255) / 255,
    blue: (num & 255) / 255,
  };
}

function getServiceAccount() {
  const jsonEnv = process.env.GOOGLE_SHEETS_SERVICE_ACCOUNT_JSON;
  const pathEnv = process.env.GOOGLE_SHEETS_SERVICE_ACCOUNT_PATH;

  let raw = null;
  if (jsonEnv) {
    raw = jsonEnv.trim();
  } else if (pathEnv) {
    raw = fs.readFileSync(pathEnv, "utf8").trim();
  } else {
    return null;
  }

  let obj;
  try {
    obj = JSON.parse(raw);
  } catch (e) {
    const decoded = Buffer.from(raw, "base64").toString("utf8");
    obj = JSON.parse(decoded);
  }

  if (obj.private_key && obj.private_key.includes("\\n")) {
    obj.private_key = obj.private_key.replace(/\\n/g, "\n");
  }

  return obj;
}

async function getSheetsClient() {
  const credentials = getServiceAccount();
  if (!credentials) {
    return null;
  }

  const auth = new google.auth.GoogleAuth({
    credentials,
    scopes: ["https://www.googleapis.com/auth/spreadsheets"],
  });

  return google.sheets({version: "v4", auth});
}

async function ensureSheet(sheets, spreadsheetId, sheetName) {
  const meta = await sheets.spreadsheets.get({spreadsheetId});
  const existing = meta.data.sheets.find(
    (s) => s.properties.title === sheetName
  );

  if (existing) {
    return existing.properties.sheetId;
  }

  const res = await sheets.spreadsheets.batchUpdate({
    spreadsheetId,
    requestBody: {
      requests: [{addSheet: {properties: {title: sheetName}}}],
    },
  });

  return res.data.replies[0].addSheet.properties.sheetId;
}

function buildCalendarValues({bikes, dates, updatedAt}) {
  const title = "Bike Rentals Calendar";
  const rangeText = `Range: ${dates[0].format("DD.MM.YYYY")} - ${dates[
    dates.length - 1
  ].format("DD.MM.YYYY")} (${dates.length} days)`;
  const updatedText = `Updated: ${updatedAt.format("YYYY-MM-DD HH:mm")}`;

  const legendStatuses = [
    "pending",
    "approved",
    "active",
    "ready",
    "completed",
  ];

  const header = ["Bike", ...dates.map((d) => `${d.format("DD.MM")}\n${d.format("ddd")}`)];

  const values = [];
  values.push([title, updatedText]);
  values.push([rangeText]);
  values.push(["Legend", ...legendStatuses]);
  values.push(header);

  for (const bike of bikes) {
    const label = bike.emoji ? `${bike.emoji} ${bike.name}` : bike.name;
    values.push([label, ...new Array(dates.length).fill("")]);
  }

  return {values, legendStatuses};
}

function padValues(values, colCount) {
  return values.map((row) => {
    const padded = row.slice(0, colCount);
    while (padded.length < colCount) padded.push("");
    return padded;
  });
}

function buildFormatRequests({
  sheetId,
  rowCount,
  colCount,
  legendStatuses,
  statusGrid,
  datesCount,
}) {
  const requests = [];

  const fullRange = {
    sheetId,
    startRowIndex: 0,
    endRowIndex: rowCount,
    startColumnIndex: 0,
    endColumnIndex: colCount,
  };

  // Default format
  requests.push({
    repeatCell: {
      range: fullRange,
      cell: {
        userEnteredFormat: {
          backgroundColor: hexToRgb("#FFFFFF"),
          textFormat: {fontSize: 9},
          horizontalAlignment: "LEFT",
          verticalAlignment: "MIDDLE",
          wrapStrategy: "CLIP",
        },
      },
      fields:
        "userEnteredFormat(backgroundColor,textFormat,horizontalAlignment,verticalAlignment,wrapStrategy)",
    },
  });

  // Freeze header/labels
  requests.push({
    updateSheetProperties: {
      properties: {
        sheetId,
        gridProperties: {
          frozenRowCount: 4,
          frozenColumnCount: 1,
        },
      },
      fields: "gridProperties.frozenRowCount,gridProperties.frozenColumnCount",
    },
  });

  // Column widths
  requests.push({
    updateDimensionProperties: {
      range: {
        sheetId,
        dimension: "COLUMNS",
        startIndex: 0,
        endIndex: 1,
      },
      properties: {pixelSize: 260},
      fields: "pixelSize",
    },
  });
  requests.push({
    updateDimensionProperties: {
      range: {
        sheetId,
        dimension: "COLUMNS",
        startIndex: 1,
        endIndex: colCount,
      },
      properties: {pixelSize: 45},
      fields: "pixelSize",
    },
  });

  // Title row
  requests.push({
    repeatCell: {
      range: {sheetId, startRowIndex: 0, endRowIndex: 1},
      cell: {
        userEnteredFormat: {
          textFormat: {fontSize: 14, bold: true},
        },
      },
      fields: "userEnteredFormat.textFormat",
    },
  });

  // Header row (row index 3)
  requests.push({
    repeatCell: {
      range: {
        sheetId,
        startRowIndex: 3,
        endRowIndex: 4,
        startColumnIndex: 0,
        endColumnIndex: colCount,
      },
      cell: {
        userEnteredFormat: {
          backgroundColor: hexToRgb("#F3F3F3"),
          textFormat: {bold: true},
          horizontalAlignment: "CENTER",
          verticalAlignment: "MIDDLE",
          wrapStrategy: "WRAP",
        },
      },
      fields:
        "userEnteredFormat(backgroundColor,textFormat,horizontalAlignment,verticalAlignment,wrapStrategy)",
    },
  });

  // Bike name column
  requests.push({
    repeatCell: {
      range: {
        sheetId,
        startRowIndex: 4,
        endRowIndex: rowCount,
        startColumnIndex: 0,
        endColumnIndex: 1,
      },
      cell: {
        userEnteredFormat: {
          textFormat: {bold: true},
        },
      },
      fields: "userEnteredFormat.textFormat",
    },
  });

  // Calendar grid alignment
  requests.push({
    repeatCell: {
      range: {
        sheetId,
        startRowIndex: 4,
        endRowIndex: rowCount,
        startColumnIndex: 1,
        endColumnIndex: colCount,
      },
      cell: {
        userEnteredFormat: {
          horizontalAlignment: "CENTER",
          verticalAlignment: "MIDDLE",
        },
      },
      fields: "userEnteredFormat(horizontalAlignment,verticalAlignment)",
    },
  });

  // Legend colors (row index 2, columns 1..)
  legendStatuses.forEach((status, idx) => {
    const color = STATUS_COLORS[status] || STATUS_COLORS.unknown;
    requests.push({
      repeatCell: {
        range: {
          sheetId,
          startRowIndex: 2,
          endRowIndex: 3,
          startColumnIndex: 1 + idx,
          endColumnIndex: 2 + idx,
        },
        cell: {userEnteredFormat: {backgroundColor: hexToRgb(color)}},
        fields: "userEnteredFormat.backgroundColor",
      },
    });
  });

  // Status coloring (rows 4..)
  const dateStartCol = 1;
  statusGrid.forEach((rowStatuses, rowIndex) => {
    let start = 0;
    let current = rowStatuses[0] || null;
    for (let i = 1; i <= datesCount; i += 1) {
      const status = i < datesCount ? rowStatuses[i] || null : null;
      if (status !== current) {
        if (current) {
          const color = STATUS_COLORS[current] || STATUS_COLORS.unknown;
          requests.push({
            repeatCell: {
              range: {
                sheetId,
                startRowIndex: rowIndex,
                endRowIndex: rowIndex + 1,
                startColumnIndex: dateStartCol + start,
                endColumnIndex: dateStartCol + i,
              },
              cell: {userEnteredFormat: {backgroundColor: hexToRgb(color)}},
              fields: "userEnteredFormat.backgroundColor",
            },
          });
        }
        start = i;
        current = status;
      }
    }
  });

  return requests;
}

async function buildStatusGrid(db, bikes, startDate, endDate) {
  const days = endDate.diff(startDate, "day") + 1;
  const grid = new Map();
  bikes.forEach((bike, idx) => {
    const row = new Array(days).fill(null);
    grid.set(bike.id, {rowIndex: 4 + idx, statuses: row});
  });

  const rentals = await db("rentals")
    .select("bike_id", "start_date", "end_date", "status")
    .whereIn("status", CALENDAR_RENTAL_STATUSES)
    .andWhere("end_date", ">=", startDate.format("YYYY-MM-DD"))
    .andWhere("start_date", "<=", endDate.format("YYYY-MM-DD"));

  rentals.forEach((rental) => {
    const row = grid.get(rental.bike_id);
    if (!row) return;

    const rentalStart = dayjs(rental.start_date);
    const rentalEnd = dayjs(rental.end_date);
    if (!rentalStart.isValid() || !rentalEnd.isValid()) return;

    const startIdx = Math.max(0, rentalStart.diff(startDate, "day"));
    const endIdx = Math.min(days - 1, rentalEnd.diff(startDate, "day"));
    const status = rental.status || "unknown";

    for (let i = startIdx; i <= endIdx; i += 1) {
      const current = row.statuses[i];
      const currentPriority = STATUS_PRIORITY[current || "unknown"] ?? 0;
      const nextPriority = STATUS_PRIORITY[status] ?? 0;
      if (!current || nextPriority >= currentPriority) {
        row.statuses[i] = status;
      }
    }
  });

  const statusRows = [];
  grid.forEach((entry) => statusRows.push(entry));
  statusRows.sort((a, b) => a.rowIndex - b.rowIndex);
  return statusRows;
}

async function syncCalendarToGoogleSheets(db) {
  const spreadsheetId = process.env.GOOGLE_SHEETS_ID;
  if (!spreadsheetId) {
    return;
  }

  const sheets = await getSheetsClient();
  if (!sheets) {
    return;
  }

  const sheetName = process.env.GOOGLE_SHEETS_SHEET_NAME || DEFAULT_SHEET_NAME;
  const daysAhead = Number(process.env.GOOGLE_SHEETS_DAYS_AHEAD) || DEFAULT_DAYS_AHEAD;

  const sheetId = await ensureSheet(sheets, spreadsheetId, sheetName);

  const startDate = dayjs().startOf("day");
  const endDate = startDate.add(daysAhead - 1, "day");
  const dates = [];
  for (let i = 0; i < daysAhead; i += 1) {
    dates.push(startDate.add(i, "day"));
  }

  const bikes = await db("bikes")
    .select("id", "name", "category_id", "emoji")
    .orderBy("category_id")
    .orderBy("name");

  const {values, legendStatuses} = buildCalendarValues({
    bikes,
    dates,
    updatedAt: dayjs(),
  });

  const colCount = 1 + dates.length;
  const paddedValues = padValues(values, colCount);

  await sheets.spreadsheets.values.clear({
    spreadsheetId,
    range: `${sheetName}!A1:ZZ`,
  });

  await sheets.spreadsheets.values.update({
    spreadsheetId,
    range: `${sheetName}!A1`,
    valueInputOption: "RAW",
    requestBody: {values: paddedValues},
  });

  const statusRows = await buildStatusGrid(db, bikes, startDate, endDate);
  const statusGrid = statusRows.map((row) => row.statuses);

  const requests = buildFormatRequests({
    sheetId,
    rowCount: paddedValues.length,
    colCount,
    legendStatuses,
    statusGrid,
    datesCount: dates.length,
  });

  await sheets.spreadsheets.batchUpdate({
    spreadsheetId,
    requestBody: {requests},
  });
}

function startGoogleSheetsCalendarSync(db) {
  const intervalMinutes =
    Number(process.env.GOOGLE_SHEETS_SYNC_INTERVAL_MINUTES) || DEFAULT_SYNC_MINUTES;

  if (!process.env.GOOGLE_SHEETS_ID) {
    return;
  }

  if (!getServiceAccount()) {
    return;
  }

  const run = async () => {
    try {
      await syncCalendarToGoogleSheets(db);
    } catch (e) {
      console.error("Google Sheets sync failed:", e.message || e);
    }
  };

  run();
  setInterval(run, intervalMinutes * 60 * 1000);
}

module.exports = {
  syncCalendarToGoogleSheets,
  startGoogleSheetsCalendarSync,
};
