import { useEffect } from 'react';
import { Spin, Flex, Typography, theme } from 'antd';
import { useNavigate } from 'react-router-dom';
import { css } from '@emotion/css';

export function Callback() {
  const { token } = theme.useToken();
  const navigate = useNavigate();

  useEffect(() => {
    // Redirect to forms after a brief pause
    const timer = setTimeout(() => {
      navigate('/forms');
    }, 1500);

    return () => clearTimeout(timer);
  }, [navigate]);

  const containerStyles = css`
    display: flex;
    align-items: center;
    justify-content: center;
    min-height: 100vh;
    background: ${token.colorBgBase};
  `;

  return (
    <div className={containerStyles}>
      <Flex vertical align="center" gap={token.marginMD}>
        <Spin size="large" />
        <Typography.Text type="secondary">Finishing setup…</Typography.Text>
      </Flex>
    </div>
  );
}
