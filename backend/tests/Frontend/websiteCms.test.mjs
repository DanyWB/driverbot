import assert from 'node:assert/strict';
import test from 'node:test';
import fs from 'node:fs/promises';
import {
    cloneCms,
    cmsDirty,
    cmsTransport,
    emptyLocaleText,
    moveCmsItem,
    newCmsBlock,
    newCmsItem,
} from '../../resources/js/lib/websiteCms.ts';

test('transport omits blank translations without inventing values or mutating draft', () => {
    const value = {
        title: { en: 'Real text', ru: '', uk: '   ' },
        cover_asset_id: null,
        blocks: [newCmsBlock('text_image', 'fixture-id')],
    };
    const transported = cmsTransport(value);
    assert.deepEqual(transported.title, { en: 'Real text' });
    assert.equal(transported.cover_asset_id, null);
    assert.deepEqual(transported.blocks[0].props.heading, {});
    assert.equal(value.title.ru, '');
});

test('six typed CMS blocks match the contract without arbitrary URLs or markup fields', () => {
    const allowed = {
        hero: ['heading', 'body', 'image_ref', 'action', 'action_label'],
        catalog: ['heading', 'vehicle_type', 'limit'],
        text_image: ['heading', 'body', 'image_ref'],
        steps: ['heading', 'items'],
        faq: ['heading', 'items'],
        contact_cta: ['heading', 'body', 'channel', 'action_label'],
    };

    for (const [type, keys] of Object.entries(allowed)) {
        const block = newCmsBlock(type, 'fixture-id');
        assert.equal(block.id, 'fixture-id');
        assert.deepEqual(Object.keys(block.props).sort(), keys.sort());
        assert.deepEqual(block.props.heading, { en: '', ru: '', uk: '' });
    }

    assert.equal(newCmsBlock('catalog').props.limit, 6);
    assert.equal(newCmsBlock('hero').props.image_ref, null);
});

test('reordering keeps stable block and item identities and does not mutate originals', () => {
    const blocks = ['hero', 'catalog', 'faq'].map((type, index) =>
        newCmsBlock(type, `block-${index}`),
    );
    const moved = moveCmsItem(blocks, 1, -1);
    assert.deepEqual(
        moved.map((block) => block.id),
        ['block-1', 'block-0', 'block-2'],
    );
    assert.deepEqual(
        blocks.map((block) => block.id),
        ['block-0', 'block-1', 'block-2'],
    );
    assert.deepEqual(moveCmsItem(blocks, 0, -1), blocks);
    assert.deepEqual(moveCmsItem(blocks, 2, 1), blocks);
    assert.deepEqual(Object.keys(newCmsItem('faq', 'item-id')).sort(), [
        'answer',
        'id',
        'question',
    ]);
    assert.deepEqual(Object.keys(newCmsItem('steps', 'item-id')).sort(), [
        'body',
        'id',
        'title',
    ]);
});

test('draft dirty tracking is isolated from saved and published snapshots', () => {
    const saved = {
        title: emptyLocaleText(),
        blocks: [newCmsBlock('hero', 'hero-id')],
    };
    const draft = cloneCms(saved);
    assert.equal(cmsDirty(draft, saved), false);
    draft.title.ru = 'Локальная проверка';
    assert.equal(cmsDirty(draft, saved), true);
    assert.equal(saved.title.ru, '');
    assert.equal(saved.blocks[0].props.heading.ru, '');
    draft.blocks[0].props.heading.uk = 'Окремий переклад';
    assert.equal(draft.blocks[0].props.heading.ru, '');
});

test('CMS editors reuse keyboard-capable custom selects and reject raw HTML rendering', async () => {
    for (const file of [
        'components/website-cms/BlockEditor.vue',
        'pages/website-admin/cards/Edit.vue',
        'pages/website-admin/cards/Index.vue',
    ]) {
        const source = await fs.readFile(
            new URL(`../../resources/js/${file}`, import.meta.url),
            'utf8',
        );
        assert.match(source, /AdminSelect/);
        assert.doesNotMatch(source, /<select\b|v-html/);
    }

    const preview = await fs.readFile(
        new URL(
            '../../resources/js/components/website-cms/CmsPreview.vue',
            import.meta.url,
        ),
        'utf8',
    );
    assert.match(preview, /sandbox="allow-same-origin allow-scripts"/);
    assert.doesNotMatch(preview, /allow-forms|allow-top-navigation/);
    const guard = await fs.readFile(
        new URL(
            '../../resources/js/components/website-cms/CmsLeaveGuard.vue',
            import.meta.url,
        ),
        'utf8',
    );
    assert.match(guard, /beforeunload/);
    assert.match(guard, /event.preventDefault/);
    const summary = await fs.readFile(
        new URL(
            '../../resources/views/website/partials/vehicle-summary.blade.php',
            import.meta.url,
        ),
        'utf8',
    );
    assert.match(summary, /website-summary/);
    assert.doesNotMatch(summary, /\{!!/);
});
