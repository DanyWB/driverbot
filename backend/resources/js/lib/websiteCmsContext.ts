import type { InjectionKey, Ref } from 'vue';
import type { CmsLocale } from '@/types/websiteCms';
export const cmsContentLanguage: InjectionKey<Ref<CmsLocale>> = Symbol(
    'cms-content-language',
);
