import type { HealthChecksData, HealthReportData } from './generated';

export type * from './generated';

export type HealthDegradedProblem = {
  type: string;
  title: string;
  status: number;
  detail?: string;
  code: 'health.degraded';
  checks: HealthChecksData;
  checked_at: string;
};

export type HealthResult =
  | { healthy: true; report: HealthReportData }
  | { healthy: false; problem: HealthDegradedProblem };

export class ApiError extends Error {
  constructor(
    message: string,
    public readonly status: number,
  ) {
    super(message);
    this.name = 'ApiError';
  }
}

export type ApiClientOptions = {
  baseUrl: string;
  fetch?: typeof globalThis.fetch;
};

export type RequestOptions = {
  correlationId?: string;
  signal?: AbortSignal;
};

export async function getHealth(
  client: ApiClientOptions,
  options: RequestOptions = {},
): Promise<HealthResult> {
  const fetchImpl = client.fetch ?? globalThis.fetch;
  const headers = new Headers({ accept: 'application/json' });
  if (options.correlationId !== undefined) {
    headers.set('x-correlation-id', options.correlationId);
  }

  const response = await fetchImpl(new URL('/v1/health', client.baseUrl), {
    headers,
    signal: options.signal,
  });

  if (response.status === 200) {
    return { healthy: true, report: (await response.json()) as HealthReportData };
  }

  if (response.status === 503) {
    const problem = (await response.json()) as HealthDegradedProblem;
    if (problem.code === 'health.degraded') {
      return { healthy: false, problem };
    }
  }

  throw new ApiError(`Unexpected response from GET /v1/health: ${response.status}`, response.status);
}
