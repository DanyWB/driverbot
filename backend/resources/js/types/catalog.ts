import type { PaginationLink } from './bookings';

export type VehiclePhoto = {
    id: number;
    url: string;
    thumbnail_url: string;
    alt_text: string | null;
    sort_order: number;
    is_primary: boolean;
};

export type CategoryOption = {
    id: number;
    name: string;
    type: string | null;
    is_active: boolean;
};

export type VehicleListItem = {
    archived_at: string | null;
    unfinished_bookings_count: number;
    can_delete: boolean;
    id: number;
    external_code: string;
    name: string;
    type: string;
    inventory_code: string | null;
    year: number | null;
    category: {
        id: number;
        name: string;
        is_active: boolean;
    } | null;
    is_active: boolean;
    is_visible_for_booking: boolean;
    sort_order: number;
    active_price_tiers_count: number;
    has_complete_pricing: boolean;
    photos_count: number;
    bookings_count: number;
    primary_photo: VehiclePhoto | null;
    updated_at: string | null;
};

export type VehiclePriceCell = {
    package_total: number | null;
    daily_rate: string | null;
    is_active: boolean;
    updated_at: string | null;
};

export type PricingTemplateOption = {
    key: string;
    label: string;
};

export type PricingPresetVehicleType = 'scooter' | 'car' | null;

export type PricingPresetPrices = Record<
    'high' | 'middle' | 'low',
    Record<'1d' | '7d' | '14d' | '21d' | 'month', number>
>;

export type PricingPresetSummary = {
    id: number;
    name: string;
    vehicle_type: PricingPresetVehicleType;
    version: number;
};

export type PricingPreset = PricingPresetSummary & {
    linked_vehicles_count: number;
    linked_vehicle_ids: number[];
    prices: PricingPresetPrices;
    created_at: string | null;
    updated_at: string | null;
};

export type VehicleDetail = VehicleListItem & {
    description: string | null;
    description_ru: string | null;
    description_ua: string | null;
    characteristics_text: string | null;
    emoji: string | null;
    pricing_profile: string | null;
    pricing_preset: PricingPresetSummary | null;
    pricing_revision: number;
    photos: VehiclePhoto[];
    pricing: Record<string, Record<string, VehiclePriceCell>>;
};

export type CategoryItem = {
    id: number;
    code: string;
    name: string;
    vehicle_type: string | null;
    description: string | null;
    sort_order: number;
    is_active: boolean;
    vehicles_count: number;
    visible_vehicles_count: number;
};

export type PaginatedVehicles = {
    data: VehicleListItem[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    from: number | null;
    to: number | null;
    links: PaginationLink[];
};
