import type { ApiErrorEnvelope, ApiSuccessEnvelope } from '@/types/api';

const API_BASE_URL = import.meta.env.VITE_API_BASE_URL;
const TOKEN_STORAGE_KEY = 'fayadhowr.admin.token';

export function getStoredToken(): string | null {
  try {
    return localStorage.getItem(TOKEN_STORAGE_KEY);
  } catch {
    return null;
  }
}

export function setStoredToken(token: string | null): void {
  try {
    if (token) {
      localStorage.setItem(TOKEN_STORAGE_KEY, token);
    } else {
      localStorage.removeItem(TOKEN_STORAGE_KEY);
    }
  } catch {
    /* localStorage unavailable (private mode, disabled storage) — auth simply won't persist */
  }
}

/**
 * Normalized client-side error. The backend's `{success, message, error_code}`
 * envelope is only guaranteed for errors raised through a FormRequest override
 * or an explicit `ApiResponse::error()` call — implicit 404s, route-not-found,
 * 429 throttling and uncaught 500s fall through to Laravel's raw default JSON
 * (`{message, errors?}`, no `success`/`error_code`). This class absorbs both.
 */
export class ApiClientError extends Error {
  readonly status: number;
  readonly code?: string;
  readonly fieldErrors?: Record<string, string[]>;

  constructor(status: number, message: string, code?: string, fieldErrors?: Record<string, string[]>) {
    super(message);
    this.name = 'ApiClientError';
    this.status = status;
    this.code = code;
    this.fieldErrors = fieldErrors;
  }

  /** 401 = not authenticated (missing/expired token). Distinct from 403 = authenticated but forbidden. */
  get isUnauthenticated(): boolean {
    return this.status === 401;
  }

  /** 403 = logged in, but blocked (inactive account, or missing permission). */
  get isForbidden(): boolean {
    return this.status === 403;
  }

  get isValidation(): boolean {
    return this.status === 422;
  }
}

export type UnauthenticatedListener = () => void;
let onUnauthenticated: UnauthenticatedListener | null = null;

/** Wired once by AuthProvider so the client can force a logout on a 401 from anywhere in the app. */
export function registerUnauthenticatedHandler(listener: UnauthenticatedListener | null): void {
  onUnauthenticated = listener;
}

interface RequestOptions {
  method?: 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE';
  query?: Record<string, string | number | boolean | undefined | null>;
  body?: unknown;
  /** Send FormData as-is (file uploads) instead of JSON-encoding `body`. */
  isFormData?: boolean;
  /** Skip attaching the bearer token — only the login endpoint needs this. */
  skipAuth?: boolean;
  signal?: AbortSignal;
  /**
   * Always return the raw Response, even when the server sends
   * `content-type: application/json` — for endpoints that stream a JSON FILE
   * (e.g. a backup snapshot) rather than the `{success, data}` envelope. The
   * default content-type sniff in `performRequest` would otherwise try to
   * parse the file body as that envelope.
   */
  raw?: boolean;
}

function buildUrl(path: string, query?: RequestOptions['query']): string {
  const url = new URL(`${API_BASE_URL.replace(/\/$/, '')}/${path.replace(/^\//, '')}`);
  if (query) {
    for (const [key, value] of Object.entries(query)) {
      if (value !== undefined && value !== null && value !== '') {
        url.searchParams.set(key, String(value));
      }
    }
  }
  return url.toString();
}

async function parseErrorResponse(response: Response): Promise<ApiClientError> {
  let body: ApiErrorEnvelope | undefined;
  try {
    body = (await response.json()) as ApiErrorEnvelope;
  } catch {
    // response had no JSON body at all
  }

  const message = body?.message ?? `Request failed with status ${response.status}.`;
  return new ApiClientError(response.status, message, body?.error_code, body?.errors);
}

async function performRequest<T>(path: string, options: RequestOptions): Promise<Response | ApiSuccessEnvelope<T>> {
  const { method = 'GET', query, body, isFormData, skipAuth, signal, raw } = options;

  const headers: Record<string, string> = { Accept: 'application/json' };
  if (!isFormData) {
    headers['Content-Type'] = 'application/json';
  }
  if (!skipAuth) {
    const token = getStoredToken();
    if (token) {
      headers.Authorization = `Bearer ${token}`;
    }
  }

  const response = await fetch(buildUrl(path, query), {
    method,
    headers,
    body: body === undefined ? undefined : isFormData ? (body as FormData) : JSON.stringify(body),
    signal,
  });

  if (!response.ok) {
    const error = await parseErrorResponse(response);
    if (error.isUnauthenticated) {
      onUnauthenticated?.();
    }
    throw error;
  }

  if (raw) {
    return response;
  }

  if (response.status === 204) {
    return { success: true, message: '', data: undefined as T };
  }

  const contentType = response.headers.get('content-type') ?? '';
  if (!contentType.includes('application/json')) {
    // e.g. the customer-attachment download endpoint returns a binary stream
    return response;
  }

  return (await response.json()) as ApiSuccessEnvelope<T>;
}

/** Low-level request used by every resource module in `src/api/`. Returns just `data`. */
export async function apiRequest<T>(path: string, options: RequestOptions = {}): Promise<T> {
  const result = await performRequest<T>(path, options);
  return result instanceof Response ? (result as unknown as T) : result.data;
}

/**
 * Like `apiRequest`, but always returns the raw `Response` — for endpoints
 * that stream a file whose content-type happens to be `application/json`
 * (a JSON snapshot file, not the `{success, data}` envelope). Use `apiRequest`
 * for every other binary download; it already returns the raw Response for
 * any non-JSON content-type.
 */
export async function apiRequestRaw(path: string, options: RequestOptions = {}): Promise<Response> {
  const result = await performRequest<never>(path, { ...options, raw: true });
  return result as Response;
}

/** Same as `apiRequest`, but also returns `meta` — needed by hand-rolled-pagination list endpoints. */
export async function apiRequestWithMeta<T>(
  path: string,
  options: RequestOptions = {},
): Promise<{ data: T; meta: unknown }> {
  const result = await performRequest<T>(path, options);
  if (result instanceof Response) {
    throw new ApiClientError(0, 'Expected a JSON envelope but received a binary response.');
  }
  return { data: result.data, meta: result.meta };
}
