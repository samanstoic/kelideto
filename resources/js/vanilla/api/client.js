const TOKEN_KEY = 'keypro_api_token';

const HTTP_ERROR_CODES = new Map([
    [401, 'UNAUTHENTICATED'],
    [403, 'FORBIDDEN'],
    [409, 'CONFLICT'],
    [422, 'VALIDATION_ERROR'],
]);

export class ApiError extends Error {
    constructor(message, { status = null, code = 'HTTP_ERROR', details = null } = {}) {
        super(message);
        this.name = 'ApiError';
        this.status = status;
        this.code = code;
        this.details = details;
    }
}

function getToken() {
    try {
        return globalThis.sessionStorage?.getItem(TOKEN_KEY) ?? null;
    } catch {
        throw new ApiError('ذخیره‌ساز نشست قابل دسترسی نیست.', {
            code: 'TOKEN_STORAGE_ERROR',
        });
    }
}

function errorCodeForStatus(status) {
    if (HTTP_ERROR_CODES.has(status)) {
        return HTTP_ERROR_CODES.get(status);
    }
    return status >= 500 ? 'SERVER_ERROR' : 'HTTP_ERROR';
}

function messageFromPayload(payload, status) {
    if (payload && typeof payload === 'object' && typeof payload.message === 'string') {
        return payload.message;
    }
    if (status === 401) return 'برای ادامه باید وارد حساب کاربری شوید.';
    if (status === 403) return 'دسترسی به این عملیات مجاز نیست.';
    if (status === 409) return 'درخواست با اطلاعات ثبت‌شدهٔ قبلی تعارض دارد.';
    if (status === 422) return 'اطلاعات ارسالی معتبر نیست.';
    if (status >= 500) return 'خطای داخلی سرور رخ داده است.';
    return `درخواست با خطای HTTP ${status} مواجه شد.`;
}

export function createApiClient({
    baseUrl = '/api',
    fetchImpl = (...args) => globalThis.fetch(...args),
} = {}) {
    const normalizedBaseUrl = baseUrl.replace(/\/+$/, '');

    async function request(path, { method = 'GET', headers = {}, body, signal, responseType = 'json' } = {}) {
        const url = `${normalizedBaseUrl}/${String(path).replace(/^\/+/, '')}`;
        const requestHeaders = new Headers(headers);
        requestHeaders.set('Accept', 'application/json');
        requestHeaders.delete('Authorization');
        requestHeaders.delete('X-User-Id');

        const requestOptions = {
            method: method.toUpperCase(),
            headers: requestHeaders,
            signal,
        };

        if (body !== undefined) {
            if (globalThis.FormData && body instanceof globalThis.FormData) {
                requestHeaders.delete('Content-Type');
                requestOptions.body = body;
            } else {
                requestHeaders.set('Content-Type', 'application/json');
                requestOptions.body = JSON.stringify(body);
            }
        }

        const token = getToken();
        if (token) {
            requestHeaders.set('Authorization', `Bearer ${token}`);
        }

        let response;
        try {
            response = await fetchImpl(url, requestOptions);
        } catch (error) {
            if (error instanceof ApiError) throw error;
            throw new ApiError('ارتباط با سرور برقرار نشد. اتصال شبکه را بررسی کنید.', {
                code: 'NETWORK_ERROR',
            });
        }

        if (response.ok && responseType === 'blob') {
            return response.blob();
        }
        const responseText = await response.text();
        let payload = null;
        if (responseText) {
            try {
                payload = JSON.parse(responseText);
            } catch {
                if (response.ok) {
                    throw new ApiError('پاسخ سرور قالب JSON معتبر ندارد.', {
                        status: response.status,
                        code: 'INVALID_RESPONSE',
                    });
                }
            }
        }

        if (!response.ok) {
            throw new ApiError(messageFromPayload(payload, response.status), {
                status: response.status,
                code: errorCodeForStatus(response.status),
                details: payload?.errors ?? null,
            });
        }

        return payload;
    }

    return { request };
}

export const apiClient = createApiClient();
