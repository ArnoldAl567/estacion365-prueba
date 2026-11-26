import { Injectable, signal } from '@angular/core';

@Injectable({ providedIn: 'root' })
export class CatalogCategoryLabels {
  readonly names = signal<Partial<Record<string, string>>>({});

  name(id: string, fallback: string): string {
    return this.names()[id] ?? fallback;
  }
}
