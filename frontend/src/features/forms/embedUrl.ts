export function getEmbedUrl(formId: string): string {
  return `https://app.paymentform.io/embed/${encodeURIComponent(formId)}`;
}

export function getShortcode(formId: string): string {
  return `[paymentform id="${formId}"]`;
}

export function getGutenbergBlock(formId: string): string {
  return `<!-- wp:paymentform/form {"formId":"${formId}"} /-->`;
}
