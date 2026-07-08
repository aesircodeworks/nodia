import { getTranslations } from 'next-intl/server';
import { HealthStatus } from '../components/health-status';

export default async function Home() {
  const t = await getTranslations('health');
  const apiUrl = process.env.NEXT_PUBLIC_API_URL ?? 'http://localhost:8000';

  return (
    <main className="flex flex-1 flex-col items-center justify-center gap-4">
      <h1>{t('heading')}</h1>
      <HealthStatus baseUrl={apiUrl} />
    </main>
  );
}
