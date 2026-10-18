const assert = require("node:assert/strict");
const test = require("node:test");
const {composerCommand} = require("./composer-command");

test("Windows Composer preserves spaced PHAR/temp arguments without a shell", () => {
  const result = composerCommand(["audit", "--locked"], {
    platform: "win32",
    env: {COMPOSER_PHAR: "C:\\Tools With Spaces\\composer.phar", DRIVE_PHANGAN_PHP_TEMP: "C:/Project Files/tmp"},
    exists: () => true,
  });
  assert.equal(result.command, "php");
  assert.equal(result.shell, false);
  assert.deepEqual(result.args, ["-d", "sys_temp_dir=C:/Project Files/tmp", "-d", "upload_tmp_dir=C:/Project Files/tmp", "C:\\Tools With Spaces\\composer.phar", "audit", "--locked"]);
});

test("Windows Composer falls back to the PHAR next to composer.bat", () => {
  const result = composerCommand(["audit"], {
    platform: "win32", env: {},
    lookup: () => ({status: 0, stdout: "C:\\missing\\composer.bat\r\nC:\\Program Files\\Composer\\composer.bat\r\n"}),
    exists: (file) => file === "C:\\Program Files\\Composer\\composer.phar",
  });
  assert.deepEqual(result.args, ["C:\\Program Files\\Composer\\composer.phar", "audit"]);
});

test("Windows Composer fails clearly when the PHAR cannot be resolved", () => {
  assert.throws(() => composerCommand([], {
    platform: "win32", env: {}, exists: () => false,
    lookup: () => ({status: 1, stdout: ""}),
  }), /COMPOSER_PHAR/);
});

test("Linux Composer continues using its native command", () => {
  assert.deepEqual(composerCommand(["audit"], {platform: "linux"}), {
    command: "composer", args: ["audit"], shell: false,
  });
});
