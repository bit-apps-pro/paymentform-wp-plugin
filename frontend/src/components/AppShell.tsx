import { useState } from "react";
import { Layout, theme, Menu, Flex, Button, Tooltip } from "antd";
import { Outlet, useLocation, useNavigate } from "react-router-dom";
import {
  FileText,
  Settings as SettingsIcon,
  PanelLeftClose,
  PanelLeftOpen,
} from "lucide-react";
import { css } from "@emotion/css";
import { Logo } from "./Logo";
import { ConnectionPill } from "./ConnectionPill";
import { HeaderThemeToggle } from "./HeaderThemeToggle";
import { HeaderConnectButton } from "./HeaderConnectButton";
import { UserMenu } from "./UserMenu";
import { AppBreadcrumb } from "./AppBreadcrumb";
import { VerifyEmailBanner } from "./VerifyEmailBanner";
import { __ } from "../lib/i18n";
import { wpModuleData } from "../utils/wpData";

const { Header, Sider, Content } = Layout;

export function AppShell() {
  const { token } = theme.useToken();
  const location = useLocation();
  const navigate = useNavigate();
  const [collapsed, setCollapsed] = useState(false);
  const connected =
    wpModuleData.connected === "1" || wpModuleData.connected === true;

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
    flex: 1;
    min-height: 0;
    overflow: auto;
  `;

  const innerLayoutStyles = css`
    height: 100%;
    display: flex;
    flex-direction: column;
  `;

  const brandText = css`
    font-size: ${token.fontSizeLG}px;
    font-weight: ${token.fontWeightStrong};
    line-height: 1;
    color: ${token.colorText};
    @media (max-width: ${token.screenSMMax}px) {
      display: none;
    }
  `;

  const menuItems = connected
    ? [
        {
          key: "/forms",
          icon: <FileText size={16} />,
          label: __("Forms", "paymentform"),
          title: __("Forms", "paymentform"),
        },
        {
          key: "/settings",
          icon: <SettingsIcon size={16} />,
          label: __("Settings", "paymentform"),
          title: __("Settings", "paymentform"),
        },
      ]
    : [
        {
          key: "/welcome",
          icon: <FileText size={16} />,
          label: __("Welcome", "paymentform"),
          title: __("Welcome", "paymentform"),
        },
      ];

  const activeKey =
    "/" + (location.pathname.replace(/^\//, "").split("/")[0] || "welcome");

  const toggleLabel = collapsed
    ? __("Expand sidebar", "paymentform")
    : __("Collapse sidebar", "paymentform");

  return (
    <Layout style={{ height: "100%" }}>
      <Sider
        width={220}
        collapsed={collapsed}
        breakpoint="md"
        collapsedWidth={56}
        trigger={null}
        onBreakpoint={(broken) => setCollapsed(broken)}
        className={siderStyles}
      >
        <Menu
          mode="inline"
          selectedKeys={[activeKey]}
          items={menuItems}
          onClick={({ key }) => navigate(key)}
          style={{ borderInlineEnd: "none", paddingTop: token.paddingSM }}
        />
      </Sider>
      <Layout className={innerLayoutStyles}>
        <Header className={headerStyles}>
          <Flex align="center" gap={token.marginSM}>
            <Tooltip title={toggleLabel} placement="bottom">
              <Button
                type="text"
                shape="circle"
                aria-label={toggleLabel}
                icon={
                  collapsed ? (
                    <PanelLeftOpen size={18} />
                  ) : (
                    <PanelLeftClose size={18} />
                  )
                }
                onClick={() => setCollapsed((c) => !c)}
              />
            </Tooltip>
            <Logo size={28} />
            <span className={brandText}>
              {__("PaymentForm.io", "paymentform")}
            </span>
          </Flex>
          <Flex align="center" gap={token.marginXS} style={{ minWidth: 0 }}>
            <ConnectionPill />
            <HeaderConnectButton />
            <HeaderThemeToggle />
            <UserMenu />
          </Flex>
        </Header>
        <div className={breadcrumbBar}>
          <AppBreadcrumb />
        </div>
        <div
          style={{
            paddingLeft: token.paddingLG,
            paddingRight: token.paddingLG,
          }}
        >
          <VerifyEmailBanner />
        </div>
        <Content className={contentStyles}>
          <Outlet />
        </Content>
      </Layout>
    </Layout>
  );
}
