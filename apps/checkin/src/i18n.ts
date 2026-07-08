export const locale = 'en';

const catalogs = {
  en: {
    'app.title': 'Nodia Check-in',
    'health.heading': 'Backend status',
    'health.loading': 'Checking backend status',
    'health.healthy': 'Backend healthy',
    'health.degraded': 'Backend degraded',
    'health.unreachable': 'Backend unreachable',
    'health.retry': 'Retry',
    'health.checks.database': 'Database',
    'health.checks.redis': 'Redis',
    'health.checks.storage': 'Storage',
  },
} as const;

export type MessageKey = keyof (typeof catalogs)[typeof locale];

export function t(key: MessageKey): string {
  return catalogs[locale][key];
}
