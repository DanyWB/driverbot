const {execFileSync} = require("node:child_process");
const path = require("node:path");

const {isProhibitedDumpFile} = require("./check-secrets");

const ROOT = path.resolve(__dirname, "..");

function historicalDumpPathsFromNameList(nameList) {
  const paths = nameList
    .split(/\r?\n/)
    .filter(Boolean)
    .filter((file) => isProhibitedDumpFile(file));

  return [...new Set(paths)].sort();
}

function repositoryHistoryNames() {
  return execFileSync("git", [
    "-c",
    "core.quotepath=false",
    "log",
    "--all",
    "--pretty=format:",
    "--name-only",
    "--diff-filter=AMR",
  ], {
    cwd: ROOT,
    encoding: "utf8",
  });
}

function main() {
  const dumpPaths = historicalDumpPathsFromNameList(repositoryHistoryNames());
  if (dumpPaths.length === 0) {
    console.log("[ok] Git history contains no recognized database export paths");
    return;
  }

  console.error("[fail] Sensitive database export paths remain in Git history:");
  dumpPaths.forEach((file) => console.error(`- ${file}`));
  console.error("Rewrite the repository history and re-run this check before release.");
  process.exitCode = 1;
}

if (require.main === module) main();

module.exports = {historicalDumpPathsFromNameList};
