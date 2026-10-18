import { useEffect, useRef, useState } from 'react';
import { Segmented, Skeleton, theme } from 'antd';
import { Monitor, Smartphone, Tablet } from 'lucide-react';
import { __ } from '../../lib/i18n';
import { PREVIEW_DEVICES, previewScale, type PreviewDevice } from './previewScale';

interface DeviceSwitchProps {
  value: PreviewDevice;
  onChange: (device: PreviewDevice) => void;
}

interface DevicePreviewProps {
  src: string;
  title: string;
  device: PreviewDevice;
  loading: boolean;
  onLoad: () => void;
}

const DEVICE_ICON_SIZE = 14;

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

/** Mobile / tablet / desktop toggle that picks the preview's emulated viewport. */
export function DeviceSwitch({ value, onChange }: DeviceSwitchProps) {
  return (
    <Segmented<PreviewDevice>
      size="small"
      value={value}
      onChange={onChange}
      options={[
        { label: __('Mobile', 'paymentform'), value: 'mobile', icon: <Smartphone size={DEVICE_ICON_SIZE} /> },
        { label: __('Tablet', 'paymentform'), value: 'tablet', icon: <Tablet size={DEVICE_ICON_SIZE} /> },
        { label: __('Desktop', 'paymentform'), value: 'desktop', icon: <Monitor size={DEVICE_ICON_SIZE} /> },
      ]}
    />
  );
}

/** Form preview iframe rendered at the given device width, scaled to fit a padded stage. */
export function DevicePreview({ src, title, device, loading, onLoad }: DevicePreviewProps) {
  const { token } = theme.useToken();
  const { ref, width, height } = useElementSize<HTMLDivElement>();

  const deviceWidth = PREVIEW_DEVICES[device];
  const scale = previewScale(width, deviceWidth);

  return (
    <div
      ref={ref}
      style={{
        flex: 1,
        minHeight: 0,
        overflow: 'hidden',
        display: 'flex',
        justifyContent: 'center',
        position: 'relative',
        padding: token.paddingLG,
        background: token.colorFillQuaternary,
        border: `1px solid ${token.colorBorderSecondary}`,
        borderRadius: token.borderRadiusLG,
      }}
    >
      {loading && (
        <Skeleton
          active
          paragraph={{ rows: 8 }}
          style={{ position: 'absolute', inset: token.paddingLG, width: 'auto' }}
        />
      )}
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
          borderRadius: token.borderRadiusLG,
          boxShadow: token.boxShadowTertiary,
          transform: `scale(${scale})`,
          transformOrigin: 'top center',
        }}
      />
    </div>
  );
}
