// Read-only fixture generator. Run with the reviewed customer prototype and JSON paths.
import fs from 'node:fs';
import vm from 'node:vm';
const [htmlPath, jsonPath] = process.argv.slice(2);
const html = fs.readFileSync(htmlPath, 'utf8');
const payload = JSON.parse(fs.readFileSync(jsonPath, 'utf8'));
const source = html.slice(html.indexOf('function baseAt('), html.indexOf('const starterFleet='));

if (!source.startsWith('function baseAt(') || !source.includes('function calculate(')) {
throw new Error('Reference functions not found');
}

const context = vm.createContext({ durations: [1, 7, 14, 21, 30] });
vm.runInContext(source, context, { timeout: 1000 });
const expected = {};

for (const preset of payload.presets) {
    expected[preset.group] = {};

    for (const [sourceSeason, season] of [['Высокий сезон', 'high'], ['Средний сезон', 'middle'], ['Низкий сезон', 'low']]) {
        expected[preset.group][season] = Array.from({ length: 31 }, (_, i) => context.calculate(i + 1, preset.data[sourceSeason], preset.rules).price);
    }
}

process.stdout.write(JSON.stringify(expected, null, 2));
