import { useMemo } from 'react';
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
  Grid,
} from 'antd';
import type { BadgeProps } from 'antd';
import { Eye, Pencil, Plus } from 'lucide-react';
import { css } from '@emotion/css';
import { useNavigate } from 'react-router-dom';
import { useFormsList, type Form } from '../features/forms/useFormsList';
import { FormsToolbar } from '../features/forms/FormsToolbar';
import { useFormsToolbarState } from '../features/forms/useFormsToolbarState';
import { CopyEmbedButton } from '../features/forms/CopyEmbedButton';
import { formPreviewPath } from '../features/forms/routes';
import { useDeepLink } from '../features/auth/useDeepLink';
import { CREATE_FORM_PATH } from '../lib/openLoginLink';
import { __ } from '../lib/i18n';

const ACTIVE_STATUSES = new Set(['enabled', 'active', 'published']);
/** Fits the copy split button plus the Preview and Edit icons on one line. */
const ACTIONS_COLUMN_WIDTH = 264;

function badgeStatus(status: string): BadgeProps['status'] {
  if (ACTIVE_STATUSES.has(status)) return 'success';
  if (status === 'draft') return 'processing';
  return 'default';
}

export function Forms() {
  const { token } = theme.useToken();
  const { data: forms = [], isLoading, isFetching, refetch } = useFormsList();
  const { value: toolbar, setValue: setToolbar } = useFormsToolbarState();
  const navigate = useNavigate();
  const screens = Grid.useBreakpoint();
  const { open: openDeepLink, pendingPath } = useDeepLink();

  const containerStyles = css`
    max-width: 1200px;
    margin: 0 auto;
  `;

  const rowHoverStyles = css`
    cursor: default;
    &:hover > td {
      /* Layered on an opaque base so the sticky Actions cell hides rows scrolling beneath it. */
      background: linear-gradient(${token.colorFillSecondary}, ${token.colorFillSecondary})
        ${token.colorBgContainer} !important;
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
      width: ACTIONS_COLUMN_WIDTH,
      // Pinned only where the viewport leaves room to still see the Name column beside it.
      fixed: screens.md ? ('right' as const) : undefined,
      render: (_: unknown, record: Form) => (
        <Space size="small">
          <CopyEmbedButton formId={record.id} />
          <Tooltip title={__('Preview form', 'paymentform')}>
            <Button
              type="text"
              size="small"
              aria-label={__('Preview', 'paymentform')}
              icon={<Eye size={16} />}
              onClick={() => navigate(formPreviewPath(record.id))}
            />
          </Tooltip>
          <Tooltip title={__('Edit form', 'paymentform')}>
            <Button
              type="text"
              size="small"
              aria-label={__('Edit form', 'paymentform')}
              icon={<Pencil size={16} />}
              loading={pendingPath === `/build/${record.id}`}
              onClick={() => {
                void openDeepLink(`/build/${record.id}`);
              }}
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

  const isCreatingForm = pendingPath === CREATE_FORM_PATH;
  const createFormButton = (
    <Button
      type="primary"
      icon={<Plus size={16} />}
      loading={isCreatingForm}
      onClick={() => {
        void openDeepLink(CREATE_FORM_PATH);
      }}
    >
      {isCreatingForm ? __('Redirecting…', 'paymentform') : __('Create form', 'paymentform')}
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
            {createFormButton}
          </Empty>
        ) : (
          <Table
            columns={columns}
            dataSource={filtered.map((form) => ({ ...form, key: form.id }))}
            pagination={{ pageSize: 10 }}
            scroll={{ x: 'max-content' }}
            rowClassName={() => rowHoverStyles}
          />
        )}
      </Card>
      <div style={{ marginTop: token.marginMD }}>
        <Typography.Text type="secondary" style={{ fontSize: token.fontSizeSM }}>
          {__(
            'Tip: paste a copied shortcode into any post or page. Use the arrow next to Copy shortcode for block markup or an iframe URL.',
            'paymentform'
          )}
        </Typography.Text>
      </div>
    </div>
  );
}
