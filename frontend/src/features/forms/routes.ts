/** Router path of the forms list page. */
export const FORMS_PATH = '/forms';

/** Router path of the full-page device preview for one form. */
export function formPreviewPath(formId: string): string {
  return `${FORMS_PATH}/${encodeURIComponent(formId)}/preview`;
}
