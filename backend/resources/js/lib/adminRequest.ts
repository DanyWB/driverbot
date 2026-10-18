export function csrfHeaders(): Record<string, string> {
    const token = document
        .querySelector<HTMLMetaElement>('meta[name="csrf-token"]')
        ?.content.trim();

    if (token) {
        return { 'X-CSRF-TOKEN': token };
    }

    const cookie = document.cookie
        .split('; ')
        .find((item) => item.startsWith('XSRF-TOKEN='))
        ?.slice(11);

    return cookie ? { 'X-XSRF-TOKEN': decodeURIComponent(cookie) } : {};
}

export async function adminRequest<T = unknown>(
    url: string,
    method = 'GET',
    body?: unknown,
): Promise<{ data: T; message?: string }> {
    const response = await fetch(url, {
        method,
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            ...csrfHeaders(),
        },
        ...(body === undefined ? {} : { body: JSON.stringify(body) }),
    });
    const result = await response.json().catch(() => ({}));

    if (!response.ok) {
        const messages = Object.values(result.errors ?? {})
            .flat()
            .join('\n');

        throw new Error(
            messages || result.message || `HTTP ${response.status}`,
        );
    }

    return result;
}
