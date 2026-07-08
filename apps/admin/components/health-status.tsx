'use client';

import { useCallback, useEffect, useState } from 'react';
import { useTranslations } from 'next-intl';
import { ApiError, getHealth, type CheckResult, type HealthResult } from 'api-client';
import { StatusBadge } from 'ui';

type HealthState =
  | { phase: 'loading' }
  | { phase: 'loaded'; result: HealthResult }
  | { phase: 'unreachable'; message?: string };

export function HealthStatus({ baseUrl }: { baseUrl: string }) {
  const t = useTranslations('health');
  const [state, setState] = useState<HealthState>({ phase: 'loading' });

  const check = useCallback(() => {
    return getHealth({ baseUrl })
      .then((result) => setState({ phase: 'loaded', result }))
      .catch((error: unknown) =>
        setState({
          phase: 'unreachable',
          message: error instanceof ApiError ? error.message : undefined,
        }),
      );
  }, [baseUrl]);

  useEffect(() => {
    void check();
  }, [check]);

  const retry = () => {
    setState({ phase: 'loading' });
    void check();
  };

  if (state.phase === 'loading') {
    return <StatusBadge variant="unknown" label={t('loading')} />;
  }

  if (state.phase === 'unreachable') {
    return (
      <div>
        <StatusBadge variant="failed" label={t('unreachable')} />
        {state.message !== undefined && <p>{state.message}</p>}
        <button type="button" onClick={retry}>
          {t('retry')}
        </button>
      </div>
    );
  }

  if (state.result.healthy) {
    return <StatusBadge variant="ok" label={t('healthy')} />;
  }

  const failedChecks = Object.entries(state.result.problem.checks).filter(
    ([, result]: [string, CheckResult]) => result === 'failed',
  );

  return (
    <div>
      <StatusBadge variant="failed" label={t('degraded')} />
      <ul>
        {failedChecks.map(([name]) => (
          <li key={name}>{t(`checks.${name}`)}</li>
        ))}
      </ul>
      <button type="button" onClick={retry}>
        {t('retry')}
      </button>
    </div>
  );
}
