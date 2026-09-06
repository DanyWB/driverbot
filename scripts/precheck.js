const fs = require("fs");
const path = require("path");
const {spawnSync} = require("child_process");

const root = path.resolve(__dirname, "..");
const phpTemp = path.join(root, "backend", "storage", "framework", "testing");
const childEnv = {...process.env};

if (process.platform === "win32") {
  fs.mkdirSync(phpTemp, {recursive: true});
  childEnv.DRIVE_PHANGAN_PHP_TEMP = phpTemp;
  childEnv.PHP_INI_SCAN_DIR = [
    path.join(root, "scripts", "php-ini"),
    process.env.PHP_INI_SCAN_DIR,
  ]
    .filter(Boolean)
    .join(path.delimiter);
}

const CHECKS = [
  ["repository security tests", "node", ["--test", "scripts/check-secrets.test.js"]],
  ["tracked secrets", "npm", ["run", "check:secrets"]],
  ["Composer production dependency audit", "composer", ["--working-dir", "backend", "audit", "--locked", "--no-dev"]],
  ["backend production dependency audit", "npm", ["--prefix", "backend", "audit", "--omit=dev"]],
  ["bot production dependency audit", "npm", ["--prefix", "bot", "audit", "--omit=dev"]],
  ["Telegram bot", "npm", ["run", "preflight"]],
  ["backend frontend build", "npm", ["run", "backend:build"]],
  ["backend CI", "npm", ["run", "backend:check"]],
  ["sensitive Git history", "npm", ["run", "check:history"]],
];

for (const [name, command, args] of CHECKS) {
  console.log(`\n== ${name} ==`);

  const result = spawnSync(command, args, {
    cwd: root,
    env: childEnv,
    stdio: "inherit",
    shell: process.platform === "win32",
  });

  if (result.status !== 0) {
    console.error(`\n[fail] ${name}`);
    process.exitCode = result.status || 1;
    return;
  }
}

console.log("\nProject precheck passed.");
