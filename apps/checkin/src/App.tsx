import { defaultTokens, tokenSetToCss } from 'ui';

import { HealthStatus } from './health-status';
import { t } from './i18n';

const apiUrl = import.meta.env.VITE_API_URL ?? 'http://localhost:8000';
const tokenCss = `:root {\n${tokenSetToCss(defaultTokens)}\n}`;

function App() {
  return (
    <main>
      <style>{tokenCss}</style>
      <h1>{t('health.heading')}</h1>
      <HealthStatus baseUrl={apiUrl} />
    </main>
  );
}

export default App;
