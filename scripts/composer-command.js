const fs = require("node:fs");
const path = require("node:path");
const {spawnSync} = require("node:child_process");

// Invoke the PHAR directly on Windows: nesting composer.bat in npm/cmd can
// lose its %~dp0 directory. Arguments (including spaces) must bypass the shell.
function composerCommand(args, options = {}) {
  const platform = options.platform ?? process.platform;
  if (platform !== "win32") return {command: "composer", args, shell: false};

  const env = options.env ?? process.env;
  const exists = options.exists ?? fs.existsSync;
  const lookup = options.lookup ?? spawnSync;
  let phar = env.COMPOSER_PHAR;

  if (!phar || !exists(phar)) {
    const result = lookup("where.exe", ["composer.bat"], {
      encoding: "utf8",
      windowsHide: true,
      env,
    });
    phar = result.status === 0
      ? String(result.stdout).split(/\r?\n/).filter(Boolean)
        .map((file) => path.win32.join(path.win32.dirname(file.trim()), "composer.phar"))
        .find(exists)
      : undefined;
  }

  if (!phar) throw new Error("Composer PHAR not found. Set COMPOSER_PHAR to its full path.");

  const phpArgs = [];
  if (env.DRIVE_PHANGAN_PHP_TEMP) {
    phpArgs.push("-d", `sys_temp_dir=${env.DRIVE_PHANGAN_PHP_TEMP}`);
    phpArgs.push("-d", `upload_tmp_dir=${env.DRIVE_PHANGAN_PHP_TEMP}`);
  }
  return {command: "php", args: [...phpArgs, phar, ...args], shell: false};
}

module.exports = {composerCommand};
