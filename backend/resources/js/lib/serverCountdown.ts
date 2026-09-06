export type ServerCountdown = {
    expiresAtMs: number;
    serverStartedAtMs: number;
    monotonicStartedAtMs: number;
};

export function createServerCountdown(
    expiresAt: string,
    serverNow: string,
    monotonicNowMs: number,
): ServerCountdown | null {
    const expiresAtMs = Date.parse(expiresAt);
    const serverStartedAtMs = Date.parse(serverNow);

    if (
        !Number.isFinite(expiresAtMs) ||
        !Number.isFinite(serverStartedAtMs) ||
        !Number.isFinite(monotonicNowMs)
    ) {
        return null;
    }

    return {
        expiresAtMs,
        serverStartedAtMs,
        monotonicStartedAtMs: monotonicNowMs,
    };
}

export function serverCountdownSeconds(
    countdown: ServerCountdown | null,
    monotonicNowMs: number,
): number {
    if (!countdown || !Number.isFinite(monotonicNowMs)) {
        return 0;
    }

    const elapsed = Math.max(
        0,
        monotonicNowMs - countdown.monotonicStartedAtMs,
    );

    return Math.max(
        0,
        Math.ceil(
            (countdown.expiresAtMs - (countdown.serverStartedAtMs + elapsed)) /
                1000,
        ),
    );
}
