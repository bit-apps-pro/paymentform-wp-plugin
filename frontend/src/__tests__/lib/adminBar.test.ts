import { afterEach, describe, expect, it } from 'vitest';
import { adminBarHeight } from '../../lib/adminBar';

describe('adminBarHeight', () => {
  afterEach(() => {
    document.getElementById('wpadminbar')?.remove();
  });

  it('returns 0 when the WP admin bar is absent', () => {
    expect(adminBarHeight()).toBe(0);
  });

  it('returns the rendered admin bar height', () => {
    const bar = document.createElement('div');
    bar.id = 'wpadminbar';
    Object.defineProperty(bar, 'offsetHeight', { value: 46 });
    document.body.appendChild(bar);

    expect(adminBarHeight()).toBe(46);
  });
});
