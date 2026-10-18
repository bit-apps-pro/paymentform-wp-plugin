import type { ReactNode } from 'react';
import { createPortal } from 'react-dom';
import { useOutletContext } from 'react-router-dom';

/** Outlet context AppShell hands its pages: the breadcrumb bar's trailing actions slot. */
export interface ShellOutletContext {
  headerActionsSlot: HTMLElement | null;
}

/** Renders a page's controls into the shell's breadcrumb bar, keeping the page header to one row. */
export function HeaderActions({ children }: { children: ReactNode }) {
  const slot = useOutletContext<ShellOutletContext | undefined>()?.headerActionsSlot;
  return slot ? createPortal(children, slot) : null;
}
