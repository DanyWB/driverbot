const {spawnSync} = require("child_process");

const CHECKS = [
  ["Telegram bot", "npm", ["run", "preflight"]],
  ["backend frontend build", "npm", ["run", "backend:build"]],
  ["backend CI", "npm", ["run", "backend:check"]],
];

for (const [name, command, args] of CHECKS) {
  console.log(`\n== ${name} ==`);

  const result = spawnSync(command, args, {
    cwd: process.cwd(),
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
