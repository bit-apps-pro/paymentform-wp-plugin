import { useState } from 'react';
import { Drawer, Tabs, Typography, Flex, Skeleton, theme, Button } from 'antd';
import { X } from 'lucide-react';
import type { Form } from './useFormsList';
import { getEmbedUrl, getShortcode, getGutenbergBlock } from './embedUrl';
import { __ } from '../../lib/i18n';

interface EmbedDrawerProps {
  form: Form | null;
  onClose: () => void;
}

export function EmbedDrawer({ form, onClose }: EmbedDrawerProps) {
  const { token } = theme.useToken();
  const [activeTab, setActiveTab] = useState<string>('embed');
  const [previewLoaded, setPreviewLoaded] = useState(false);

  const open = !!form;
  const formId = form?.id ?? '';
  const embedUrl = formId ? getEmbedUrl(formId) : '';
  const shortcode = formId ? getShortcode(formId) : '';
  const block = formId ? getGutenbergBlock(formId) : '';

  const handleClose = () => {
    setActiveTab('embed');
    setPreviewLoaded(false);
    onClose();
  };

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
        wrapper: { width: 520 },
        header: {
          padding: `${token.paddingMD}px ${token.paddingLG}px`,
          borderBottom: `1px solid ${token.colorBorderSecondary}`,
        },
        body: { padding: token.paddingLG },
      }}
    >
      <Tabs
        activeKey={activeTab}
        onChange={setActiveTab}
        items={[
          {
            key: 'embed',
            label: __('Embed', 'paymentform'),
            children: (
              <Flex vertical gap={token.marginMD} style={{ width: '100%' }}>
                <div>
                  <Typography.Text strong>
                    {__('Shortcode', 'paymentform')}
                  </Typography.Text>
                  <Typography.Paragraph copyable code style={{ marginTop: token.marginXS }}>
                    {shortcode}
                  </Typography.Paragraph>
                </div>
                <div>
                  <Typography.Text strong>
                    {__('Gutenberg block', 'paymentform')}
                  </Typography.Text>
                  <Typography.Paragraph copyable code style={{ marginTop: token.marginXS }}>
                    {block}
                  </Typography.Paragraph>
                </div>
                <div>
                  <Typography.Text strong>{__('Iframe URL', 'paymentform')}</Typography.Text>
                  <Typography.Paragraph copyable code style={{ marginTop: token.marginXS }}>
                    {embedUrl}
                  </Typography.Paragraph>
                </div>
                <Typography.Text type="secondary">
                  {__(
                    'Paste the shortcode in any post or page, or insert the PaymentForm block in the editor.',
                    'paymentform'
                  )}
                </Typography.Text>
              </Flex>
            ),
          },
          {
            key: 'preview',
            label: __('Preview', 'paymentform'),
            children:
              activeTab === 'preview' && formId ? (
                <div style={{ position: 'relative', minHeight: 600 }}>
                  {!previewLoaded && (
                    <Skeleton
                      active
                      paragraph={{ rows: 8 }}
                      style={{ position: 'absolute', inset: 0 }}
                    />
                  )}
                  <iframe
                    src={embedUrl}
                    title={form?.name ?? __('Preview', 'paymentform')}
                    style={{
                      border: 'none',
                      width: '100%',
                      height: 600,
                      background: token.colorBgContainer,
                    }}
                    onLoad={() => setPreviewLoaded(true)}
                  />
                </div>
              ) : null,
          },
        ]}
      />
    </Drawer>
  );
}
