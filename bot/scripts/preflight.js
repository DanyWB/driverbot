const {spawnSync} = require("child_process");
require("dotenv").config();
const {isLaravelMode} = require("../config/runtime");

const LEGACY_CHECKS = [
  ["check:syntax", "npm", ["run", "check:syntax"]],
  ["check:translations", "npm", ["run", "check:translations"]],
  ["check:migrations", "npm", ["run", "check:migrations"]],
  ["check:bot-load", "npm", ["run", "check:bot-load"]],
  ["check:rental-service", "npm", ["run", "check:rental-service"]],
  ["check:data:preflight", "npm", ["run", "check:data:preflight"]],
];

const LARAVEL_CHECKS = [
  ["check:syntax", "npm", ["run", "check:syntax"]],
  ["check:translations", "npm", ["run", "check:translations"]],
  ["test", "npm", ["test"]],
  ["check:laravel-mode", "npm", ["run", "check:laravel-mode"]],
  ["check:redis-session", "npm", ["run", "check:redis-session"]],
  ["check:api", "npm", ["run", "check:api"]],
];

const CHECKS = isLaravelMode() ? LARAVEL_CHECKS : LEGACY_CHECKS;

let failed = false;

for (const [name, command, args] of CHECKS) {
  console.log(`\n== ${name} ==`);
  const result = spawnSync(command, args, {
    cwd: process.cwd(),
    stdio: "inherit",
    shell: process.platform === "win32",
  });

  if (result.status !== 0) {
    failed = true;
    console.error(`\n[fail] ${name}`);
    break;
  }
}

if (failed) {
  process.exitCode = 1;
} else {
  console.log("\nPreflight checks passed.");
}
