import { describe, expect, it } from 'vitest';
import { getGutenbergBlock } from '../../features/forms/embedUrl';

const FORM_ID = '3576ba7c-7f35-41d8-9e22-dbb370a285c8';

describe('getGutenbergBlock', () => {
  it("matches the block's saved markup, so a paste is a valid block, not one needing recovery", () => {
    // Captured from wp.blocks.serialize() of the registered block on WordPress 7.1.
    const serialized =
      `<!-- wp:paymentform/form {"formId":"${FORM_ID}"} -->\n` +
      `<div class="wp-block-paymentform-form paymentform-embed" data-form-id="${FORM_ID}"></div>\n` +
      '<!-- /wp:paymentform/form -->';

    expect(getGutenbergBlock(FORM_ID)).toBe(serialized);
  });

  it('carries the embed placeholder the front-end view script mounts into', () => {
    const doc = new DOMParser().parseFromString(
      getGutenbergBlock(FORM_ID),
      'text/html',
    );

    expect(
      doc.querySelector(`.paymentform-embed[data-form-id="${FORM_ID}"]`),
    ).not.toBeNull();
  });
});
