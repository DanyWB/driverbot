import type {
    BookingListItem,
    BookingStatus,
    PaginationLink,
} from './bookings';

export type CustomerContact = {
    id: number;
    type: string;
    value: string;
    is_primary: boolean;
    verified_at: string | null;
};

export type CustomerListItem = {
    id: number;
    name: string;
    locale: string;
    contacts: CustomerContact[];
    bookings_count: number;
    documents_count: number;
    latest_booking: {
        public_id: string;
        status: BookingStatus;
        starts_on: string | null;
        vehicle_name: string;
    } | null;
    created_at: string | null;
    updated_at: string | null;
};

export type CustomerDocument = {
    id: number;
    type: string;
    filename: string;
    mime_type: string | null;
    file_size: number | null;
    booking_public_id: string | null;
    download_url: string;
    created_at: string | null;
};

export type CustomerDetail = CustomerListItem & {
    internal_note: string | null;
    passport_number: string | null;
    identities: Array<{
        id: number;
        provider: string;
        external_id: string;
    }>;
    documents: CustomerDocument[];
};

export type PaginatedCustomers = {
    data: CustomerListItem[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    from: number | null;
    to: number | null;
    links: PaginationLink[];
};

export type PaginatedCustomerBookings = {
    data: BookingListItem[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    from: number | null;
    to: number | null;
    links: PaginationLink[];
};
