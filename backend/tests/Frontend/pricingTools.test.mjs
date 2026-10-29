import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { defaultPricingSettings, linkedPricing } from '../../resources/js/lib/pricingTools.ts';

test('calculator defaults have independent explicit calendars and all rule parameters',()=>{
    const first=defaultPricingSettings();const second=defaultPricingSettings();
    assert.equal(first.prefs.seasonCalendar.length,12);
    first.prefs.seasonCalendar[0]='low';assert.equal(second.prefs.seasonCalendar[0],'high');
    assert.deepEqual(Object.keys(second.rules).sort(),['avg','cap','holdDays','mode','monthly','monthlyStart','step']);
});
test('linked pricing supports Vue-style proxies without structuredClone and keeps source immutable',()=>{
    const settings=defaultPricingSettings();settings.prefs.linkMiddle=true;
    const source={high:{'1d':370,'7d':2400,'14d':4400,'21d':6300,month:7500},middle:{'1d':0,'7d':0,'14d':0,'21d':0,month:0},low:{'1d':330,'7d':2000,'14d':3500,'21d':4900,month:5700}};
    const result=linkedPricing(new Proxy(source,{}),settings);
    assert.equal(result.middle['1d'],350);assert.equal(source.middle['1d'],0);assert.deepEqual(result.low,source.low);
});
test('pricing UI uses custom selects and exposes accessible calendar and reduced-motion controls',()=>{
    const dir=new URL('../../resources/js/components/pricing/',import.meta.url);
    const editor=readFileSync(new URL('PricingRulesEditor.vue',dir),'utf8');
    const workbench=readFileSync(new URL('PricingWorkbench.vue',dir),'utf8');
    const calendar=readFileSync(new URL('PricingCalendar.vue',dir),'utf8');
    assert.doesNotMatch(editor+workbench+calendar,/<select(?:\s|>)/);
    assert.match(editor,/Long-period override day/);assert.match(workbench,/days-preview|Preliminary: days only/);
    assert.match(calendar,/ArrowDown/);assert.match(calendar,/keydown.esc/);assert.match(calendar,/prefers-reduced-motion/);
    assert.match(workbench,/calculationGeneration/);assert.match(workbench,/350ms/);
    assert.match(workbench,/Number\(result\.version\) !== preset\.version/);
});
