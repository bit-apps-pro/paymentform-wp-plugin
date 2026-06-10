import { useMemo, useState } from 'react';
import {
  Table,
  Empty,
  Button,
  Space,
  Badge,
  Skeleton,
  Tooltip,
  Typography,
  theme,
  Row,
  Col,
  Card,
  Statistic,
} from 'antd';
import type { BadgeProps } from 'antd';
import { Code2, Pencil, Plus } from 'lucide-react';
import { css } from '@emotion/css';
import { useFormsList, type Form } from '../features/forms/useFormsList';
import { FormsToolbar, useFormsToolbarState } from '../features/forms/FormsToolbar';
import { EmbedDrawer } from '../features/forms/EmbedDrawer';
import { __ } from '../lib/i18n';
import { appBaseUrl } from '../lib/appBaseUrl';

const ACTIVE_STATUSES = new Set(['enabled', 'active', 'published']);

function badgeStatus(status: string): BadgeProps['status'] {
  if (ACTIVE_STATUSES.has(status)) return 'success';
  if (status === 'draft') return 'processing';
  return 'default';
}

export function Forms() {
  const { token } = theme.useToken();
  const { data: forms = [], isLoading, isFetching, refetch } = useFormsList();
  const { value: toolbar, setValue: setToolbar } = useFormsToolbarState();
  const [openForm, setOpenForm] = useState<Form | null>(null);
  const baseUrl = appBaseUrl();

  const containerStyles = css`
    max-width: 1200px;
    margin: 0 auto;
  `;

  const rowHoverStyles = css`
    cursor: default;
    &:hover > td {
      background: ${token.colorFillSecondary} !important;
    }
  `;

  const filtered = useMemo(() => {
    const q = toolbar.q.trim().toLowerCase();
    return forms.filter((f) => {
      if (toolbar.status !== 'all' && f.status !== toolbar.status) return false;
      if (q && !(f.name ?? '').toLowerCase().includes(q)) return false;
      return true;
    });
  }, [forms, toolbar.q, toolbar.status]);

  const totals = useMemo(() => {
    const total = forms.length;
    const submissions = forms.reduce((sum, f) => sum + (f.submissionsCount ?? 0), 0);
    const active = forms.filter((f) => ACTIVE_STATUSES.has(f.status)).length;
    return { total, submissions, active };
  }, [forms]);

  const columns = [
    {
      title: __('Name', 'paymentform'),
      dataIndex: 'name',
      key: 'name',
      width: 240,
    },
    {
      title: __('Status', 'paymentform'),
      dataIndex: 'status',
      key: 'status',
      width: 140,
      render: (status: string) => <Badge status={badgeStatus(status)} text={status} />,
    },
    {
      title: __('Submissions', 'paymentform'),
      dataIndex: 'submissionsCount',
      key: 'submissionsCount',
      width: 140,
      responsive: ['md' as const],
      render: (count: number | undefined) => count ?? 0,
    },
    {
      title: __('Created', 'paymentform'),
      dataIndex: 'createdAt',
      key: 'createdAt',
      width: 200,
      responsive: ['md' as const],
      render: (date: string | undefined) => date ?? '—',
    },
    {
      title: __('Actions', 'paymentform'),
      key: 'actions',
      width: 140,
      render: (_: unknown, record: Form) => (
        <Space size="small">
          <Button
            type="text"
            size="small"
            aria-label={__('Embed', 'paymentform')}
            icon={<Code2 size={16} />}
            onClick={() => setOpenForm(record)}
          />
          <Tooltip title={__('Edit form', 'paymentform')}>
            <Button
              type="text"
              size="small"
              aria-label={__('Edit form', 'paymentform')}
              icon={<Pencil size={16} />}
              href={`${baseUrl}/build/${encodeURIComponent(record.id)}`}
              target="_blank"
              rel="noopener noreferrer"
            />
          </Tooltip>
        </Space>
      ),
    },
  ];

  const summaryRow = (
    <Row gutter={[token.marginMD, token.marginMD]} style={{ marginBottom: token.marginLG }}>
      <Col xs={24} sm={8}>
        <Card>
          {isLoading ? (
            <Skeleton.Button active block size="large" />
          ) : (
            <Statistic title={__('Total forms', 'paymentform')} value={totals.total} />
          )}
        </Card>
      </Col>
      <Col xs={24} sm={8}>
        <Card>
          {isLoading ? (
            <Skeleton.Button active block size="large" />
          ) : (
            <Statistic title={__('Total submissions', 'paymentform')} value={totals.submissions} />
          )}
        </Card>
      </Col>
      <Col xs={24} sm={8}>
        <Card>
          {isLoading ? (
            <Skeleton.Button active block size="large" />
          ) : (
            <Statistic title={__('Active forms', 'paymentform')} value={totals.active} />
          )}
        </Card>
      </Col>
    </Row>
  );

  if (isLoading) {
    return (
      <div className={containerStyles}>
        {summaryRow}
        <Card>
          <Skeleton active paragraph={{ rows: 6 }} />
        </Card>
      </div>
    );
  }

  const createFormButton = (
    <Button
      type="primary"
      icon={<Plus size={16} />}
      href={`${baseUrl}/myforms`}
      target="_blank"
      rel="noopener noreferrer"
    >
      {__('Create form', 'paymentform')}
    </Button>
  );

  return (
    <div className={containerStyles}>
      {summaryRow}
      <Card>
        <FormsToolbar
          value={toolbar}
          onChange={setToolbar}
          onRefresh={() => refetch()}
          isRefreshing={isFetching}
          extra={createFormButton}
        />
        {filtered.length === 0 ? (
          <Empty
            description={
              forms.length === 0
                ? __('No forms yet', 'paymentform')
                : __('No forms match your filters', 'paymentform')
            }
            style={{ marginTop: token.marginLG, marginBottom: token.marginLG }}
          >
            <Button
              type="primary"
              icon={<Plus size={16} />}
              href={`${baseUrl}/myforms`}
              target="_blank"
              rel="noopener noreferrer"
            >
              {__('Create form', 'paymentform')}
            </Button>
          </Empty>
        ) : (
          <Table
            columns={columns}
            dataSource={filtered.map((form) => ({ ...form, key: form.id }))}
            pagination={{ pageSize: 10 }}
            scroll={{ x: 'max-content' }}
            size={toolbar.density === 'compact' ? 'small' : 'middle'}
            rowClassName={() => rowHoverStyles}
          />
        )}
      </Card>
      <EmbedDrawer form={openForm} onClose={() => setOpenForm(null)} />
      <div style={{ marginTop: token.marginMD }}>
        <Typography.Text type="secondary" style={{ fontSize: token.fontSizeSM }}>
          {__('Tip: click the embed icon to copy a shortcode or preview a form.', 'paymentform')}
        </Typography.Text>
      </div>
    </div>
  );
}
