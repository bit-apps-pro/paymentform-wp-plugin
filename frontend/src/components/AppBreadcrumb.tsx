import { Breadcrumb, Button, Flex, Tooltip, theme } from 'antd';
import type { BreadcrumbProps } from 'antd';
import { ArrowLeft } from 'lucide-react';
import { Link, useLocation, useNavigate } from 'react-router-dom';
import { FORMS_PATH } from '../features/forms/routes';
import { useFormsList } from '../features/forms/useFormsList';
import { __ } from '../lib/i18n';

/** Crumb label for a top-level route segment. */
function labelFor(segment: string): string {
  switch (segment) {
    case 'forms':
      return __('Forms', 'paymentform');
    case 'settings':
      return __('Settings', 'paymentform');
    case 'welcome':
      return __('Welcome', 'paymentform');
    case 'callback':
      return __('Connecting', 'paymentform');
    default:
      return segment;
  }
}

/** Form id when the route is a form preview (`/forms/:formId/preview`), otherwise null. */
function previewedFormId(segments: string[]): string | null {
  return segments[0] === 'forms' && segments[2] === 'preview' && segments[1] ? decodeURIComponent(segments[1]) : null;
}

/** Trailing crumb naming the previewed form, falling back to "Preview" until the list loads. */
function FormNameCrumb({ formId }: { formId: string }) {
  const { data: forms } = useFormsList();
  return <>{forms?.find((form) => form.id === formId)?.name ?? __('Preview', 'paymentform')}</>;
}

/** Breadcrumb trail for the current route, with a back arrow to Forms on a form preview. */
export function AppBreadcrumb() {
  const { token } = theme.useToken();
  const navigate = useNavigate();
  const location = useLocation();
  const segments = location.pathname.replace(/^\//, '').split('/');
  const section = segments[0] || 'welcome';
  const formId = previewedFormId(segments);
  const backLabel = __('Back to forms', 'paymentform');

  const items: BreadcrumbProps['items'] = [
    { title: __('PaymentForm', 'paymentform') },
    { title: formId ? <Link to={FORMS_PATH}>{labelFor(section)}</Link> : labelFor(section) },
    ...(formId ? [{ title: <FormNameCrumb formId={formId} /> }] : []),
  ];

  return (
    <Flex align="center" gap={token.marginXS} style={{ minWidth: 0 }}>
      {formId && (
        <Tooltip title={backLabel}>
          <Button
            type="text"
            size="small"
            aria-label={backLabel}
            icon={<ArrowLeft size={16} />}
            onClick={() => navigate(FORMS_PATH)}
          />
        </Tooltip>
      )}
      <Breadcrumb items={items} />
    </Flex>
  );
}
