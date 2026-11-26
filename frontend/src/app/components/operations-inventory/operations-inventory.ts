import { createTransientNotice } from '../../services/transient-notice';
import { DatePipe, DecimalPipe, NgTemplateOutlet } from '@angular/common';
import { Component, computed, DestroyRef, inject, input, OnInit, output, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { OperationsPageId, operationsPageUrl } from '../../config/operations-navigation';
import { InventoryView, PurchaseDetail } from '../../models/workflow.models';
import { LocalOperationsApi } from '../../services/local-operations-api.service';
import { RecordDialog } from '../record-dialog/record-dialog';
import { RemovalConfirmation } from '../../services/removal-confirmation.service';
import { OperationTimestampPipe } from '../../pipes/operation-timestamp.pipe';

interface StockLine { item_id: number | null; quantity: number | null; cost: number | null }
type InventoryEditor = 'items' | 'units' | 'suppliers' | 'purchases' | 'movements' | 'recipes' | 'consume' | 'cancel-purchase' | 'categories';


@Component({
  selector: 'app-operations-inventory',
  imports: [FormsModule, DatePipe, DecimalPipe, NgTemplateOutlet, RecordDialog, OperationTimestampPipe],
  templateUrl: './operations-inventory.html',
  styleUrl: './operations-inventory.scss',
})
export class OperationsInventory implements OnInit {
  private readonly api = inject(LocalOperationsApi);
  private readonly confirmation = inject(RemovalConfirmation);
  readonly editor = signal<InventoryEditor | null>(null);
  readonly purchaseDetailId = signal<number | null>(null);
  readonly purchaseDetail = signal<PurchaseDetail | null>(null);
  readonly recipeDetailId = signal<number | null>(null);
  readonly recipeDetail = computed(() => {
    const stock = this.data();
    const recipe = stock?.recipes.find((entry) => entry.id === this.recipeDetailId());
    if (!recipe || !stock) return null;
    const items = new Map(stock.items.map((item) => [item.id, item]));
    return { ...recipe, lines: recipe.components.map((component) => ({
      item_id: component.item_id,
      name: items.get(component.item_id)?.name ?? 'Insumo #' + component.item_id,
      symbol: items.get(component.item_id)?.symbol ?? '—',
      quantity_milli: component.quantity_milli,
    })) };
  });
  readonly detailLoading = signal(false);
  readonly detailError = signal('');
  private detailRequest = 0;
  readonly editorTitle = computed(() => {
    switch (this.editor()) {
      case 'items': return this.item.id ? 'Editar insumo' : 'Nuevo insumo';
      case 'categories': return this.category.id ? 'Editar categoría de insumos' : 'Nueva categoría de insumos';
      case 'units': return this.unit.id ? 'Editar unidad' : 'Nueva unidad';
      case 'suppliers': return this.supplier.id ? 'Editar proveedor' : 'Nuevo proveedor';
      case 'purchases': return 'Registrar compra';
      case 'cancel-purchase': return 'Anular compra #' + this.cancellation.id;
      case 'movements': return 'Registrar movimiento';
      case 'recipes': return this.data()?.recipes.some((entry) => entry.product_key === this.recipe.product_key) ? 'Editar receta' : 'Nueva receta';
      case 'consume': return 'Registrar consumo del pedido #' + this.confirmOrder;
      default: return '';
    }
  });
  private readonly requestIds = new Map<string, { id: string; payload: string }>();
  readonly data = signal<InventoryView | null>(null);
  readonly busy = signal(false);
  readonly error = signal('');
  private readonly successNotice = createTransientNotice();
  readonly notice = this.successNotice.value;
  readonly page = input<OperationsPageId>('inventory');
  readonly pageRequested = output<{event: MouseEvent; id: OperationsPageId}>();
  readonly ingredientTabs: {id: OperationsPageId; label: string}[] = [
    {id: 'inventory', label: 'Listado de insumos'}, {id: 'inventory-categories', label: 'Categorías'}, {id: 'inventory-units', label: 'Unidades de medida'},
  ];
  readonly tabUrl = operationsPageUrl;
  readonly view = computed(() => {
    switch (this.page()) {
      case 'inventory-suppliers': return 'suppliers';
      case 'inventory-categories': return 'categories';
      case 'inventory-units': return 'units';
      case 'inventory-purchases': return 'purchases';
      case 'inventory-movements': return 'movements';
      case 'inventory-production': return 'production';
      case 'inventory-orders': return 'orders';
      default: return 'items';
    }
  });
  readonly kindLabels: Record<string, string> = {
    carta: 'A la carta', fondo: 'Fondo del día', entrada: 'Entrada',
    entrada_adicional: 'Entrada adicional', guarnicion: 'Guarnición',
  };
  readonly money = (cents: number) => 'S/ ' + (cents / 100).toFixed(2);
  readonly quantity = (amount: number) => Math.round(amount * 1000);
  item = this.newItem();
  category = {id: undefined as number | undefined, name: ''};
  unit = { id: undefined as number | undefined, name: '', symbol: '' };
  supplier = { id: undefined as number | undefined, name: '', contact: '' };
  purchase = { supplier_id: null as number | null, reference: '', lines: [this.emptyLine()] };
  cancellation = { id: 0, reason: '' };
  movement = { type: 'entrada', useBatch: false, item_id: null as number | null,
    batch_id: null as number | null, quantity: 1, cost: 0, reason: '' };
  recipe = { product_key: '', mode: 'direct', batch_yield: 1, lines: [this.emptyLine()] };
  ingredientName(id: number): string { return this.data()?.items.find((item) => item.id === id)?.name ?? 'Insumo #' + id; }
  ingredientSymbol(id: number): string { return this.data()?.items.find((item) => item.id === id)?.symbol ?? ''; }
  confirmOrder: number | null = null;
  pages = { page: 1, batchPage: 1, orderPage: 1 };

  constructor() { inject(DestroyRef).onDestroy(() => this.detailRequest++); }

  async openPurchaseDetail(id: number): Promise<void> {
    const request = ++this.detailRequest;
    this.purchaseDetailId.set(id);
    this.purchaseDetail.set(null);
    this.detailError.set('');
    this.detailLoading.set(true);
    try {
      const detail = await this.api.workflow<PurchaseDetail>('/inventory/purchases/' + id);
      if (request === this.detailRequest) this.purchaseDetail.set(detail);
    } catch (error) {
      if (request === this.detailRequest) this.detailError.set(error instanceof Error ? error.message : 'No se pudo cargar la compra.');
    } finally {
      if (request === this.detailRequest) this.detailLoading.set(false);
    }
  }

  closePurchaseDetail(): void {
    this.detailRequest++;
    this.purchaseDetailId.set(null);
    this.purchaseDetail.set(null);
    this.detailLoading.set(false);
    this.detailError.set('');
  }

  ngOnInit(): void { void this.load(); }
  newItem() { return { id: undefined as number | undefined, name: '', category: '', category_id: null as number | null, unit_id: null as number | null, minimum_milli: 0 }; }
  emptyLine(): StockLine { return { item_id: null, quantity: null, cost: null }; }

  openEditor(editor: InventoryEditor): void {
    if (this.busy()) return;
    this.error.set('');
    this.editor.set(editor);
  }

  create(editor: InventoryEditor): void {
    if (editor === 'items') this.item = this.newItem();
    if (editor === 'categories') this.category = {id: undefined, name: ''};
    if (editor === 'units') this.unit = { id: undefined, name: '', symbol: '' };
    if (editor === 'suppliers') this.supplier = { id: undefined, name: '', contact: '' };
    if (editor === 'recipes') this.selectRecipe('');
    this.openEditor(editor);
  }

  closeEditor(): void {
    if (!this.busy()) this.editor.set(null);
  }

  async removeStockLine(lines: StockLine[], line: StockLine): Promise<void> {
    if (this.busy() || lines.length <= 1) return;
    const name = this.data()?.items.find((item) => item.id === line.item_id)?.name ?? 'este insumo';
    if (await this.confirmation.ask('Quitar insumo', 'Se quitará ' + name + ' del formulario.', 'Quitar insumo')) {
      const index = lines.indexOf(line);
      if (index >= 0 && lines.length > 1) lines.splice(index, 1);
    }
  }

  async load(): Promise<void> {
    await this.execute();
  }

  changePage(key: keyof OperationsInventory['pages'], step: number): void {
    this.pages[key] = Math.max(1, this.pages[key] + step);
    void this.load();
  }

  async saveMaster(kind: 'items' | 'units' | 'suppliers'): Promise<void> {
    const body = { items: { ...this.item, minimum_milli: this.quantity(this.item.minimum_milli) },
      units: this.unit, suppliers: this.supplier }[kind];
    if (!(await this.execute('/master/' + kind, body))) return;
    if (kind === 'items') this.item = this.newItem();
    if (kind === 'units') this.unit = { id: undefined, name: '', symbol: '' };
    if (kind === 'suppliers') this.supplier = { id: undefined, name: '', contact: '' };
  }

  private linePayload(line: StockLine) {
    return { item_id: line.item_id, quantity_milli: this.quantity(line.quantity ?? 0) };
  }

  async savePurchase(): Promise<void> {
    if (await this.execute('/purchases', { supplier_id: this.purchase.supplier_id, reference: this.purchase.reference,
      lines: this.purchase.lines.map((line) => ({ ...this.linePayload(line), cost_cents: Math.round((line.cost ?? 0) * 100) })) }, true)) {
      this.purchase = { supplier_id: null, reference: '', lines: [this.emptyLine()] };
    }
  }

  async saveMovement(): Promise<void> {
    const m = this.movement;
    const batch = m.type === 'merma' && m.useBatch;
    if (await this.execute('/movements', { type: m.type, reason: m.reason,
      item_id: batch ? null : m.item_id, batch_id: batch ? m.batch_id : null, quantity_milli: this.quantity(m.quantity),
      cost_cents: m.type === 'entrada' ? Math.round(m.cost * 100) : null }, true)) {
      this.movement.reason = '';
      this.pages.page = 1;
    }
  }

  selectRecipe(product: string): void {
    const recipe = this.data()?.recipes.find((entry) => entry.product_key === product);
    if (!recipe) {
      this.recipe = { product_key: product, mode: 'direct', batch_yield: 1, lines: [this.emptyLine()] };
      return;
    }
    this.recipe = { product_key: recipe.product_key, mode: recipe.mode, batch_yield: recipe.batch_yield ?? 1,
      lines: recipe.components.map((line) => ({ item_id: line.item_id, quantity: line.quantity_milli / 1000, cost: null })) };
  }

  editRecipe(product: string): void {
    this.selectRecipe(product);
    this.openEditor('recipes');
  }

  validLines(lines: StockLine[], withCost = false): boolean {
    return lines.every((line) => !!line.item_id && (line.quantity ?? 0) >= .001 && (!withCost || (line.cost ?? 0) >= .01))
      && new Set(lines.map((line) => line.item_id)).size === lines.length;
  }

  duplicateItems(lines: StockLine[]): Map<number, string> {
    const seen = new Set<number>();
    const duplicates = new Map<number, string>();
    for (const line of lines) {
      if (!line.item_id) continue;
      if (seen.has(line.item_id)) {
        duplicates.set(line.item_id, this.data()?.items.find((item) => item.id === line.item_id)?.name ?? 'seleccionado');
      }
      seen.add(line.item_id);
    }
    return duplicates;
  }

  async saveRecipe(): Promise<void> {
    await this.execute('/recipes', { product_key: this.recipe.product_key, mode: this.recipe.mode,
      batch_yield: this.recipe.batch_yield,
      components: this.recipe.lines.map((line) => this.linePayload(line)) });
  }

  async saveCategory(): Promise<void> { await this.execute('/categories', this.category); }
  async deleteCategory(id: number, name: string): Promise<void> {
    if (this.busy() || !(await this.confirmation.ask('Eliminar categoría de insumos', 'Se eliminará «' + name + '» si no tiene insumos asignados. Esta acción no se puede deshacer.'))) return;
    await this.execute('/categories/' + id + '/delete', {});
  }

  async deleteUnit(id: number, name: string): Promise<void> {
    if (this.busy()) return;
    if (!(await this.confirmation.ask('Eliminar unidad', 'Se eliminará la unidad «' + name + '». Esta acción no se puede deshacer.', 'Eliminar unidad'))) return;
    if (await this.execute('/units/' + id + '/delete', {})) {
      if (this.unit.id === id) this.unit = { id: undefined, name: '', symbol: '' };
      this.successNotice.show('Unidad eliminada.');
    }
  }

  async removeRecord(kind: string, id: number, name: string, expectedStatus?: string): Promise<void> {
    if (this.busy()) return;
    const message = kind === 'orders'
      ? 'Se solicitará cancelar el pedido ' + name + '. Los pedidos entregados o cobrados conservan su historial.'
      : 'Se eliminará «' + name + '» si no tiene stock, movimientos ni registros relacionados. Esta acción no se puede deshacer.';
    if (!(await this.confirmation.ask('Eliminar registro', message, kind === 'orders' ? 'Cancelar pedido' : 'Eliminar'))) return;
    await this.execute('/remove/' + kind + '/' + id, {expectedStatus});
  }

  async cancelPurchase(): Promise<void> {
    if (this.busy() || this.cancellation.reason.trim().length < 3) return;
    if (!(await this.confirmation.ask('Anular compra #' + this.cancellation.id,
      'Se revertirán las cantidades y el valor ingresados por esta compra. La compra y el motivo se conservarán en el historial. No se puede deshacer.', 'Anular compra'))) return;
    if (await this.execute('/purchases/' + this.cancellation.id + '/cancel', {reason: this.cancellation.reason.trim()})) {
      this.successNotice.show('Compra anulada. Se revirtió su entrada de stock y valor.');
    }
  }

  async consume(id: number): Promise<void> {
    this.pages.orderPage = 1;
    if (await this.execute('/orders/' + id + '/consume', {})) this.confirmOrder = null;
  }

  private async execute(path = '', payload?: object, idempotent = false): Promise<boolean> {
    if (this.busy()) return false;
    this.busy.set(true);
    this.error.set('');
    this.successNotice.show('');
    let saved = false;
    try {
      if (path && payload) {
        const json = JSON.stringify(payload);
        const previous = this.requestIds.get(path);
        const request = previous?.payload === json ? previous : { id: crypto.randomUUID(), payload: json };
        if (idempotent) this.requestIds.set(path, request);
        const result = await this.api.workflow<{ consumption?: { covered_lines: number; total_lines: number } }>(
          '/inventory' + path, idempotent ? { ...payload, request_id: request.id } : payload,
        );
        this.requestIds.delete(path);
        saved = true;
        this.successNotice.show(result.consumption ? `Consumo registrado para ${result.consumption.covered_lines} de ${result.consumption.total_lines} líneas.` : 'Operación registrada.');
        this.editor.set(null);
      }
      const search = new URLSearchParams(Object.entries(this.pages).map(([key, value]) => [key, String(value)]));
      this.data.set(await this.api.workflow<InventoryView>('/inventory?' + search));
      return true;
    } catch (error) {
      this.error.set(error instanceof Error ? error.message : 'No se pudo completar la operación.');
      return saved;
    } finally {
      this.busy.set(false);
    }
  }
}
