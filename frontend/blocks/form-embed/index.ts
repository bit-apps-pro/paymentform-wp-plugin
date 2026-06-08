import { registerBlockType } from '@wordpress/blocks';
import metadata from './block.json';
import { Edit } from './edit';
import { Save } from './save';

type Attrs = { formId: string };

registerBlockType<Attrs>(metadata.name, {
  ...(metadata as unknown as { title: string; category: string }),
  attributes: {
    formId: { type: 'string', default: '' },
  },
  edit: Edit,
  save: Save,
});
