const {execFileSync} = require("node:child_process");
const fs = require("node:fs");
const path = require("node:path");

const ROOT = path.resolve(__dirname, "..");
const BINARY_EXTENSIONS = new Set([
  ".avif", ".csv", ".gif", ".ico", ".jpeg", ".jpg", ".lock", ".pdf",
  ".png", ".webp", ".xls", ".xlsx", ".zip",
]);
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

function main() {
  const failures = [];

  for (const file of repositoryFiles()) {
    if (isUnexpectedEnvFile(file)) {
      failures.push(`${file}: tracked environment file`);
      continue;
    }

    const absolute = path.join(ROOT, file);
    const extension = path.extname(file).toLowerCase();
    if (BINARY_EXTENSIONS.has(extension) || !fs.existsSync(absolute)) continue;
    if (fs.statSync(absolute).size > 2 * 1024 * 1024) continue;

    const contents = fs.readFileSync(absolute, "utf8");
    for (const [label, pattern] of PATTERNS) {
      pattern.lastIndex = 0;
      if (pattern.test(contents)) failures.push(`${file}: ${label}`);
    }
  }

  if (failures.length) {
    console.error("[fail] Potential secrets found in repository files:");
    failures.forEach((failure) => console.error(`- ${failure}`));
    process.exitCode = 1;
    return;
  }

  console.log("[ok] No environment files or recognized secret patterns in repository files");
}

main();
