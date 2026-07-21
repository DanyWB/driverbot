const fs = require("fs");
const path = require("path");
const {spawnSync} = require("child_process");

const ROOT = path.resolve(__dirname, "..");
const SKIP_DIRS = new Set([".git", "node_modules"]);

function collectJsFiles(dir, files = []) {
  for (const entry of fs.readdirSync(dir, {withFileTypes: true})) {
    if (entry.isDirectory()) {
      if (!SKIP_DIRS.has(entry.name)) {
        collectJsFiles(path.join(dir, entry.name), files);
      }
      continue;
    }

    if (entry.isFile() && entry.name.endsWith(".js")) {
      files.push(path.join(dir, entry.name));
    }
  }

  return files;
}

function relative(file) {
  return path.relative(ROOT, file).replace(/\\/g, "/");
}

const files = collectJsFiles(ROOT).sort();
let failed = false;

for (const file of files) {
  const result = spawnSync(process.execPath, ["--check", file], {
    cwd: ROOT,
    stdio: "pipe",
    encoding: "utf8",
  });

  if (result.status !== 0) {
    failed = true;
    console.error(`[fail] ${relative(file)}`);
    if (result.stdout) process.stdout.write(result.stdout);
    if (result.stderr) process.stderr.write(result.stderr);
  }
}

if (failed) {
  process.exitCode = 1;
} else {
  console.log(`[ok] JS syntax (${files.length} files)`);
}
