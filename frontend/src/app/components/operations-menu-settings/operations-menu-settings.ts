import { createTransientNotice } from '../../services/transient-notice';
import { Component, computed, effect, ElementRef, inject, input, signal, untracked, viewChild } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { OperationsPageId } from '../../config/operations-navigation';
import { MENU_CATEGORY_LABELS } from '../../config/menu-category-labels';
import { OrderModifier } from '../../models/operations.models';
import { CatalogItem } from '../../models/catalog.models';
import { OperationsSettings } from '../../models/workflow.models';
import { LocalOperationsApi } from '../../services/local-operations-api.service';
import { RemovalConfirmation } from '../../services/removal-confirmation.service';

interface CategoryRow {
  id: string; name: string; center: string; centerCode: string; total: number; available: number;
}
type MenuEditor =
  | { kind: 'category'; id: string; name: string; center: string }
  | { kind: 'modifier'; id: number; name: string };

@Component({
  selector: 'app-operations-menu-settings',
  imports: [FormsModule],
  templateUrl: './operations-menu-settings.html',
})
export class OperationsMenuSettings {
  private readonly api = inject(LocalOperationsApi);
  private readonly confirmation = inject(RemovalConfirmation);
  private readonly loaded = new Set<OperationsPageId>();
  readonly page = input.required<OperationsPageId>();
  readonly busy = signal(false);
  readonly error = signal('');
  private readonly successNotice = createTransientNotice();
  readonly notice = this.successNotice.value;
  private readonly catalogItems = signal<CatalogItem[]>([]);
  private readonly settings = signal<OperationsSettings | null>(null);
  private readonly dialog = viewChild.required<ElementRef<HTMLDialogElement>>('editDialog');
  readonly editing = signal<MenuEditor | null>(null);
  readonly editError = signal('');
  readonly centers = computed(() => this.settings()?.centers.filter((entry) => entry.active) ?? []);
  readonly categories = computed<CategoryRow[]>(() => {
    const counts = new Map<string, { total: number; available: number }>();
    for (const item of this.catalogItems()) {
      const id = item.kind === 'carta' ? item.category_id ?? 'sin-categoria' : 'menu-dia';
      const count = counts.get(id) ?? { total: 0, available: 0 };
      count.total++;
      if (item.active) count.available++;
      counts.set(id, count);
    }
    const settings = this.settings();
    const routes = new Map(settings?.routes.map((entry) => [entry.category, entry.center]));
    const centers = new Map(this.centers().map((entry) => [entry.code, entry.name]));
    return Array.from(counts, ([id, totals]) => {
      const configured = routes.get(id);
      const centerCode = configured && centers.has(configured) ? configured : 'kitchen';
      return {
        id, centerCode, center: centers.get(centerCode) ?? 'Cocina',
        name: settings?.categoryLabels?.[id] ?? MENU_CATEGORY_LABELS[id] ?? id,
        ...totals,
      };
    }).sort((a, b) => a.name.localeCompare(b.name, 'es'));
  });
  readonly modifiers = signal<OrderModifier[]>([]);
  readonly query = signal('');
  readonly matching = computed(() => {
    const query = this.query().trim().toLocaleLowerCase('es-PE');
    return this.categories().filter((entry) => entry.name.toLocaleLowerCase('es-PE').includes(query));
  });

  constructor() {
    effect(() => {
      const page = this.page();
      if (!this.loaded.has(page)) untracked(() => void this.load(page));
    });
  }

  async load(page: OperationsPageId): Promise<void> {
    this.busy.set(true);
    this.error.set('');
    this.successNotice.show('');
    try {
      if (page === 'categories') {
        const [{ items }, settings] = await Promise.all([
          this.api.catalog(),
          this.api.workflow<OperationsSettings>('/operations-settings'),
        ]);
        this.catalogItems.set(items);
        this.settings.set(settings);
      } else {
        this.modifiers.set((await this.api.orderModifiers()).modifiers);
      }
      this.loaded.add(page);
    } catch (error) {
      this.error.set(error instanceof Error ? error.message : 'No se pudo cargar el menú.');
    } finally {
      this.busy.set(false);
    }
  }

  editCategory(row: CategoryRow): void {
    this.openEdit({ kind: 'category', id: row.id, name: row.name, center: 'kitchen' });
  }

  async removeCategory(row: CategoryRow): Promise<void> {
    if (this.busy() || !(await this.confirmation.ask('Eliminar categoría', 'Se eliminará «' + row.name + '» únicamente si no contiene productos. Reasigna sus productos antes de continuar.'))) return;
    await this.update(async () => {
      this.settings.set(await this.api.workflow<OperationsSettings>('/operations-settings/remove/categories/' + encodeURIComponent(row.id), {}));
      this.successNotice.show('Categoría eliminada.');
    });
  }

  editModifier(modifier: OrderModifier): void {
    this.openEdit({ kind: 'modifier', id: modifier.id, name: modifier.name });
  }

  newModifier(): void {
    this.openEdit({ kind: 'modifier', id: 0, name: '' });
  }

  private openEdit(editor: MenuEditor): void {
    const panel = this.dialog().nativeElement;
    this.editError.set('');
    this.editing.set(editor);
    panel.showModal();
    requestAnimationFrame(() => panel.querySelector('input')?.focus());
  }

  closeEdit(event?: Event): void {
    event?.preventDefault();
    if (!this.busy()) this.dialog().nativeElement.close();
  }

  async saveEdit(editor: MenuEditor): Promise<void> {
    if (this.busy() || !editor.name.trim()) return;
    this.busy.set(true);
    this.editError.set('');
    this.successNotice.show('');
    try {
      if (editor.kind === 'category') {
        this.settings.set(await this.api.updateCategory(editor.id, editor.name.trim(), editor.center));
        this.successNotice.show('Categoría actualizada.');
      } else {
        const result = editor.id
          ? await this.api.updateOrderModifier(editor.id, editor.name.trim())
          : await this.api.createOrderModifier(editor.name.trim());
        this.modifiers.set(result.modifiers);
        this.successNotice.show(editor.id ? 'Modificador actualizado.' : 'Modificador guardado.');
      }
      this.dialog().nativeElement.close();
    } catch (error) {
      this.editError.set(error instanceof Error ? error.message : 'No se pudo guardar el cambio.');
    } finally {
      this.busy.set(false);
    }
  }

  async removeModifier(modifier: OrderModifier): Promise<void> {
    if (this.busy() || !(await this.confirmation.ask('Eliminar modificador', 'Se eliminará la opción «' + modifier.name + '».'))) return;
    await this.update(async () => {
      await this.api.deleteOrderModifier(modifier.id);
      this.modifiers.update((items) => items.filter((item) => item.id !== modifier.id));
      this.successNotice.show('Modificador eliminado.');
    });
  }

  private async update(action: () => Promise<void>): Promise<void> {
    this.busy.set(true);
    this.error.set('');
    this.successNotice.show('');
    try {
      await action();
    } catch (error) {
      this.error.set(error instanceof Error ? error.message : 'No se pudo guardar el cambio.');
    } finally {
      this.busy.set(false);
    }
  }
}
