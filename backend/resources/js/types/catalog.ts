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
    sort_order?: number;
    linked_vehicles_count: number;
    linked_vehicle_ids: number[];
    prices: PricingPresetPrices;
    calculation_settings?: PricingCalculationSettings | null;
    created_at: string | null;
    updated_at: string | null;
};

export type PricingCalculationSettings = {
    algorithm: 'calculator-v1';
    rules: {
        step: number;
        mode: 'linear' | 'delayed';
        holdDays: number;
        cap: boolean;
        monthly: boolean;
        monthlyStart: number;
        avg: boolean;
    };
    prefs: {
        linkMiddle: boolean;
        linkLow: boolean;
        roundSteps: Record<'high' | 'middle' | 'low', number[]>;
        discounts: Record<'middle' | 'low', number[]>;
        seasonCalendar: ('high' | 'middle' | 'low')[];
    };
    dayOverrides: Record<string, number>;
};

export type PricingFleetVehicle = {
    id: number;
    external_code: string;
    inventory_code: string | null;
    name: string;
    year: number | null;
    type: string;
    category: string | null;
    is_visible_for_booking: boolean;
    pricing_preset_id: number | null;
    preset_name: string | null;
    pricing_revision: number;
    algorithm: 'legacy' | 'calculator-v1';
    prices: Record<
        'high' | 'middle' | 'low',
        Record<'1d' | '7d' | '14d' | '21d' | 'month', VehiclePriceCell>
    >;
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
