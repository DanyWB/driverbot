export type VehicleSpecifications = {
    brand: string | null;
    model: string | null;
    engine_cc: number | null;
    color: string | null;
    abs: boolean | null;
    seats: number | null;
    transmission: 'automatic' | 'manual' | 'semi_automatic' | null;
};

export type VehicleSpecificationFields = {
    brand: string;
    model: string;
    engine_cc: number | string;
    color: string;
    abs: number | null;
    seats: number | string;
    transmission: string | null;
};

export function vehicleSpecificationFields(
    source?: Partial<VehicleSpecifications>,
): VehicleSpecificationFields {
    return {
        brand: source?.brand ?? '',
        model: source?.model ?? '',
        engine_cc: source?.engine_cc ?? '',
        color: source?.color ?? '',
        abs: source?.abs == null ? null : source.abs ? 1 : 0,
        seats: source?.seats ?? '',
        transmission: source?.transmission ?? null,
    };
}

function blankToNull(value: string | number | null): string | number | null {
    return typeof value === 'string' ? value.trim() || null : value;
}

export function vehicleSpecificationPayload(
    fields: VehicleSpecificationFields,
) {
    return {
        brand: blankToNull(fields.brand),
        model: blankToNull(fields.model),
        engine_cc: blankToNull(fields.engine_cc),
        color: blankToNull(fields.color),
        abs: fields.abs === null ? null : fields.abs === 1,
        seats: blankToNull(fields.seats),
        transmission: blankToNull(fields.transmission),
    };
}
