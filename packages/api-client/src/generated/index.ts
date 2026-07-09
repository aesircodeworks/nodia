export type CheckResult = 'ok' | 'failed';
export type CursorPaginatedDataCollection<TKey, TValue> = CursorPaginator<TKey, TValue>;
export type CursorPaginator<TKey, TValue> = {
  data: TKey extends string ? Record<TKey, TValue> : TValue[];
  links: {
    url: string | null;
    label: string;
    active: boolean;
  }[];
  meta: {
    path: string;
    per_page: number;
    next_cursor: string | null;
    next_page_url: string | null;
    prev_cursor: string | null;
    prev_page_url: string | null;
  };
};
export type CursorPaginatorInterface<TKey, TValue> = CursorPaginator<TKey, TValue>;
export type ErrorCode =
  | 'request.not_found'
  | 'request.method_not_allowed'
  | 'request.validation_failed'
  | 'auth.unauthenticated'
  | 'auth.forbidden'
  | 'request.rate_limited'
  | 'server.internal_error'
  | 'health.degraded';
export type HealthChecksData = {
  database: CheckResult;
  redis: CheckResult;
  storage: CheckResult;
};
export type HealthDegradedProblemData = {
  checks: HealthChecksData;
  checked_at: string;
  type: string;
  title: string;
  status: number;
  detail: string;
  code: string;
  correlation_id?: string;
};
export type HealthReportData = {
  status: HealthStatus;
  checks: HealthChecksData;
  checked_at: string;
};
export type HealthStatus = 'ok';
export type LengthAwarePaginator<TKey, TValue> = {
  data: TKey extends string ? Record<TKey, TValue> : TValue[];
  links: {
    url: string | null;
    label: string;
    active: boolean;
  }[];
  meta: {
    total: number;
    current_page: number;
    first_page_url: string;
    from: number | null;
    last_page: number;
    last_page_url: string;
    next_page_url: string | null;
    path: string;
    per_page: number;
    prev_page_url: string | null;
    to: number | null;
  };
};
export type LengthAwarePaginatorInterface<TKey, TValue> = LengthAwarePaginator<TKey, TValue>;
export type Money = {
  amount: number;
  currency: string;
};
export type PaginatedDataCollection<TKey, TValue> = LengthAwarePaginator<TKey, TValue>;
export type ProblemData = {
  type: string;
  title: string;
  status: number;
  detail: string;
  code: string;
  correlation_id?: string;
};
export type ValidationProblemData = {
  errors: Record<string, string[]>;
  type: string;
  title: string;
  status: number;
  detail: string;
  code: string;
  correlation_id?: string;
};
