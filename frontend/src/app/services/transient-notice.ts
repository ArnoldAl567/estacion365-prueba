import { DestroyRef, inject, NgZone, signal } from '@angular/core';

/** Component-local success messages with a fresh reading period for every operation. */
export function createTransientNotice(shouldDismiss: () => boolean = () => true) {
  const destroyRef = inject(DestroyRef);
  const zone = inject(NgZone);
  const value = signal('');
  let timer: number | undefined;
  let destroyed = false;

  const clearTimer = () => {
    if (timer !== undefined) window.clearTimeout(timer);
    timer = undefined;
  };

  destroyRef.onDestroy(() => {
    destroyed = true;
    clearTimer();
  });

  return {
    value: value.asReadonly(),
    show(message: string): void {
      if (destroyed) return;
      clearTimer();
      value.set(message);
      if (message && shouldDismiss()) {
        timer = zone.runOutsideAngular(() => window.setTimeout(() => {
          timer = undefined;
          zone.run(() => value.set(''));
        }, 4000));
      }
    },
  };
}
