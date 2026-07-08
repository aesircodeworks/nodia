import { renderToStaticMarkup } from 'react-dom/server';
import { describe, expect, it } from 'vitest';

import { StatusBadge } from './status-badge';
import { defaultTokens, tokenSetToCss, tokens } from './tokens';

describe('StatusBadge', () => {
  it('renders the provided label as a status element', () => {
    const html = renderToStaticMarkup(<StatusBadge variant="ok" label="Backend healthy" />);

    expect(html).toContain('role="status"');
    expect(html).toContain('Backend healthy');
    expect(html).toContain('data-variant="ok"');
  });

  it('styles exclusively through design tokens, never literal colors', () => {
    for (const variant of ['ok', 'failed', 'unknown'] as const) {
      const html = renderToStaticMarkup(<StatusBadge variant={variant} label="label" />);

      expect(html).not.toMatch(/#[0-9a-fA-F]{3,8}\b/);
      expect(html).not.toMatch(/rgb\(/);
      expect(html).toContain('var(--nodia-color-status-');
    }
  });
});

describe('tokens', () => {
  it('provides a default value for every token', () => {
    const names = Object.values(tokens).flatMap((group) => Object.values(group));

    for (const name of names) {
      expect(defaultTokens[name]).toBeTruthy();
    }
  });

  it('serializes a token set to CSS declarations', () => {
    const css = tokenSetToCss(defaultTokens);

    expect(css).toContain('--nodia-color-status-ok-foreground:');
    expect(css).toContain('--nodia-radius-badge:');
  });
});
