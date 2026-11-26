import { Component, inject, OnInit, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { StaffOrder, staffOrderMethod } from '../../models/operations.models';
import { LocalOperationsApi } from '../../services/local-operations-api.service';
import { OperationTimestampPipe } from '../../pipes/operation-timestamp.pipe';
import { RecordDialog } from '../record-dialog/record-dialog';

interface CashPayment {
  id: number; order_id: number; amount_cents: number; method: string; method_name: string;
  paid_at: string; shift_id: number; shift_name: string; cashier_name: string;
  delivery_method: string; table_label: string | null; table_closed_at: string | null; status: string;
}
interface PaymentHistory {
  records: { data: CashPayment[]; current_page: number; last_page: number; total: number };
  total_cents: number;
  tables: string[];
  shifts: string[];
  methods: { method: string; method_name: string }[];
}

@Component({
  selector: 'app-cash-payment-history',
  imports: [FormsModule, RecordDialog, OperationTimestampPipe],
  templateUrl: './cash-payment-history.html',
  styles: `
    :host { display: block; min-width: 0; }
    .payment-history-filters { width: 100%; justify-items: stretch; }
    .payment-history-filters .workflow-grid {
      width: 100%; grid-template-columns: repeat(auto-fit, minmax(min(180px, 100%), 1fr));
    }
    .payment-history-filters label { width: 100%; }
    .payment-detail-meta { display: grid; gap: 8px; }
  `,
})
export class CashPaymentHistory implements OnInit {
  private readonly api = inject(LocalOperationsApi);
  readonly data = signal<PaymentHistory | null>(null);
  readonly options = signal<Pick<PaymentHistory, 'tables' | 'shifts' | 'methods'>>({ tables: [], shifts: [], methods: [] });
  readonly busy = signal(false);
  readonly error = signal('');
  readonly detail = signal<{ payment: CashPayment; order: StaffOrder } | null>(null);
  readonly detailOpen = signal(false);
  readonly detailBusy = signal(false);
  readonly detailError = signal('');
  filters = { from: '', to: '', table: '', shift: '', method: '' };
  private appliedFilters = { ...this.filters };
  readonly money = (cents: number) => 'S/ ' + (cents / 100).toFixed(2);
  readonly staffOrderMethod = staffOrderMethod;
  readonly service = (payment: CashPayment) => payment.delivery_method === 'table'
    ? 'Mesa ' + (payment.table_label || 'sin nombre')
    : payment.delivery_method === 'delivery' ? 'Delivery' : 'Para llevar';

  ngOnInit(): void { void this.load(); }

  async applyFilters(): Promise<void> {
    if (this.busy()) return;
    this.appliedFilters = { ...this.filters };
    await this.load();
  }

  async clearFilters(): Promise<void> {
    this.filters = { from: '', to: '', table: '', shift: '', method: '' };
    await this.applyFilters();
  }

  async load(page = 1): Promise<void> {
    if (this.busy()) return;
    this.busy.set(true);
    this.error.set('');
    try {
      const query = new URLSearchParams({ page: String(page) });
      for (const [key, value] of Object.entries(this.appliedFilters)) {
        if (value.trim()) query.set(key, value.trim());
      }
      const data = await this.api.workflow<PaymentHistory>('/cash/payments?' + query);
      this.data.set(data);
      this.options.set({ tables: data.tables, shifts: data.shifts, methods: data.methods });
    } catch (error) {
      // Do not leave a previous result visible under newly applied filters.
      this.data.set(null);
      this.error.set(error instanceof Error ? error.message : 'No se pudo consultar el historial.');
    } finally { this.busy.set(false); }
  }

  async openDetail(id: number): Promise<void> {
    if (this.detailBusy()) return;
    this.detail.set(null);
    this.detailError.set('');
    this.detailOpen.set(true);
    this.detailBusy.set(true);
    try {
      this.detail.set(await this.api.workflow('/cash/payments/' + id));
    } catch (error) {
      this.detailError.set(error instanceof Error ? error.message : 'No se pudo consultar el cobro.');
    } finally { this.detailBusy.set(false); }
  }
}
