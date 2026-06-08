import { Layout, theme, Space } from 'antd';
import { Outlet } from 'react-router-dom';
import { DollarSign } from 'lucide-react';
import { css } from '@emotion/css';

const { Header, Sider, Content } = Layout;

export function AppShell() {
  const { token } = theme.useToken();

  const headerStyles = css`
    background: ${token.colorBgContainer};
    border-bottom: 1px solid ${token.colorBorder};
    display: flex;
    align-items: center;
    padding: 0 ${token.paddingLG}px;
    height: 64px;
  `;

  const siderStyles = css`
    background: ${token.colorBgContainer};
  `;

  const contentStyles = css`
    padding: ${token.paddingLG}px;
    background: ${token.colorBgElevated};
    min-height: 100vh;
  `;

  return (
    <Layout style={{ minHeight: '100vh' }}>
      <Sider
        width={200}
        collapsible
        breakpoint="lg"
        collapsedWidth={0}
        className={siderStyles}
      >
        <Space direction="vertical" style={{ width: '100%', padding: token.paddingLG }}>
          <div style={{ fontSize: '14px', fontWeight: 500 }}>PaymentForm</div>
        </Space>
      </Sider>
      <Layout>
        <Header className={headerStyles}>
          <Space>
            <DollarSign size={24} color={token.colorPrimary} />
            <span style={{ fontSize: '16px', fontWeight: 500 }}>PaymentForm Admin</span>
          </Space>
        </Header>
        <Content className={contentStyles}>
          <Outlet />
        </Content>
      </Layout>
    </Layout>
  );
}
