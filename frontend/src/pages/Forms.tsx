import { Table, Empty, Button, Space, Tag, Spin, message } from 'antd';
import { Copy } from 'lucide-react';
import { dateI18n } from '@wordpress/date';
import { useFormsList, type Form } from '../features/forms/useFormsList';
import { css } from '@emotion/css';
import { theme } from 'antd';

export function Forms() {
  const { token } = theme.useToken();
  const { data: forms = [], isLoading } = useFormsList();

  const handleCopyEmbed = (formId: string) => {
    const embedCode = `<div data-form-id="${formId}"></div>`;
    navigator.clipboard.writeText(embedCode).then(() => {
      message.success('Embed code copied to clipboard');
    });
  };

  const columns = [
    {
      title: 'Name',
      dataIndex: 'name',
      key: 'name',
      width: 200,
    },
    {
      title: 'Status',
      dataIndex: 'status',
      key: 'status',
      width: 120,
      render: (status: string) => {
        const color = status === 'active' ? 'green' : status === 'draft' ? 'blue' : 'default';
        return <Tag color={color}>{status}</Tag>;
      },
    },
    {
      title: 'Created',
      dataIndex: 'created_at',
      key: 'created_at',
      width: 150,
      render: (date: string) => {
        try {
          return dateI18n('F j, Y', new Date(date).getTime() / 1000);
        } catch {
          return date;
        }
      },
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
        <Spin tip="Loading forms..." />
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
