export type TimelineOccupancyStatus =
    'pending' | 'approved' | 'active' | 'maintenance';

export type TimelineDate = {
    date: string;
    day: number;
    weekday: string;
    month: string;
    is_weekend: boolean;
    is_today: boolean;
};

export type TimelineOccupancy = {
    id: number;
    type: 'booking' | 'maintenance';
    status: TimelineOccupancyStatus;
    label: string;
    starts_on: string;
    ends_on: string;
    start_index: number;
    span_days: number;
    continues_before: boolean;
    continues_after: boolean;
    booking_public_id: string | null;
};

export type TimelineVehicle = {
    id: number;
    name: string;
    type: string;
    inventory_code: string | null;
    is_active: boolean;
    is_visible: boolean;
    category: { id: number; name: string } | null;
    occupancies: TimelineOccupancy[];
};

export type TimelineData = {
    range: {
        starts_on: string;
        ends_on: string;
        days: number;
        today: string;
    };
    dates: TimelineDate[];
    rows: TimelineVehicle[];
    stats: {
        vehicles: number;
        occupied: number;
        available: number;
        occupancies: number;
    };
};

export type TimelineFilters = {
    starts_on: string;
    ends_on: string;
    vehicle_type: string;
    category_id: number | null;
    vehicle_id: number | null;
    visibility: string;
    status: string;
    available_only: boolean;
};

export type TimelineOptions = {
    vehicle_types: string[];
    statuses: TimelineOccupancyStatus[];
    categories: Array<{
        id: number;
        name: string;
        vehicle_type: string;
    }>;
    vehicles: Array<{
        id: number;
        name: string;
        type: string;
        category_id: number | null;
        is_active: boolean;
        is_visible: boolean;
    }>;
};
