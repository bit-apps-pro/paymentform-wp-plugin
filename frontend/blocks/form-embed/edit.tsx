import { useBlockProps } from '@wordpress/block-editor';
import { SelectControl, Placeholder, Spinner } from '@wordpress/components';
import { useState, useEffect } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';

type Attrs = { formId: string };
type SetAttrs = (next: Partial<Attrs>) => void;
type FormSummary = { id: string; name: string; status?: string };

export function Edit({ attributes, setAttributes }: { attributes: Attrs; setAttributes: SetAttrs }) {
  const blockProps = useBlockProps({ className: 'paymentform-embed-edit' });
  const [forms, setForms] = useState<FormSummary[] | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    apiFetch<{ data: FormSummary[] }>({ path: '/paymentform/v1/forms' })
      .then((res) => setForms(res.data ?? []))
      .catch((e) => setError(e?.message ?? 'Failed to load forms'));
  }, []);

  if (error) {
    return (
      <div {...blockProps}>
        <Placeholder
          icon="money-alt"
          label={__('PaymentForm', 'paymentform')}
          instructions={error}
        />
      </div>
    );
  }

  if (forms === null) {
    return (
      <div {...blockProps}>
        <Placeholder icon="money-alt" label={__('PaymentForm', 'paymentform')}>
          <Spinner />
        </Placeholder>
      </div>
    );
  }

  if (forms.length === 0) {
    return (
      <div {...blockProps}>
        <Placeholder
          icon="money-alt"
          label={__('PaymentForm', 'paymentform')}
          instructions={__('No forms yet. Create one in your PaymentForm account first.', 'paymentform')}
        />
      </div>
    );
  }

  return (
    <div {...blockProps}>
      <Placeholder icon="money-alt" label={__('PaymentForm', 'paymentform')}>
        <SelectControl
          label={__('Form', 'paymentform')}
          value={attributes.formId}
          options={[
            { label: __('— Select a form —', 'paymentform'), value: '' },
            ...forms.map((f) => ({ label: f.name, value: f.id })),
          ]}
          onChange={(value) => setAttributes({ formId: value })}
          __next40pxDefaultSize
          __nextHasNoMarginBottom
        />
      </Placeholder>
    </div>
  );
}
