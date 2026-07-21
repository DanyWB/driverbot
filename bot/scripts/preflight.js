const {spawnSync} = require("child_process");

const CHECKS = [
  ["check:syntax", "npm", ["run", "check:syntax"]],
  ["check:migrations", "npm", ["run", "check:migrations"]],
  ["check:bot-load", "npm", ["run", "check:bot-load"]],
  ["check:rental-service", "npm", ["run", "check:rental-service"]],
  ["check:data:preflight", "npm", ["run", "check:data:preflight"]],
];

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
