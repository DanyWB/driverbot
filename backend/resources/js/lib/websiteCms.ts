import type {
    CmsBlock,
    CmsBlockType,
    CmsItem,
    LocaleText,
} from '@/types/websiteCms';

export function emptyLocaleText(): LocaleText {
    return { en: '', ru: '', uk: '' };
}
export function cloneCms<T>(value: T): T {
    return JSON.parse(JSON.stringify(value)) as T;
}
/** Missing translations remain absent; Laravel must never receive empty strings converted to null. */
export function cmsTransport<T>(value: T): T {
    if (Array.isArray(value)) {
        return value.map((item) => cmsTransport(item)) as T;
    }

    if (value !== null && typeof value === 'object') {
        return Object.fromEntries(
            Object.entries(value)
                .filter(
                    ([key, item]) =>
                        !(
                            ['en', 'ru', 'uk'].includes(key) &&
                            typeof item === 'string' &&
                            item.trim() === ''
                        ),
                )
                .map(([key, item]) => [key, cmsTransport(item)]),
        ) as T;
    }

    return value;
}
export function cmsDirty(value: unknown, saved: unknown): boolean {
    return JSON.stringify(value) !== JSON.stringify(saved);
}
export function moveCmsItem<T>(
    items: T[],
    index: number,
    direction: -1 | 1,
): T[] {
    const result = [...items];
    const target = index + direction;

    if (
        index < 0 ||
        index >= result.length ||
        target < 0 ||
        target >= result.length
    ) {
        return result;
    }

    const [item] = result.splice(index, 1);
    result.splice(target, 0, item);

    return result;
}
export function newCmsItem(
    type: 'steps' | 'faq',
    id = crypto.randomUUID(),
): CmsItem {
    return type === 'steps'
        ? { id, title: emptyLocaleText(), body: emptyLocaleText() }
        : { id, question: emptyLocaleText(), answer: emptyLocaleText() };
}
export function newCmsBlock(
    type: CmsBlockType,
    id = crypto.randomUUID(),
): CmsBlock {
    const props: CmsBlock['props'] = { heading: emptyLocaleText() };

    if (['hero', 'text_image', 'contact_cta'].includes(type)) {
        props.body = emptyLocaleText();
    }

    if (type === 'hero' || type === 'text_image') {
        props.image_ref = null;
    }

    if (type === 'hero') {
        props.action = 'scooters';
        props.action_label = emptyLocaleText();
    }

    if (type === 'catalog') {
        props.vehicle_type = 'all';
        props.limit = 6;
    }

    if (type === 'steps' || type === 'faq') {
        props.items = [];
    }

    if (type === 'contact_cta') {
        props.channel = 'telegram';
        props.action_label = emptyLocaleText();
    }

    return { id, type, props };
}
