import { AfterViewInit, Component, computed, ElementRef, inject, input, OnInit, output, signal, viewChild } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { CATEGORIES, DAILY_ADDON_CATEGORIES } from '../../data/menu.data';
import { CatalogItem, DailyPrices, DEFAULT_DAILY_PRICES } from '../../models/catalog.models';
import { OrderModifier, StaffOrder, StaffOrderDraft } from '../../models/operations.models';
import { LocalOperationsApi } from '../../services/local-operations-api.service';
import { CatalogCategoryLabels } from '../../services/catalog-category-labels.service';
import { RemovalConfirmation } from '../../services/removal-confirmation.service';

interface DraftLine {
  key: number;
  productId: string;
  name: string;
  categoryId: string;
  unitPriceCents: number;
  quantity: number;
  notes: string;
  modifiers: string;
}

@Component({
  selector: 'app-operations-order-form',
  imports: [FormsModule],
  templateUrl: './operations-order-form.html',
  styleUrl: './operations-order-form.scss',
})
export class OperationsOrderForm implements OnInit, AfterViewInit {
  readonly order = input.required<StaffOrder | null>();
  readonly initialTable = input<string | null>(null);
  readonly initialTableId = input<string | null>(null);
  readonly initialMethod = input<StaffOrder['method']>('table');
  readonly saved = output<StaffOrder>();
  readonly closed = output<void>();
  readonly categories = [...CATEGORIES, ...DAILY_ADDON_CATEGORIES];
  readonly categoryLabels = inject(CatalogCategoryLabels);
  readonly menuTypes = [
    { id: 'economico' as const, label: 'Económico' },
    { id: 'ejecutivo' as const, label: 'Ejecutivo' },
  ];
  readonly loading = signal(true);
  readonly loaded = signal(false);
  readonly saving = signal(false);
  readonly confirmingClose = signal(false);
  readonly error = signal('');
  readonly modifierError = signal('');
  readonly loadingModifiers = signal(false);
  readonly modifiers = signal<OrderModifier[]>([]);
  readonly catalog = signal<CatalogItem[]>([]);
  readonly prices = signal<DailyPrices>(DEFAULT_DAILY_PRICES);
  readonly category = signal('');
  readonly query = signal('');
  readonly matches = computed(() => {
    const query = this.searchText(this.query());
    const category = this.category();
    return this.catalog().filter((item) =>
      (category === 'menu-dia'
        ? item.kind === 'fondo'
        : item.kind === 'carta' && item.price !== null && (!category || item.category_id === category))
      && this.searchText(item.name).includes(query),
    );
  });
  readonly starters = computed(() => this.catalog().filter((item) => item.kind === 'entrada'));
  readonly customer: StaffOrderDraft['customer'] = {
    method: 'table', name: '', phone: '', tableLabel: '', payment: null, address: '', reference: '',
  };
  lines: DraftLine[] = [];
  productKey = '';
  starterKey = '';
  menuType: 'economico' | 'ejecutivo' = 'economico';
  private nextKey = 0;
  private initialDraft = '';
  private lastAttempt?: { body: string; id: string };
  private readonly api = inject(LocalOperationsApi);
  private readonly confirmation = inject(RemovalConfirmation);
  private readonly dialog = viewChild.required<ElementRef<HTMLDialogElement>>('orderDialog');
  private readonly heading = viewChild.required<ElementRef<HTMLHeadingElement>>('orderHeading');
  private readonly closeButton = viewChild.required<ElementRef<HTMLButtonElement>>('closeButton');
  private readonly keepEditing = viewChild<ElementRef<HTMLButtonElement>>('keepEditing');

  ngOnInit(): void {
    const order = this.order();
    if (order) {
      Object.assign(this.customer, {
        method: order.method, name: order.customerName, phone: order.customerPhone,
        tableLabel: order.tableLabel ?? '', payment: order.payment,
        address: order.address ?? '', reference: order.reference ?? '',
      });
      this.lines = order.items.map((item) => ({
        ...item,
        key: this.nextKey++,
        modifiers: (item.modifiers ?? []).join(', '),
      }));
    } else {
      this.customer.method = this.initialMethod();
      this.customer.tableLabel = this.initialTable() ?? '';
    }
    this.initialDraft = JSON.stringify(this.buildDraft());
    void this.loadCatalog();
    void this.loadModifiers();
  }

  ngAfterViewInit(): void {
    this.dialog().nativeElement.showModal();
    this.heading().nativeElement.focus();
  }

  cancelDialog(event: Event): void {
    event.preventDefault();
    if (this.confirmingClose()) {
      this.continueEditing();
    } else {
      this.requestClose();
    }
  }

  requestClose(): void {
    if (this.saving() || this.confirmingClose()) return;
    if (this.initialDraft !== JSON.stringify(this.buildDraft())) {
      this.confirmingClose.set(true);
      requestAnimationFrame(() => this.keepEditing()?.nativeElement.focus());
      return;
    }
    this.discardOrder();
  }

  continueEditing(): void {
    this.confirmingClose.set(false);
    const button = this.closeButton().nativeElement;
    requestAnimationFrame(() => button.focus());
  }

  discardOrder(): void {
    this.dialog().nativeElement.close();
    this.closed.emit();
  }

  async loadModifiers(): Promise<void> {
    this.loadingModifiers.set(true);
    this.modifierError.set('');
    try {
      this.modifiers.set((await this.api.orderModifiers()).modifiers);
    } catch {
      this.modifierError.set('No se pudieron cargar las opciones. Puedes escribirlas en cada plato.');
    } finally {
      this.loadingModifiers.set(false);
    }
  }

  applyModifier(line: DraftLine, name: string): void {
    const options = this.lineModifiers(line);
    if (name && !options.includes(name) && options.length < 8) {
      line.modifiers = [...options, name].join(', ');
    }
  }

  async loadCatalog(): Promise<void> {
    this.loading.set(true);
    this.error.set('');
    try {
      const { items, prices } = await this.api.catalog();
      this.catalog.set(items.filter((item) => item.active));
      this.prices.set(prices);
      this.loaded.set(true);
    } catch {
      this.error.set('No se pudo cargar el catálogo. Vuelve a intentarlo.');
    } finally {
      this.loading.set(false);
    }
  }

  changeCategory(category: string): void {
    this.category.set(category);
    this.productKey = '';
  }

  addLine(): void {
    const product = this.matches().find((item) => item.item_key === this.productKey);
    if (!product || this.lines.length >= 100) return;
    const starter = this.starters().find((item) => item.item_key === this.starterKey);
    if (product.kind === 'fondo' && !starter) return;

    let productId = product.source_id;
    let name = product.name;
    let categoryId = product.category_id ?? '';
    let price = product.price ?? 0;
    if (product.kind === 'fondo' && starter) {
      const type = this.menuTypes.find(({ id }) => id === this.menuType)?.label;
      productId = ['menu-dia', product.source_id, starter.source_id, this.menuType].join('__');
      name = 'Menú ' + type + ' · ' + product.name + ' / ' + starter.name;
      categoryId = 'menu-dia';
      price = this.prices()[this.menuType];
    }
    const original = this.order()?.items.find((item) => item.productId === productId);
    this.lines.push({
      key: this.nextKey++, productId, name, categoryId,
      unitPriceCents: original?.unitPriceCents ?? Math.round(price * 100),
      quantity: 1, notes: '', modifiers: '',
    });
    this.productKey = '';
  }

  async removeLine(key: number): Promise<void> {
    if (this.saving()) return;
    const line = this.lines.find((line) => line.key === key);
    if (line && await this.confirmation.ask('Quitar producto', 'Se quitará «' + line.name + '» de este pedido.', 'Quitar producto')) {
      this.lines = this.lines.filter((line) => line.key !== key);
    }
  }

  get subtotal(): number {
    return this.lines.reduce((total, line) => total + line.unitPriceCents * (line.quantity || 0), 0);
  }

  get packaging(): number {
    return this.customer.method === 'table'
      ? 0
      : this.lines.reduce((total, line) => total + (line.categoryId === 'bebidas' ? 0 : (line.quantity || 0) * 100), 0);
  }

  amount(cents: number): string {
    return 'S/ ' + (cents / 100).toFixed(2);
  }

  async submit(): Promise<void> {
    if (!this.lines.length || this.saving() || this.confirmingClose()) return;
    this.error.set('');
    const draft = this.buildDraft();
    if (draft.items.some((line) => line.modifiers!.length > 8 || line.modifiers!.some((value) => value.length > 40))) {
      this.error.set('Cada plato admite hasta 8 indicaciones de 40 caracteres cada una.');
      return;
    }
    const body = JSON.stringify(draft);
    if (this.lastAttempt?.body !== body) this.lastAttempt = { body, id: crypto.randomUUID() };
    this.saving.set(true);
    try {
      const { order } = await this.api.saveStaffOrder(draft, this.order(), this.lastAttempt.id);
      this.dialog().nativeElement.close();
      this.saved.emit(order);
    } catch (error) {
      this.error.set(error instanceof Error ? error.message : 'No se pudo guardar el pedido.');
    } finally {
      this.saving.set(false);
    }
  }

  private buildDraft(): StaffOrderDraft {
    const tableId = this.initialTableId();
    return {
      customer: {
        ...this.customer,
        ...(this.customer.method === 'table' && this.customer.tableLabel === this.initialTable() && tableId ? { tableId } : {}),
      },
      items: this.lines.map(({ productId, quantity, notes, modifiers }) => ({
        productId, quantity, notes, modifiers: this.lineModifiers({ modifiers }),
      })),
      expectedTotalCents: this.subtotal + this.packaging,
    };
  }

  private searchText(value: string): string {
    return value.normalize('NFD').replace(/\p{Diacritic}/gu, '').toLowerCase().trim();
  }

  private lineModifiers(line: Pick<DraftLine, 'modifiers'>): string[] {
    return line.modifiers.split(',').map((value) => value.trim()).filter(Boolean);
  }
}
