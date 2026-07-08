import type { CSSProperties, ReactElement } from 'react';

import { cssVar, tokens } from './tokens';

export type StatusBadgeVariant = 'ok' | 'failed' | 'unknown';

export type StatusBadgeProps = {
  variant: StatusBadgeVariant;
  label: string;
};

const variantColors: Record<StatusBadgeVariant, { color: string; backgroundColor: string }> = {
  ok: {
    color: cssVar(tokens.color.statusOkForeground),
    backgroundColor: cssVar(tokens.color.statusOkBackground),
  },
  failed: {
    color: cssVar(tokens.color.statusFailedForeground),
    backgroundColor: cssVar(tokens.color.statusFailedBackground),
  },
  unknown: {
    color: cssVar(tokens.color.statusUnknownForeground),
    backgroundColor: cssVar(tokens.color.statusUnknownBackground),
  },
};

export function StatusBadge({ variant, label }: StatusBadgeProps): ReactElement {
  const style: CSSProperties = {
    ...variantColors[variant],
    display: 'inline-block',
    fontFamily: cssVar(tokens.font.family),
    fontSize: cssVar(tokens.font.sizeBadge),
    paddingInline: cssVar(tokens.spacing.badgeX),
    paddingBlock: cssVar(tokens.spacing.badgeY),
    borderRadius: cssVar(tokens.radius.badge),
  };

  return (
    <span role="status" data-variant={variant} style={style}>
      {label}
    </span>
  );
}
