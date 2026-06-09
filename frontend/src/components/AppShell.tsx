import { Layout, theme, Menu, Flex } from 'antd';
import { Outlet, useLocation, useNavigate } from 'react-router-dom';
import { FileText, Settings as SettingsIcon } from 'lucide-react';
import { css } from '@emotion/css';
import { Logo } from './Logo';
import { ConnectionPill } from './ConnectionPill';
import { HeaderThemeToggle } from './HeaderThemeToggle';
import { UserMenu } from './UserMenu';
import { AppBreadcrumb } from './AppBreadcrumb';
import { __ } from '../lib/i18n';

const { Header, Sider, Content } = Layout;

export function AppShell() {
  const { token } = theme.useToken();
  const location = useLocation();
  const navigate = useNavigate();
  const connected =
    window.paymentformAdmin?.connected === '1' || window.paymentformAdmin?.connected === true;

  const headerStyles = css`
    background: ${token.colorBgContainer};
    border-bottom: 1px solid ${token.colorBorder};
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 0 ${token.paddingLG}px;
    height: 64px;
  `;

  const siderStyles = css`
    background: ${token.colorBgContainer};
    border-inline-end: 1px solid ${token.colorBorder};
  `;

  const breadcrumbBar = css`
    padding: ${token.paddingSM}px ${token.paddingLG}px;
    background: ${token.colorBgContainer};
    border-bottom: 1px solid ${token.colorBorderSecondary};
  `;

  const contentStyles = css`
    padding: ${token.paddingLG}px;
    background: ${token.colorBgLayout};
    min-height: calc(100vh - 64px);
  `;

  const brandText = css`
    font-size: ${token.fontSizeLG}px;
    font-weight: ${token.fontWeightStrong};
    line-height: 1;
    color: ${token.colorText};
  `;

  const menuItems = connected
    ? [
        {
          key: '/forms',
          icon: <FileText size={16} />,
          label: __('Forms', 'paymentform'),
          title: __('Forms', 'paymentform'),
        },
        {
          key: '/settings',
          icon: <SettingsIcon size={16} />,
          label: __('Settings', 'paymentform'),
          title: __('Settings', 'paymentform'),
        },
      ]
    : [
        {
          key: '/welcome',
          icon: <FileText size={16} />,
          label: __('Welcome', 'paymentform'),
          title: __('Welcome', 'paymentform'),
        },
      ];

  const activeKey = '/' + (location.pathname.replace(/^\//, '').split('/')[0] || 'welcome');

  return (
    <Layout style={{ minHeight: '100vh' }}>
      <Sider
        width={220}
        collapsible
        breakpoint="md"
        collapsedWidth={56}
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
          <Flex align="center" gap={token.marginSM}>
            <Logo size={28} />
            <span className={brandText}>{__('PaymentForm', 'paymentform')}</span>
          </Flex>
          <Flex align="center" gap={token.marginXS}>
            <ConnectionPill />
            <HeaderThemeToggle />
            <UserMenu />
          </Flex>
        </Header>
        <div className={breadcrumbBar}>
          <AppBreadcrumb />
        </div>
        <Content className={contentStyles}>
          <Outlet />
        </Content>
      </Layout>
    </Layout>
  );
}
