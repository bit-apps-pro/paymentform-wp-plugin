import type { CSSProperties } from 'react';

interface LogoProps {
  size?: number;
  className?: string;
  style?: CSSProperties;
}

export function Logo({ size = 32, className, style }: LogoProps) {
  return (
    <svg
      width={size}
      height={size}
      viewBox="0 0 32 32"
      fill="none"
      className={className}
      style={style}
      aria-label="PaymentForm"
    >
      <rect width="100%" height="100%" rx="7" fill="#6E62E5" />
      <path
        d="M11 25V8H18.7645C20.0345 8 21.1116 8.21702 21.9957 8.65106C22.8799 9.08511 23.547 9.70402 23.9972 10.5078C24.4634 11.3116 24.6965 12.2842 24.6965 13.4255C24.6965 14.5669 24.4634 15.5475 23.9972 16.3674C23.547 17.1712 22.8799 17.7901 21.9957 18.2241C21.1116 18.6582 20.0345 18.8752 18.7645 18.8752H14.7376V22L11 25ZM14.7376 15.9816H18.1376C19.1021 15.9816 19.8336 15.7645 20.3319 15.3305C20.8463 14.8965 21.1035 14.2615 21.1035 13.4255C21.1035 12.5896 20.8463 11.9626 20.3319 11.5447C19.8336 11.1267 19.1021 10.9177 18.1376 10.9177H14.7376V15.9816Z"
        fill="white"
      />
    </svg>
  );
}
