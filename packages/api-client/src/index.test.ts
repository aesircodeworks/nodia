import { describe, expect, it, vi } from 'vitest';

import { ApiError, getHealth } from './index';

const healthyBody = {
  status: 'ok',
  checks: { database: 'ok', redis: 'ok', storage: 'ok' },
  checked_at: '2026-07-04T12:00:00Z',
};

const degradedBody = {
  type: '/problems/health-degraded',
  title: 'Service degraded',
  status: 503,
  detail: 'One or more backing services failed their health check.',
  code: 'health.degraded',
  checks: { database: 'ok', redis: 'failed', storage: 'ok' },
  checked_at: '2026-07-04T12:00:00Z',
};

function jsonResponse(status: number, body: unknown, contentType = 'application/json') {
  return new Response(JSON.stringify(body), {
    status,
    headers: { 'content-type': contentType },
  });
}

describe('getHealth', () => {
  it('returns the health report on 200', async () => {
    const fetch = vi.fn().mockResolvedValue(jsonResponse(200, healthyBody));

    const result = await getHealth({ baseUrl: 'http://localhost:8080', fetch });

    expect(result).toEqual({ healthy: true, report: healthyBody });
  });

  it('requests /v1/health on the configured base URL with a JSON accept header', async () => {
    const fetch = vi.fn().mockResolvedValue(jsonResponse(200, healthyBody));

    await getHealth({ baseUrl: 'http://localhost:8080', fetch });

    const [url, init] = fetch.mock.calls[0] as [URL, RequestInit];
    expect(url.toString()).toBe('http://localhost:8080/v1/health');
    expect(new Headers(init.headers).get('accept')).toBe('application/json');
  });

  it('sends the correlation ID header when provided', async () => {
    const fetch = vi.fn().mockResolvedValue(jsonResponse(200, healthyBody));

    await getHealth(
      { baseUrl: 'http://localhost:8080', fetch },
      { correlationId: '0197f7a4-0000-7000-8000-000000000000' },
    );

    const [, init] = fetch.mock.calls[0] as [URL, RequestInit];
    expect(new Headers(init.headers).get('x-correlation-id')).toBe(
      '0197f7a4-0000-7000-8000-000000000000',
    );
  });

  it('returns the problem document on a 503 health.degraded response', async () => {
    const fetch = vi
      .fn()
      .mockResolvedValue(jsonResponse(503, degradedBody, 'application/problem+json'));

    const result = await getHealth({ baseUrl: 'http://localhost:8080', fetch });

    expect(result).toEqual({ healthy: false, problem: degradedBody });
  });

  it('throws ApiError on an unexpected status', async () => {
    const fetch = vi.fn().mockResolvedValue(jsonResponse(500, { message: 'boom' }));

    await expect(getHealth({ baseUrl: 'http://localhost:8080', fetch })).rejects.toMatchObject({
      name: 'ApiError',
      status: 500,
    });
  });

  it('throws ApiError on a 503 without the health.degraded code', async () => {
    const fetch = vi
      .fn()
      .mockResolvedValue(jsonResponse(503, { code: 'maintenance' }, 'application/problem+json'));

    await expect(getHealth({ baseUrl: 'http://localhost:8080', fetch })).rejects.toBeInstanceOf(
      ApiError,
    );
  });
});
