import { Injectable, signal } from '@angular/core';

interface RemovalRequest { title: string; message: string; action: string }

@Injectable({ providedIn: 'root' })
export class RemovalConfirmation {
  readonly pending = signal<RemovalRequest | null>(null);
  private resolve?: (confirmed: boolean) => void;

  ask(title: string, message: string, action = 'Eliminar'): Promise<boolean> {
    if (this.pending()) return Promise.resolve(false);
    this.pending.set({ title, message, action });
    return new Promise<boolean>((resolve) => { this.resolve = resolve; });
  }

  finish(confirmed: boolean): void {
    const resolve = this.resolve;
    this.resolve = undefined;
    this.pending.set(null);
    resolve?.(confirmed);
  }
}
