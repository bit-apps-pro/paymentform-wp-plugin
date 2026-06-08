import { Layout, theme, Space, Menu } from 'antd';
import { Outlet, useLocation, useNavigate } from 'react-router-dom';
import { DollarSign, FileText, Settings as SettingsIcon } from 'lucide-react';
import { css } from '@emotion/css';

const { Header, Sider, Content } = Layout;

export function AppShell() {
  const { token } = theme.useToken();
  const location = useLocation();
  const navigate = useNavigate();
  const connected = window.paymentformAdmin?.connected === '1' || window.paymentformAdmin?.connected === true;

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

  const menuItems = connected
    ? [
        { key: '/forms', icon: <FileText size={16} />, label: 'Forms' },
        { key: '/settings', icon: <SettingsIcon size={16} />, label: 'Settings' },
      ]
    : [{ key: '/welcome', icon: <FileText size={16} />, label: 'Welcome' }];

  const activeKey = '/' + (location.pathname.replace(/^\//, '').split('/')[0] || 'welcome');

  return (
    <Layout style={{ minHeight: '100vh' }}>
      <Sider
        width={220}
        collapsible
        breakpoint="lg"
        collapsedWidth={0}
        className={siderStyles}
      >
        <Menu
          mode="inline"
          selectedKeys={[activeKey]}
          items={menuItems}
          onClick={({ key }) => navigate(key)}
          style={{ borderInlineEnd: 'none', paddingTop: token.paddingSM }}
        />
      </Sider>
      <Layout>
        <Header className={headerStyles}>
          <Space>
            <DollarSign size={24} color={token.colorPrimary} />
            <span style={{ fontSize: token.fontSizeLG, fontWeight: token.fontWeightStrong }}>
              PaymentForm Admin
            </span>
          </Space>
        </Header>
        <Content className={contentStyles}>
          <Outlet />
        </Content>
      </Layout>
    </Layout>
  );
}
