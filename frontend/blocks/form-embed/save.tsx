import { useBlockProps } from '@wordpress/block-editor';

type Attrs = { formId: string };

export function Save({ attributes }: { attributes: Attrs }) {
  if (!attributes.formId) return null;
  const blockProps = useBlockProps.save({
    className: 'paymentform-embed',
    'data-form-id': attributes.formId,
  });
  return <div {...blockProps} />;
}
