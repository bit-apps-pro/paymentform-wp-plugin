import { useState } from 'react';
import { Flex, Typography } from 'antd';
import { useParams } from 'react-router-dom';
import { HeaderActions } from '../components/HeaderActions';
import { DevicePreview, DeviceSwitch } from '../features/forms/DevicePreview';
import { getEmbedUrl } from '../features/forms/embedUrl';
import type { PreviewDevice } from '../features/forms/previewScale';
import { useFormsList } from '../features/forms/useFormsList';
import { __ } from '../lib/i18n';

/** Below this the device frame is too short to read, so the content area scrolls instead. */
const PREVIEW_MIN_HEIGHT_PX = 420;

/** Full-page device preview of one form; its title and device switch live in the shell's breadcrumb bar. */
export function FormPreview() {
  const { formId = '' } = useParams<{ formId: string }>();
  const { data: forms } = useFormsList();
  const [device, setDevice] = useState<PreviewDevice>('desktop');
  const [loadedSrc, setLoadedSrc] = useState<string | null>(null);

  const title = forms?.find((form) => form.id === formId)?.name ?? __('Preview', 'paymentform');
  const embedUrl = getEmbedUrl(formId);

  if (!embedUrl) {
    return (
      <Typography.Text type="secondary">
        {__('Connect this site to PaymentForm to preview forms here.', 'paymentform')}
      </Typography.Text>
    );
  }

  return (
    <Flex vertical style={{ height: '100%', minHeight: PREVIEW_MIN_HEIGHT_PX }}>
      <HeaderActions>
        <DeviceSwitch value={device} onChange={setDevice} />
      </HeaderActions>
      <DevicePreview
        src={embedUrl}
        title={title}
        device={device}
        loading={loadedSrc !== embedUrl}
        onLoad={() => setLoadedSrc(embedUrl)}
      />
    </Flex>
  );
}
