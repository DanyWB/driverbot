export type BookingStatus =
    | 'process'
    | 'pending'
    | 'approved'
    | 'active'
    | 'completed'
    | 'cancelled'
    | 'cancelled_by_client'
    | 'expired'
    | 'no_show';

export type BookingSource =
    | 'telegram'
    | 'admin_phone'
    | 'admin_whatsapp'
    | 'admin_instagram'
    | 'admin_manual'
    | 'website';

export type BookingPrice = {
    final_total: number;
    calculated_total: string;
    currency: string;
    pricing_source: string;
};

export type BookingListItem = {
    public_id: string;
    status: BookingStatus;
    source: BookingSource;
    customer: {
        id: number;
        name: string;
        phone: string | null;
        telegram: string | null;
    };
    vehicle: {
        id: number;
        name: string;
        type: string;
        inventory_code: string | null;
    };
    starts_on: string;
    ends_on: string;
    pickup_time: string | null;
    return_time: string | null;
    total_days: number;
    price: BookingPrice | null;
    documents_count: number;
    client_comment: string | null;
    admin_note: string | null;
    deposit_note: string | null;
    created_at: string | null;
    updated_at: string | null;
};

export type PaginationLink = {
    url: string | null;
    label: string;
    active: boolean;
};

export type PaginatedBookings = {
    data: BookingListItem[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    from: number | null;
    to: number | null;
    links: PaginationLink[];
};

export type PriceBreakdownItem = {
    season: string;
    days: number;
    tier: string;
    package_total: number;
    anchor_days: number;
    daily_rate: string;
    subtotal: string;
};

export type PriceQuote = {
    vehicle_id: number;
    start_date: string;
    end_date: string;
    total_days: number;
    tier_key: string;
    calculated_total: string;
    rounded_total: number;
    final_total: number;
    currency: string;
    breakdown: PriceBreakdownItem[];
};

export type BookingPriceSnapshot = BookingPrice & {
    version: number;
    total_days: number;
    tier_key: string;
    rounded_total: number;
    manual_total: number | null;
    override_reason: string | null;
    breakdown: PriceBreakdownItem[];
    calculated_at: string | null;
    overridden_by_admin: { id: number; name: string } | null;
};

export type BookingDetail = BookingListItem & {
    cancellation_reason: string | null;
    no_show_reason: string | null;
    options: {
        helmets_quantity: number;
        delivery_required: boolean;
        delivery_address: string | null;
    };
    terms: {
        version: string | null;
        accepted_at: string | null;
    } | null;
    pending_expires_at: string | null;
    created_by_admin: { id: number; name: string } | null;
    price_snapshots: BookingPriceSnapshot[];
    status_history: Array<{
        id: number;
        from_status: BookingStatus | null;
        to_status: BookingStatus;
        actor_type: string;
        actor_name: string | null;
        reason: string | null;
        context: Record<string, unknown> | null;
        created_at: string | null;
    }>;
    documents: Array<{
        id: number;
        type: string;
        filename: string;
        download_url: string;
        created_at: string | null;
    }>;
    actions: Record<
        | 'approve'
        | 'activate'
        | 'complete'
        | 'cancel'
        | 'no_show'
        | 'change_dates'
        | 'edit_note'
        | 'recalculate_price'
        | 'override_price',
        boolean
    >;
};
