import { memo } from 'react';
import { Statistic } from 'antd';

function toEpochMs(value: number | string | null | undefined): number | null {
  if (value == null) return null;
  if (typeof value === 'number') return value < 1e12 ? value * 1000 : value;
  const parsed = Date.parse(value);
  return Number.isNaN(parsed) ? null : parsed;
}

export const ExpiresCountdown = memo(function ExpiresCountdown({
  value,
  title,
}: {
  value: number | string | null | undefined;
  title?: string;
}) {
  const ms = toEpochMs(value);
  if (ms == null) return <Statistic title={title} value="—" />;
  return <Statistic.Timer type="countdown" title={title} value={ms} format="D[d] HH:mm:ss" />;
});
