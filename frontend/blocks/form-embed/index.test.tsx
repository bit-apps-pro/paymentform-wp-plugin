import type { ReactElement } from 'react';
import { describe, it, expect, vi } from 'vitest';
import { render } from '@testing-library/react';

const registerBlockType = vi.fn();
vi.mock('@wordpress/blocks', () => ({ registerBlockType }));
vi.mock('./edit', () => ({ Edit: () => null }));
vi.mock('./save', () => ({ Save: () => null }));

describe('form-embed block registration', () => {
  it('uses the PaymentForm logo as the inserter icon', async () => {
    await import('./index');

    const settings = registerBlockType.mock.calls[0]?.[1] as { icon?: { src?: ReactElement } };
    expect(settings.icon?.src).toBeDefined();
    const { container } = render(settings.icon!.src!);
    expect(container.querySelector('svg[aria-label="PaymentForm"]')).not.toBeNull();
  });
});
