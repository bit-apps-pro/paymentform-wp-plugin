import { Table, Empty, Button, Space, Tag, Spin, message, Flex, Typography, theme } from 'antd';
import { Copy } from 'lucide-react';
import { useFormsList, type Form } from '../features/forms/useFormsList';
import { css } from '@emotion/css';

export function Forms() {
  const { token } = theme.useToken();
  const { data: forms = [], isLoading } = useFormsList();

  const handleCopyEmbed = (formId: string) => {
    const embedCode = `<div data-form-id="${formId}"></div>`;
    navigator.clipboard.writeText(embedCode).then(() => {
      message.success('Embed code copied to clipboard');
    });
  };

  const statusColor = (status: string): string => {
    switch (status) {
      case 'enabled':
      case 'active':
      case 'published':
        return 'green';
      case 'draft':
      case 'disabled':
        return 'blue';
      default:
        return 'default';
    }
  };

  const columns = [
    {
      title: 'Name',
      dataIndex: 'name',
      key: 'name',
      width: 220,
    },
    {
      title: 'Status',
      dataIndex: 'status',
      key: 'status',
      width: 120,
      render: (status: string) => <Tag color={statusColor(status)}>{status}</Tag>,
    },
    {
      title: 'Submissions',
      dataIndex: 'submissionsCount',
      key: 'submissionsCount',
      width: 120,
      render: (count: number | undefined) => count ?? 0,
    },
    {
      title: 'Created',
      dataIndex: 'createdAt',
      key: 'createdAt',
      width: 180,
      render: (date: string | undefined) => date ?? '—',
    },
    {
      title: 'Actions',
      key: 'actions',
      width: 100,
      render: (_: unknown, record: Form) => (
        <Space size="small">
          <Button
            type="text"
            size="small"
            icon={<Copy size={16} />}
            onClick={() => handleCopyEmbed(record.id)}
          />
        </Space>
      ),
    },
  ];

  const containerStyles = css`
    max-width: 1200px;
    margin: 0 auto;
  `;

  if (isLoading) {
    return (
      <div className={containerStyles}>
        <Flex vertical align="center" gap={token.marginMD} style={{ paddingTop: token.paddingXL }}>
          <Spin size="large" />
          <Typography.Text type="secondary">Loading forms…</Typography.Text>
        </Flex>
      </div>
    );
  }

  if (!forms || forms.length === 0) {
    return (
      <div className={containerStyles}>
        <Empty
          description="No forms yet"
          style={{ marginTop: token.marginLG }}
        />
      </div>
    );
  }

  return (
    <div className={containerStyles}>
      <Table
        columns={columns}
        dataSource={forms.map((form) => ({ ...form, key: form.id }))}
        pagination={{ pageSize: 10 }}
        scroll={{ x: 'max-content' }}
        size="middle"
      />
    </div>
  );
}
