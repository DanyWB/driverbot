export type CmsLocale = 'en' | 'ru' | 'uk';
export type LocaleText = Record<CmsLocale, string>;
export type CmsActions = {
    draft: string;
    publish: string;
    unpublish: string;
    preview?: Record<CmsLocale, string>;
    upload?: string;
};
export type CmsCapabilities = { edit: boolean; publish: boolean };
export type CmsPublication = {
    revision: number;
    published_revision: number | null;
    published_at: string | null;
    has_unpublished_changes?: boolean;
};
export type CardContent = {
    schema_version: number;
    title: LocaleText;
    summary: LocaleText;
    meta_title: LocaleText;
    meta_description: LocaleText;
    image_alt: LocaleText;
    cover_asset_id?: string | null;
};
export type WebsiteMedia = {
    id: string;
    kind: 'photo' | 'artwork';
    width: number;
    height: number;
    alt_text: LocaleText;
    referenced_by_draft: boolean;
    referenced_by_published: boolean;
    variants: Record<
        'card' | 'card_2x' | 'detail',
        { url: string; width: number; height: number }
    >;
};
export type CmsBlockType =
    'hero' | 'catalog' | 'text_image' | 'steps' | 'faq' | 'contact_cta';
export type CmsItem = {
    id: string;
    title?: LocaleText;
    body?: LocaleText;
    question?: LocaleText;
    answer?: LocaleText;
};
export type CmsBlock = {
    id: string;
    type: CmsBlockType;
    props: {
        heading: LocaleText;
        body?: LocaleText;
        image_ref?: string | null;
        action?: 'cars' | 'scooters';
        action_label?: LocaleText;
        vehicle_type?: 'car' | 'scooter' | 'all';
        limit?: number;
        items?: CmsItem[];
        channel?: 'telegram' | 'whatsapp' | 'email';
    };
};
export type PageContent = {
    schema_version: number;
    title: LocaleText;
    meta_title: LocaleText;
    meta_description: LocaleText;
    blocks: CmsBlock[];
};
export type StaticAsset = {
    id: string;
    url: string;
    width: number;
    height: number;
    alt: string;
};
export type SettingsContent = {
    schema_version: number;
    business: {
        name: string | null;
        legal_name: string | null;
        address: string | null;
        telephone: string | null;
    };
    contacts: {
        telegram: string | null;
        whatsapp: string | null;
        email: string | null;
        maps: string | null;
    };
    navigation: string[];
    footer_note: LocaleText;
};
