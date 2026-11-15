import { DatePipe } from '@angular/common';
import { Component, computed, ElementRef, HostListener, inject, input, OnInit, output, signal, viewChild } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { isActiveOrderStatus, ORDER_STATUSES, SalonLayout, StaffOrder } from '../../models/operations.models';
import { LocalOperationsApi, OperationsError } from '../../services/local-operations-api.service';

interface SalonTable {
  id: string | null;
  label: string;
  orders: StaffOrder[];
  assignable: boolean;
}

@Component({
  selector: 'app-operations-salon',
  imports: [DatePipe, FormsModule],
  templateUrl: './operations-salon.html',
  styleUrl: './operations-salon.scss',
})
export class OperationsSalon implements OnInit {
  readonly orders = input.required<StaffOrder[]>();
  readonly online = input(false);
  readonly newOrder = output<{ label: string; id: string | null }>();
  readonly orderDetails = output<StaffOrder>();
  readonly editOrder = output<StaffOrder>();
  readonly layoutSaved = output<void>();
  readonly statuses = ORDER_STATUSES;
  readonly layout = signal<SalonLayout | null>(null);
  readonly draft = signal<SalonLayout | null>(null);
  readonly confirmingClose = signal(false);
  readonly loading = signal(false);
  readonly saving = signal(false);
  readonly error = signal('');
  readonly editError = signal('');
  readonly outdated = signal(false);
  private readonly api = inject(LocalOperationsApi);
  private readonly dialog = viewChild.required<ElementRef<HTMLDialogElement>>('layoutDialog');
  private readonly heading = viewChild.required<ElementRef<HTMLHeadingElement>>('layoutHeading');
  private readonly keepEditing = viewChild<ElementRef<HTMLButtonElement>>('keepEditing');

  readonly zones = computed(() => {
    const byTable = new Map<string, SalonTable>();
    for (const order of this.orders()) {
      if (order.method !== 'table' || !isActiveOrderStatus(order.status)) continue;
      const raw = order.tableLabel?.trim() ?? '';
      const key = this.tableKey(raw);
      const table = byTable.get(key) ?? { id: null, label: raw || 'Sin mesa asignada', orders: [], assignable: !!raw };
      table.orders.push(order);
      byTable.set(key, table);
    }
    const zones = this.layout()?.zones.map(({ id, name, tables }) => ({
      id,
      name,
      tables: tables.map<SalonTable>(({ id, label }) => {
        const key = this.tableKey(label);
        const orders = byTable.get(key)?.orders ?? [];
        byTable.delete(key);
        return { id, label, orders, assignable: true };
      }),
    })) ?? [];
    if (byTable.size) zones.push({ id: 'unassigned', name: 'Pedidos sin mesa en el plano', tables: [...byTable.values()] });
    for (const zone of zones) {
      for (const table of zone.tables) table.orders.sort((a, b) => a.id - b.id);
    }
    return zones;
  });

  ngOnInit(): void {
    void this.loadLayout();
  }

  @HostListener('window:focus')
  async loadLayout(): Promise<void> {
    if (this.loading() || this.draft()) return;
    this.loading.set(true);
    this.error.set('');
    try {
      this.layout.set(await this.api.salonLayout());
    } catch {
      this.error.set('No se pudo cargar el plano del salón.');
    } finally {
      this.loading.set(false);
    }
  }

  startEditing(): void {
    const layout = this.layout();
    if (!layout || this.draft()) return;
    this.draft.set(structuredClone(layout));
    this.editError.set('');
    this.outdated.set(false);
    this.dialog().nativeElement.showModal();
    this.heading().nativeElement.focus();
  }

  cancelDialog(event: Event): void {
    event.preventDefault();
    if (this.confirmingClose()) this.continueEditing();
    else this.requestClose();
  }

  requestClose(): void {
    if (this.saving() || this.confirmingClose()) return;
    if (JSON.stringify(this.draft()) !== JSON.stringify(this.layout())) {
      this.confirmingClose.set(true);
      requestAnimationFrame(() => this.keepEditing()?.nativeElement.focus());
    } else {
      void this.closeEditor();
    }
  }

  continueEditing(): void {
    this.confirmingClose.set(false);
    const heading = this.heading().nativeElement;
    requestAnimationFrame(() => heading.focus());
  }

  async closeEditor(): Promise<void> {
    this.dialog().nativeElement.close();
    this.confirmingClose.set(false);
    this.draft.set(null);
    if (this.outdated()) await this.loadLayout();
  }

  addZone(layout: SalonLayout): void {
    layout.zones.push({ id: crypto.randomUUID(), name: '', tables: [] });
  }

  addTable(layout: SalonLayout, zone: SalonLayout['zones'][number]): void {
    const taken = new Set(layout.zones.flatMap(({ tables }) => tables.map(({ label }) => this.tableKey(label))));
    let number = 1;
    while (taken.has('M' + number)) number++;
    zone.tables.push({ id: crypto.randomUUID(), label: 'M' + number });
  }

  async saveLayout(layout: SalonLayout): Promise<void> {
    if (this.saving() || this.confirmingClose()) return;
    this.saving.set(true);
    this.editError.set('');
    try {
      this.layout.set(await this.api.saveSalonLayout(layout));
      this.outdated.set(false);
      await this.closeEditor();
      this.layoutSaved.emit();
    } catch (error) {
      this.editError.set(error instanceof Error ? error.message : 'No se pudo guardar el plano.');
      this.outdated.set(error instanceof OperationsError && error.status === 409);
    } finally {
      this.saving.set(false);
    }
  }

  statusLabel(order: StaffOrder): string {
    return this.statuses.find(({ id }) => id === order.status)?.label ?? order.status;
  }

  private tableKey(label: string): string {
    const value = label.trim();
    const numeric = /^(?:mesa\s*|m\s*)?0*(\d+)$/i.exec(value);
    return numeric ? 'M' + numeric[1] : value.toLocaleUpperCase('es-PE');
  }
}
