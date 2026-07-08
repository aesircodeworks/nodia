import { NextIntlClientProvider } from 'next-intl';
import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';

import messages from '../messages/en.json';
import { HealthStatus } from '../components/health-status';

function renderHealthStatus() {
  return render(
    <NextIntlClientProvider locale="en" messages={messages}>
      <HealthStatus baseUrl="http://localhost:8000" />
    </NextIntlClientProvider>,
  );
}

function stubHealthResponse(status: number, body: unknown) {
  vi.stubGlobal(
    'fetch',
    vi.fn().mockResolvedValue(
      new Response(JSON.stringify(body), {
        status,
        headers: { 'content-type': 'application/json' },
      }),
    ),
  );
}

afterEach(() => {
  cleanup();
  vi.unstubAllGlobals();
});

describe('HealthStatus', () => {
  it('shows the healthy badge when the API reports ok', async () => {
    stubHealthResponse(200, {
      status: 'ok',
      checks: { database: 'ok', redis: 'ok', storage: 'ok' },
      checked_at: '2026-07-04T12:00:00Z',
    });

    renderHealthStatus();

    expect(await screen.findByText(messages.health.healthy)).toBeDefined();
    expect(screen.getByRole('status')).toBeDefined();
  });

  it('shows the degraded badge and names the failed check on 503', async () => {
    stubHealthResponse(503, {
      type: '/problems/health-degraded',
      title: 'Service degraded',
      status: 503,
      code: 'health.degraded',
      checks: { database: 'ok', redis: 'failed', storage: 'ok' },
      checked_at: '2026-07-04T12:00:00Z',
    });

    renderHealthStatus();

    expect(await screen.findByText(messages.health.degraded)).toBeDefined();
    expect(screen.getByText(messages.health.checks.redis)).toBeDefined();
    expect(screen.queryByText(messages.health.checks.database)).toBeNull();
    expect(screen.getByRole('button', { name: messages.health.retry })).toBeDefined();
  });

  it('shows the unreachable state with a retry action when the request fails', async () => {
    vi.stubGlobal('fetch', vi.fn().mockRejectedValue(new TypeError('fetch failed')));

    renderHealthStatus();

    expect(await screen.findByText(messages.health.unreachable)).toBeDefined();
    expect(screen.getByRole('button', { name: messages.health.retry })).toBeDefined();
  });
});
