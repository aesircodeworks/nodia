import { useCallback, useEffect, useState } from 'react';
import { getHealth, type CheckResult, type HealthResult } from 'api-client';
import { StatusBadge } from 'ui';

import { t } from './i18n';

type HealthState =
  | { phase: 'loading' }
  | { phase: 'loaded'; result: HealthResult }
  | { phase: 'unreachable' };

export function HealthStatus({ baseUrl }: { baseUrl: string }) {
  const [state, setState] = useState<HealthState>({ phase: 'loading' });

  const check = useCallback(() => {
    return getHealth({ baseUrl })
      .then((result) => setState({ phase: 'loaded', result }))
      .catch(() => setState({ phase: 'unreachable' }));
  }, [baseUrl]);

  useEffect(() => {
    void check();
  }, [check]);

  const retry = () => {
    setState({ phase: 'loading' });
    void check();
  };

  if (state.phase === 'loading') {
    return <StatusBadge variant="unknown" label={t('health.loading')} />;
  }

  if (state.phase === 'unreachable') {
    return (
      <div>
        <StatusBadge variant="failed" label={t('health.unreachable')} />
        <button type="button" onClick={retry}>
          {t('health.retry')}
        </button>
      </div>
    );
  }

  if (state.result.healthy) {
    return <StatusBadge variant="ok" label={t('health.healthy')} />;
  }

  const failedChecks = (
    Object.entries(state.result.problem.checks) as [
      'database' | 'redis' | 'storage',
      CheckResult,
    ][]
  ).filter(([, result]) => result === 'failed');

  return (
    <div>
      <StatusBadge variant="failed" label={t('health.degraded')} />
      <ul>
        {failedChecks.map(([name]) => (
          <li key={name}>{t(`health.checks.${name}`)}</li>
        ))}
      </ul>
      <button type="button" onClick={retry}>
        {t('health.retry')}
      </button>
    </div>
  );
}
