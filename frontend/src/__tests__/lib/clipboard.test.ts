import { afterEach, describe, expect, it, vi } from 'vitest';
import { copyText } from '../../lib/clipboard';

/** Replaces navigator.clipboard for one test; undefined mimics an insecure (http) context. */
function setClipboard(value: Partial<Clipboard> | undefined): void {
  vi.stubGlobal('navigator', Object.create(navigator, { clipboard: { value } }));
}

describe('copyText', () => {
  afterEach(() => {
    vi.unstubAllGlobals();
  });

  it('uses the async clipboard API when available', async () => {
    const writeText = vi.fn().mockResolvedValue(undefined);
    setClipboard({ writeText });

    await copyText('hello');

    expect(writeText).toHaveBeenCalledWith('hello');
  });

  it('falls back to execCommand when the clipboard API is missing', async () => {
    setClipboard(undefined);
    let copied: string | null = null;
    document.execCommand = vi.fn(() => {
      copied = (document.activeElement as HTMLTextAreaElement).value;
      return true;
    });

    await copyText('fallback');

    expect(copied).toBe('fallback');
    expect(document.querySelector('textarea')).toBeNull();
  });

  it('rejects when the fallback copy is refused', async () => {
    setClipboard(undefined);
    document.execCommand = vi.fn(() => false);

    await expect(copyText('x')).rejects.toThrow();
  });
});
