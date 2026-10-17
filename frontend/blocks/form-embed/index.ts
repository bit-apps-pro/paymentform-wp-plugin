import { registerBlockType } from '@wordpress/blocks';
import { createElement } from '@wordpress/element';
import metadata from './block.json';
import { Edit } from './edit';
import { Save } from './save';
import { BrandLogo } from './BrandLogo';

const INSERTER_ICON_SIZE_PX = 24;

type Attrs = { formId: string };

registerBlockType<Attrs>(metadata.name, {
  ...(metadata as unknown as { title: string; category: string }),
  attributes: {
    formId: { type: 'string', default: '' },
  },
  icon: { src: createElement(BrandLogo, { size: INSERTER_ICON_SIZE_PX }) },
  edit: Edit,
  save: Save,
});
