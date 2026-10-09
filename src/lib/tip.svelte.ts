/* One shared tooltip for every hoverable mark (heat cells, bars, map dots).
   Values lead, labels follow; content is plain data, rendered as text. Shows on focus too. */
import type { Action } from 'svelte/action';

export interface TipLine { label?: string; value: string; color?: string }
export interface TipContent { title: string; lines?: TipLine[]; note?: string }

export const tipState = $state({ open: false, x: 0, y: 0, content: null as TipContent | null });

function show(content: TipContent, x: number, y: number) {
  tipState.content = content;
  tipState.x = x;
  tipState.y = y;
  tipState.open = true;
}
export const hideTip = () => { tipState.open = false; };

/** use:tip={{ title, lines }} — pointer and keyboard. Pass null to disable. */
export const tip: Action<HTMLElement | SVGElement, TipContent | null> = (node, initial) => {
  let content = initial;
  const move = (e: PointerEvent) => content && show(content, e.clientX, e.clientY);
  const focus = () => {
    if (!content) return;
    const r = node.getBoundingClientRect();
    show(content, r.left + r.width / 2, r.top);
  };
  node.addEventListener('pointerenter', move as EventListener);
  node.addEventListener('pointermove', move as EventListener);
  node.addEventListener('pointerleave', hideTip);
  node.addEventListener('focus', focus);
  node.addEventListener('blur', hideTip);
  return {
    update(c) { content = c; if (tipState.open && c) tipState.content = c; },
    destroy() {
      node.removeEventListener('pointerenter', move as EventListener);
      node.removeEventListener('pointermove', move as EventListener);
      node.removeEventListener('pointerleave', hideTip);
      node.removeEventListener('focus', focus);
      node.removeEventListener('blur', hideTip);
      hideTip();
    },
  };
};
