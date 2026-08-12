const fs = require('fs');
const path = require('path');
const { spawnSync } = require('child_process');

const root = path.resolve(__dirname, '..');
const backend = path.join(root, 'backend');
const phpTemp = path.join(backend, 'storage', 'framework', 'testing');
const phpEnv = {
  ...process.env,
  DRIVE_PHANGAN_PHP_TEMP: phpTemp,
  PHP_INI_SCAN_DIR: [
    path.join(root, 'scripts', 'php-ini'),
    process.env.PHP_INI_SCAN_DIR,
  ]
    .filter(Boolean)
    .join(path.delimiter),
};

fs.mkdirSync(phpTemp, { recursive: true });

function findWindowsComposerPhar() {
  if (process.env.COMPOSER_PHAR && fs.existsSync(process.env.COMPOSER_PHAR)) {
    return process.env.COMPOSER_PHAR;
  }

  const lookup = spawnSync('where.exe', ['composer.bat'], {
    encoding: 'utf8',
    windowsHide: true,
  });

  if (lookup.status !== 0) {
    return null;
  }

  for (const candidate of lookup.stdout.split(/\r?\n/).filter(Boolean)) {
    const phar = path.join(path.dirname(candidate.trim()), 'composer.phar');

    if (fs.existsSync(phar)) {
      return phar;
    }
  }

  return null;
}

let result;

if (process.platform === 'win32') {
  const composerPhar = findWindowsComposerPhar();

  if (!composerPhar) {
    console.error(
      'Composer PHAR was not found next to composer.bat. Set COMPOSER_PHAR to its path.',
    );
    process.exit(1);
  }

  result = spawnSync(
    'php',
    [
      '-d',
      `sys_temp_dir=${phpTemp}`,
      '-d',
      `upload_tmp_dir=${phpTemp}`,
      composerPhar,
      '--working-dir',
      backend,
      'ci:check',
    ],
    { cwd: root, env: phpEnv, stdio: 'inherit', windowsHide: true },
  );
} else {
  result = spawnSync(
    'composer',
    ['--working-dir', backend, 'ci:check'],
    { cwd: root, env: phpEnv, stdio: 'inherit' },
  );
}

if (result.error) {
  console.error(result.error.message);
}

process.exit(result.status ?? 1);
