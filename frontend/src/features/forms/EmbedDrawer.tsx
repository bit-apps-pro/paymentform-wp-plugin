import { useState } from 'react';
import { Drawer, Tabs, Typography, Flex, Segmented, theme, Button } from 'antd';
import { X } from 'lucide-react';
import { css } from '@emotion/css';
import type { Form } from './useFormsList';
import { DevicePreview } from './DevicePreview';
import { getEmbedUrl, getShortcode, getGutenbergBlock } from './embedUrl';
import { __ } from '../../lib/i18n';

interface EmbedDrawerProps {
  form: Form | null;
  onClose: () => void;
}

type SnippetKind = 'shortcode' | 'block' | 'iframe';

const EMBED_DRAWER_WIDTH = 520;
/** Wide enough for a legible tablet/desktop preview, never wider than the admin content area. */
const PREVIEW_DRAWER_WIDTH = 'min(1100px, calc(100vw - 200px))';

const FLEX_FILL: React.CSSProperties = {
  height: '100%',
  display: 'flex',
  flexDirection: 'column',
};

/**
 * Tabs ship a fixed three-layer DOM (nav, content-holder, content) where each
 * inner level defaults to height:auto, so a `style={FLEX_FILL}` on the root
 * Tabs element doesn't reach the tabpane — the preview iframe collapses.
 * Override the inner layers so the active tabpane fills the drawer body.
 */
const TABS_FILL = css`
  .ant-tabs-content-holder,
  .ant-tabs-content,
  .ant-tabs-tabpane-active {
    display: flex;
    flex-direction: column;
    flex: 1;
    min-height: 0;
  }
`;

function snippetHint(kind: SnippetKind): string {
  switch (kind) {
    case 'shortcode':
      return __('Paste this shortcode into any post or page.', 'paymentform');
    case 'block':
      return __('Paste this block markup into the Code Editor of any post or page.', 'paymentform');
    case 'iframe':
      return __('Embed this URL in any HTML page outside WordPress.', 'paymentform');
  }
}

export function EmbedDrawer({ form, onClose }: EmbedDrawerProps) {
  const { token } = theme.useToken();
  const [activeTab, setActiveTab] = useState<string>('embed');
  const [snippet, setSnippet] = useState<SnippetKind>('shortcode');
  const [previewLoaded, setPreviewLoaded] = useState(false);

  const open = !!form;
  const formId = form?.id ?? '';
  const embedUrl = formId ? (getEmbedUrl(formId) ?? '') : '';
  const shortcode = formId ? getShortcode(formId) : '';
  const block = formId ? getGutenbergBlock(formId) : '';

  // Reset transient state when the drawer opens OR the selected form changes
  // (parent may swap `form` without closing the drawer between row clicks).
  // Done during render via the "adjust state on prop change" pattern rather
  // than an effect, so the reset is applied before paint with no extra render.
  // https://react.dev/learn/you-might-not-need-an-effect#adjusting-some-state-when-a-prop-changes
  const [openedFormId, setOpenedFormId] = useState<string | null>(null);
  if (open && openedFormId !== formId) {
    setOpenedFormId(formId);
    setSnippet('shortcode');
    setActiveTab('embed');
    setPreviewLoaded(false);
  } else if (!open && openedFormId !== null) {
    // Forget the opened form once closed so re-opening the same form resets.
    setOpenedFormId(null);
  }

  const handleClose = () => {
    onClose();
  };

  const snippetValue = snippet === 'shortcode' ? shortcode : snippet === 'block' ? block : embedUrl;
  const isIframeMissing = snippet === 'iframe' && !embedUrl;

  return (
    <Drawer
      title={form?.name ?? ''}
      placement="right"
      open={open}
      onClose={handleClose}
      destroyOnHidden
      closeIcon={null}
      extra={
        <Button
          type="text"
          shape="circle"
          aria-label={__('Close', 'paymentform')}
          icon={<X size={18} />}
          onClick={handleClose}
        />
      }
      styles={{
        wrapper: { width: activeTab === 'preview' ? PREVIEW_DRAWER_WIDTH : EMBED_DRAWER_WIDTH },
        header: {
          padding: `${token.paddingMD}px ${token.paddingLG}px`,
          borderBottom: `1px solid ${token.colorBorderSecondary}`,
        },
        body: {
          padding: token.paddingLG,
          height: '100%',
          display: 'flex',
          flexDirection: 'column',
        },
      }}
    >
      <Tabs
        activeKey={activeTab}
        onChange={setActiveTab}
        style={FLEX_FILL}
        className={TABS_FILL}
        items={[
          {
            key: 'embed',
            label: __('Embed', 'paymentform'),
            children: (
              <Flex
                vertical
                gap={token.marginSM}
                style={{ flex: 1, display: 'flex', flexDirection: 'column', minHeight: 0 }}
              >
                <Segmented<SnippetKind>
                  value={snippet}
                  onChange={setSnippet}
                  options={[
                    { label: __('Shortcode', 'paymentform'), value: 'shortcode' },
                    { label: __('Block', 'paymentform'), value: 'block' },
                    { label: __('Iframe URL', 'paymentform'), value: 'iframe' },
                  ]}
                />
                <Typography.Text type="secondary">{snippetHint(snippet)}</Typography.Text>
                {isIframeMissing ? (
                  <Typography.Paragraph type="secondary" style={{ marginTop: token.marginXS }}>
                    {__(
                      'Connect this site to PaymentForm to get an iframe URL.',
                      'paymentform'
                    )}
                  </Typography.Paragraph>
                ) : (
                  <Typography.Paragraph copyable code style={{ marginTop: token.marginXS }}>
                    {snippetValue}
                  </Typography.Paragraph>
                )}
              </Flex>
            ),
          },
          {
            key: 'preview',
            label: __('Preview', 'paymentform'),
            children:
              activeTab === 'preview' && formId ? (
                embedUrl ? (
                  <DevicePreview
                    src={embedUrl}
                    title={form?.name ?? __('Preview', 'paymentform')}
                    loading={!previewLoaded}
                    onLoad={() => setPreviewLoaded(true)}
                  />
                ) : (
                  <Typography.Text type="secondary">
                    {__(
                      'Connect this site to PaymentForm to preview forms here.',
                      'paymentform'
                    )}
                  </Typography.Text>
                )
              ) : null,
          },
        ]}
      />
    </Drawer>
  );
}
