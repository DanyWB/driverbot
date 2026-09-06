const {execFileSync} = require("node:child_process");
const fs = require("node:fs");
const path = require("node:path");

const ROOT = path.resolve(__dirname, "..");
const BINARY_EXTENSIONS = new Set([
  ".avif", ".csv", ".gif", ".ico", ".jpeg", ".jpg", ".lock", ".pdf",
  ".png", ".webp", ".xls", ".xlsx", ".zip",
]);
const PROHIBITED_DUMP_EXTENSIONS = [".backup", ".dump", ".sql.gz"];
const PROHIBITED_SQL_DUMP_NAMES = new Set(["bd.sql", "driverbot.sql", "insert.sql"]);
const SENSITIVE_SQL_DATA = /\b(?:COPY|INSERT\s+INTO)\s+(?:"?public"?\.)?"?(?:no_availability_requests|reminders|rentals|sessions|users)"?\b/i;
const PATTERNS = [
  ["Telegram bot token", /\b[0-9]{6,12}:[A-Za-z0-9_-]{30,}\b/g],
  ["AWS access key", /\b(?:AKIA|ASIA)[A-Z0-9]{16}\b/g],
  ["GitHub token", /\b(?:ghp|github_pat)_[A-Za-z0-9_]{20,}\b/g],
  ["Private key", new RegExp(`-----BEGIN (?:RSA |EC |OPENSSH )?${"PRIVATE"} KEY-----`, "g")],
];

function repositoryFiles() {
  return execFileSync(
    "git",
    ["ls-files", "-z", "--cached", "--others", "--exclude-standard"],
    {cwd: ROOT, encoding: "utf8"}
  )
    .split("\0")
    .filter(Boolean);
}

function isUnexpectedEnvFile(file) {
  const name = path.basename(file);
  return (name === ".env" || name.startsWith(".env.")) && !name.endsWith(".example");
}

function isProhibitedDumpFile(file) {
  const normalized = file.replaceAll("\\", "/").toLowerCase();
  const basename = path.posix.basename(normalized);

  return PROHIBITED_SQL_DUMP_NAMES.has(basename)
    || PROHIBITED_DUMP_EXTENSIONS.some((extension) => normalized.endsWith(extension));
}

function inspectFile(file, contents = "") {
  const failures = [];

  if (isUnexpectedEnvFile(file)) {
    failures.push(`${file}: repository environment file`);
  }

  if (isProhibitedDumpFile(file)) {
    failures.push(`${file}: database export must not be stored in Git`);
  }

  if (path.extname(file).toLowerCase() === ".sql" && SENSITIVE_SQL_DATA.test(contents)) {
    failures.push(`${file}: SQL export contains customer or booking rows`);
  }

  for (const [label, pattern] of PATTERNS) {
    pattern.lastIndex = 0;
    if (pattern.test(contents)) failures.push(`${file}: ${label}`);
  }

  return failures;
}

function main() {
  const failures = [];

  for (const file of repositoryFiles()) {
    const absolute = path.join(ROOT, file);
    const extension = path.extname(file).toLowerCase();
    if (!fs.existsSync(absolute)) continue;

    const metadataFailures = inspectFile(file);
    failures.push(...metadataFailures);

    if (BINARY_EXTENSIONS.has(extension)) continue;
    if (fs.statSync(absolute).size > 2 * 1024 * 1024) {
      if (extension === ".sql") {
        failures.push(`${file}: SQL file is too large for content inspection and must stay outside Git`);
      }
      continue;
    }

    const contents = fs.readFileSync(absolute, "utf8");
    const contentFailures = inspectFile(file, contents)
      .filter((failure) => !metadataFailures.includes(failure));
    failures.push(...contentFailures);
  }

  if (failures.length) {
    console.error("[fail] Potential secrets found in repository files:");
    failures.forEach((failure) => console.error(`- ${failure}`));
    process.exitCode = 1;
    return;
  }

  console.log("[ok] No environment files, sensitive database exports, or recognized secret patterns in the current tree");
}

if (require.main === module) main();

module.exports = {inspectFile, isProhibitedDumpFile, isUnexpectedEnvFile};
