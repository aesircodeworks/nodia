export const tokens = {
  color: {
    statusOkForeground: '--nodia-color-status-ok-foreground',
    statusOkBackground: '--nodia-color-status-ok-background',
    statusFailedForeground: '--nodia-color-status-failed-foreground',
    statusFailedBackground: '--nodia-color-status-failed-background',
    statusUnknownForeground: '--nodia-color-status-unknown-foreground',
    statusUnknownBackground: '--nodia-color-status-unknown-background',
  },
  font: {
    family: '--nodia-font-family',
    sizeBadge: '--nodia-font-size-badge',
  },
  spacing: {
    badgeX: '--nodia-spacing-badge-x',
    badgeY: '--nodia-spacing-badge-y',
  },
  radius: {
    badge: '--nodia-radius-badge',
  },
} as const;

export type TokenName =
  | (typeof tokens.color)[keyof typeof tokens.color]
  | (typeof tokens.font)[keyof typeof tokens.font]
  | (typeof tokens.spacing)[keyof typeof tokens.spacing]
  | (typeof tokens.radius)[keyof typeof tokens.radius];

export type TokenSet = Record<TokenName, string>;

// Neutral defaults. Tenant branding swaps these values at the theme root;
// components reference tokens only, never these literals.
export const defaultTokens: TokenSet = {
  [tokens.color.statusOkForeground]: '#0a5c36',
  [tokens.color.statusOkBackground]: '#d9f2e5',
  [tokens.color.statusFailedForeground]: '#7f1d1d',
  [tokens.color.statusFailedBackground]: '#fde4e4',
  [tokens.color.statusUnknownForeground]: '#3f3f46',
  [tokens.color.statusUnknownBackground]: '#e4e4e7',
  [tokens.font.family]:
    'system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif',
  [tokens.font.sizeBadge]: '0.875rem',
  [tokens.spacing.badgeX]: '0.75rem',
  [tokens.spacing.badgeY]: '0.25rem',
  [tokens.radius.badge]: '9999px',
};

export function cssVar(token: TokenName): string {
  return `var(${token})`;
}

export function tokenSetToCss(tokenSet: TokenSet): string {
  return Object.entries(tokenSet)
    .map(([name, value]) => `${name}: ${value};`)
    .join('\n');
}
