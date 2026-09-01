/**
 * Shapes mirror `App\Support\ApiResponse` exactly. Note the backend only
 * guarantees this envelope for errors raised through a FormRequest override
 * or an explicit `ApiResponse::error()` call — anything else (implicit 404s,
 * route-not-found, throttling, uncaught 500s) falls through to Laravel's raw
 * default JSON. `ApiClientError` normalizes both cases; never assume
 * `error_code` is present.
 */
export interface ApiSuccessEnvelope<T> {
  success: true;
  message: string;
  data: T;
  meta?: unknown;
}

export interface ApiErrorEnvelope {
  success?: false;
  message: string;
  error_code?: string;
  errors?: Record<string, string[]>;
}

/** Hand-rolled pagination meta used by list endpoints (customers, timeline, notes, attachments). */
export interface PageMeta {
  current_page: number;
  per_page: number;
  total: number;
  last_page: number;
}

export interface PagedResult<T> {
  data: T[];
  meta: PageMeta;
}
