import { useEffect, useRef, useState } from 'react';
import { Flex, Segmented, Skeleton, theme } from 'antd';
import { __ } from '../../lib/i18n';
import { PREVIEW_DEVICES, previewScale, type PreviewDevice } from './previewScale';

interface DevicePreviewProps {
  src: string;
  title: string;
  loading: boolean;
  onLoad: () => void;
}

/** Tracks an element's content box size via ResizeObserver. */
function useElementSize<T extends HTMLElement>() {
  const ref = useRef<T>(null);
  const [size, setSize] = useState({ width: 0, height: 0 });

  useEffect(() => {
    const element = ref.current;
    if (!element || typeof ResizeObserver === 'undefined') return;
    const observer = new ResizeObserver(([entry]) => {
      if (entry) setSize({ width: entry.contentRect.width, height: entry.contentRect.height });
    });
    observer.observe(element);
    return () => observer.disconnect();
  }, []);

  return { ref, ...size };
}

/** Form preview iframe rendered at a chosen device width and scaled down to fit its container. */
export function DevicePreview({ src, title, loading, onLoad }: DevicePreviewProps) {
  const { token } = theme.useToken();
  const [device, setDevice] = useState<PreviewDevice>('mobile');
  const { ref, width, height } = useElementSize<HTMLDivElement>();

  const deviceWidth = PREVIEW_DEVICES[device];
  const scale = previewScale(width, deviceWidth);

  return (
    <Flex vertical gap={token.marginSM} style={{ flex: 1, minHeight: 0 }}>
      <Segmented<PreviewDevice>
        value={device}
        onChange={setDevice}
        options={[
          { label: __('Mobile', 'paymentform'), value: 'mobile' },
          { label: __('Tablet', 'paymentform'), value: 'tablet' },
          { label: __('Desktop', 'paymentform'), value: 'desktop' },
        ]}
        style={{ alignSelf: 'center' }}
      />
      <div
        ref={ref}
        style={{ flex: 1, minHeight: 0, overflow: 'hidden', display: 'flex', justifyContent: 'center', position: 'relative' }}
      >
        {loading && <Skeleton active paragraph={{ rows: 8 }} style={{ position: 'absolute', inset: 0 }} />}
        <iframe
          src={src}
          title={title}
          // Sandbox so the preview can't submit a real payment or navigate the wp-admin tab.
          sandbox="allow-scripts allow-same-origin"
          referrerPolicy="no-referrer"
          onLoad={onLoad}
          style={{
            width: deviceWidth,
            height: height > 0 ? height / scale : '100%',
            flexShrink: 0,
            border: 'none',
            display: 'block',
            background: token.colorBgContainer,
            transform: `scale(${scale})`,
            transformOrigin: 'top center',
          }}
        />
      </div>
    </Flex>
  );
}
