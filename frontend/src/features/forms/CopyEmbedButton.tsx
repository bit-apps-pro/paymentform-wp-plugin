import { App as AntApp, Button, Dropdown, Flex, Space, Typography, theme } from 'antd';
import type { MenuProps } from 'antd';
import { ChevronDown, Copy } from 'lucide-react';
import { copyText } from '../../lib/clipboard';
import { __ } from '../../lib/i18n';
import { getEmbedUrl, getGutenbergBlock, getShortcode } from './embedUrl';

interface CopyEmbedButtonProps {
  formId: string;
}

type EmbedKind = 'shortcode' | 'block' | 'iframe';

interface EmbedSnippet {
  label: string;
  copiedMessage: string;
  /** `null` when the snippet can't be produced yet (iframe URL before the site is connected). */
  value: string | null;
}

const MENU_ORDER: readonly EmbedKind[] = ['shortcode', 'block', 'iframe'];
const ICON_SIZE = 14;
const HINT_MAX_WIDTH_PX = 240;

/** Builds every copyable embed snippet for a form, keyed by kind. */
function embedSnippets(formId: string): Record<EmbedKind, EmbedSnippet> {
  return {
    shortcode: {
      label: __('Shortcode', 'paymentform'),
      copiedMessage: __('Shortcode copied', 'paymentform'),
      value: getShortcode(formId),
    },
    block: {
      label: __('Block markup', 'paymentform'),
      copiedMessage: __('Block markup copied', 'paymentform'),
      value: getGutenbergBlock(formId),
    },
    iframe: {
      label: __('Iframe URL', 'paymentform'),
      copiedMessage: __('Iframe URL copied', 'paymentform'),
      value: getEmbedUrl(formId),
    },
  };
}

/** Split button that copies a form's shortcode in one click, with block markup and iframe URL in its menu. */
export function CopyEmbedButton({ formId }: CopyEmbedButtonProps) {
  const { message } = AntApp.useApp();
  const { token } = theme.useToken();
  const snippets = embedSnippets(formId);

  /** Copies one snippet and reports the outcome as a toast. */
  const copy = async ({ value, copiedMessage }: EmbedSnippet) => {
    if (value === null) return;
    try {
      await copyText(value);
      message.success(copiedMessage);
    } catch {
      message.error(__('Copy failed', 'paymentform'));
    }
  };

  /** Menu label, with an explanation under an item that is unavailable. */
  const menuLabel = ({ label, value }: EmbedSnippet) =>
    value === null ? (
      <Flex vertical style={{ maxWidth: HINT_MAX_WIDTH_PX, whiteSpace: 'normal' }}>
        <span>{label}</span>
        <Typography.Text type="secondary" style={{ fontSize: token.fontSizeSM }}>
          {__('Connect this site to PaymentForm to get an iframe URL.', 'paymentform')}
        </Typography.Text>
      </Flex>
    ) : (
      label
    );

  const menu: MenuProps = {
    items: MENU_ORDER.map((kind) => ({
      key: kind,
      label: menuLabel(snippets[kind]),
      disabled: snippets[kind].value === null,
      onClick: () => void copy(snippets[kind]),
    })),
  };

  return (
    <Space.Compact size="small">
      <Button icon={<Copy size={ICON_SIZE} />} onClick={() => void copy(snippets.shortcode)}>
        {__('Copy shortcode', 'paymentform')}
      </Button>
      <Dropdown menu={menu} trigger={['click']} placement="bottomRight">
        <Button
          icon={<ChevronDown size={ICON_SIZE} />}
          aria-label={__('More embed options', 'paymentform')}
        />
      </Dropdown>
    </Space.Compact>
  );
}
