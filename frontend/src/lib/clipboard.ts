/** Copies text to the clipboard, falling back to execCommand where the async API is unavailable (plain-http admin). */
export async function copyText(text: string): Promise<void> {
  if (navigator.clipboard?.writeText) {
    await navigator.clipboard.writeText(text);
    return;
  }
  copyViaHiddenTextarea(text);
}

/** Legacy copy path: select an off-screen textarea and ask the browser to copy it. */
function copyViaHiddenTextarea(text: string): void {
  const textarea = document.createElement('textarea');
  textarea.value = text;
  textarea.setAttribute('readonly', '');
  textarea.style.position = 'fixed';
  textarea.style.opacity = '0';
  document.body.appendChild(textarea);
  textarea.focus();
  textarea.select();
  try {
    if (!document.execCommand('copy')) {
      throw new Error('Clipboard copy was refused');
    }
  } finally {
    textarea.remove();
  }
}
