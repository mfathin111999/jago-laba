/** Error raised for a refused or failed request; the message is safe to show to the user. */
export class ApiError extends Error {
    readonly status: number;
    readonly code: string | null;

    constructor(message: string, status: number, code: string | null = null) {
        super(message);
        this.name = 'ApiError';
        this.status = status;
        this.code = code;
    }
}

export type ApiResult<T = Record<string, any>> = { success?: boolean; ok?: boolean; message?: string; data?: T } & Record<string, any>;

type Fetcher = typeof fetch;

export interface ApiOptions {
    method?: 'POST' | 'PUT' | 'PATCH' | 'DELETE' | 'GET';
    /** Extra headers, e.g. X-Idempotency-Key. */
    headers?: Record<string, string>;
    csrf?: string;
    fetcher?: Fetcher;
}

export function csrfToken(): string {
    return typeof document === 'undefined' ? '' : (document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '');
}

/** A new random key for one user action; reuse it only to retry that same action. */
export function newIdempotencyKey(): string {
    return globalThis.crypto?.randomUUID?.() ?? `jl-${Date.now()}-${Math.random().toString(16).slice(2)}`;
}

/** The server answers with several error shapes (legacy `message`, RPC `error.message`); return the readable one. */
export function errorMessage(body: Record<string, any> | null, status: number): string {
    const message = body?.message ?? body?.error?.message;
    if (typeof message === 'string' && message.trim() !== '') {
        return message;
    }
    if (status === 401) return 'Sesi login berakhir. Silakan login kembali.';
    if (status === 403) return 'Aksi tidak diizinkan atau token keamanan tidak valid. Muat ulang halaman.';
    if (status === 419) return 'Token keamanan kedaluwarsa. Muat ulang halaman.';
    if (status === 422) return 'Data yang dikirim belum valid.';
    if (status === 503) return 'Fitur belum tersedia di server.';

    return `Permintaan belum dapat diproses (HTTP ${status}).`;
}

/**
 * Sends a JSON or form request and returns the parsed body. Anything that is not a clear success throws an
 * {@link ApiError}, so callers never treat a refusal as success.
 */
export async function apiRequest<T = Record<string, any>>(url: string, body?: unknown, options: ApiOptions = {}): Promise<ApiResult<T>> {
    const fetcher = options.fetcher ?? fetch;
    const isForm = typeof FormData !== 'undefined' && body instanceof FormData;
    const headers: Record<string, string> = {
        Accept: 'application/json',
        'X-CSRF-TOKEN': options.csrf ?? csrfToken(),
        'X-Requested-With': 'XMLHttpRequest',
        ...(isForm || body === undefined ? {} : { 'Content-Type': 'application/json' }),
        ...(options.headers ?? {}),
    };

    let response: Response;
    try {
        response = await fetcher(url, {
            method: options.method ?? 'POST',
            headers,
            credentials: 'same-origin',
            body: body === undefined ? undefined : isForm ? (body as FormData) : JSON.stringify(body),
        });
    } catch {
        throw new ApiError('Tidak dapat menghubungi server. Periksa koneksi lalu coba lagi.', 0);
    }

    let parsed: Record<string, any> | null = null;
    try {
        parsed = await response.json();
    } catch {
        parsed = null;
    }

    const refused = parsed !== null && (parsed.success === false || parsed.ok === false);
    if (!response.ok || refused || parsed === null) {
        throw new ApiError(errorMessage(parsed, response.status), response.status, parsed?.code ?? parsed?.error?.code ?? parsed?.error_code ?? null);
    }

    return parsed as ApiResult<T>;
}
