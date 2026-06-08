import { useEffect } from 'react';
import { Spin } from 'antd';
import { useNavigate } from 'react-router-dom';
import { theme } from 'antd';
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
      <Spin size="large" tip="Finishing setup..." />
    </div>
  );
}
