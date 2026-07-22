const assert = require("node:assert/strict");
const languages = require("../langs");

const names = Object.keys(languages);
const allKeys = new Set(names.flatMap((name) => Object.keys(languages[name])));

function placeholders(value) {
  return [...String(value).matchAll(/\{([A-Za-z0-9_]+)\}/g)]
    .map((match) => match[1])
    .filter((value, index, values) => values.indexOf(value) === index)
    .sort();
}

for (const name of names) {
  const missing = [...allKeys].filter((key) => !(key in languages[name]));
  assert.deepEqual(missing, [], `${name} is missing translation keys`);
}

const reference = names[0];
for (const key of allKeys) {
  const expected = placeholders(languages[reference][key]);
  for (const name of names.slice(1)) {
    assert.deepEqual(
      placeholders(languages[name][key]),
      expected,
      `${name}.${key} uses a different placeholder set than ${reference}.${key}`
    );
  }
}

console.log(`[ok] Translation dictionaries (${names.join(", ")}, ${allKeys.size} keys)`);
