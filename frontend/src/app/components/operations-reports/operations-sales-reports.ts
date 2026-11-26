import { DatePipe, DecimalPipe } from '@angular/common';
import { Component, computed, effect, inject, input, signal, untracked } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { MENU_CATEGORY_LABELS } from '../../config/menu-category-labels';
import { OperationsPageId } from '../../config/operations-navigation';
import { ORDER_STATUSES } from '../../models/operations.models';
import { LocalOperationsApi } from '../../services/local-operations-api.service';

interface SalesRow {
  series: string;
  category: string;
  name: string;
  units: number;
  amount_cents: number;
}
interface BaseReport { from: string; to: string }
interface SalesSummary extends BaseReport {
  days: { day: string; amount_cents: number; orders: number }[];
  payments: { method: string; name: string; amount_cents: number; orders: number }[];
  orders: { status: string; count: number }[];
  expenseTotal: number;
}
interface SalesDetail extends BaseReport {
  days: { day: string; series: string; amount_cents: number }[];
  rows: SalesRow[];
  staffOptions: { id: number; name: string }[];
  categories: string[];
  categoryLabels?: Partial<Record<string, string>>;
}
type Group = 'summary' | 'article' | 'category';
type Preset = 'today' | 'week' | 'month';
type Series = { key: string; name: string; color: string; amount: number };

const COLORS = ['#176447', '#31859c', '#8c672c', '#6b60a7', '#477f3b', '#8399a5'];
const DAY_MS = 86400000;

@Component({
  selector: 'app-operations-sales-reports',
  imports: [FormsModule, DatePipe, DecimalPipe],
  templateUrl: './operations-sales-reports.html',
  styleUrl: './operations-sales-reports.scss',
})
export class OperationsSalesReports {
  private readonly api = inject(LocalOperationsApi);
  private readonly currency = new Intl.NumberFormat('es-PE', { style: 'currency', currency: 'PEN' });
  private readonly dayFormat = new Intl.DateTimeFormat('es-PE', { timeZone: 'UTC', day: 'numeric', month: 'short' });
  private readonly today = new Intl.DateTimeFormat('sv-SE', { timeZone: 'America/Lima' }).format(new Date());
  private requestNumber = 0;
  readonly page = input.required<OperationsPageId>();
  readonly group = computed<Group>(() => {
    if (this.page() === 'report-articles') return 'article';
    if (this.page() === 'report-categories') return 'category';
    return 'summary';
  });
  readonly summary = signal<SalesSummary | null>(null);
  readonly detail = signal<SalesDetail | null>(null);
  readonly busy = signal(false);
  readonly error = signal('');
  readonly applied = signal<{ from: string; to: string; description: string } | null>(null);
  readonly preset = signal<Preset | null>('month');
  readonly granularity = signal<'day' | 'week'>('day');
  readonly search = signal('');
  readonly orderBy = signal<'amount' | 'units' | 'name'>('amount');
  readonly selectedDay = signal<string | null>(null);
  readonly categoryLabels = computed(() => ({ ...MENU_CATEGORY_LABELS, ...this.detail()?.categoryLabels }));
  readonly orderStatuses = ORDER_STATUSES;
  readonly presets = [
    { id: 'today' as const, label: 'Hoy' },
    { id: 'week' as const, label: 'Últimos 7 días' },
    { id: 'month' as const, label: 'Este mes' },
  ];
  from = this.today.slice(0, 8) + '01';
  to = this.today;
  start = '';
  end = '';
  category = '';
  staff = '';
  attribution: 'cashier' | 'order' = 'cashier';

  constructor() {
    effect(() => {
      const group = this.group();
      untracked(() => void this.load(group));
    });
  }

  money(cents: number): string { return this.currency.format(cents / 100); }

  choosePreset(preset: Preset): void {
    this.preset.set(preset);
    this.to = this.today;
    this.from = preset === 'today' ? this.to
      : preset === 'month' ? this.to.slice(0, 8) + '01'
        : new Date(Date.parse(this.to) - 6 * DAY_MS).toISOString().slice(0, 10);
    void this.load();
  }

  async load(group = this.group()): Promise<void> {
    const id = ++this.requestNumber;
    this.busy.set(true);
    this.error.set('');
    this.selectedDay.set(null);
    if (group === 'summary') this.detail.set(null);
    else this.summary.set(null);
    const params = new URLSearchParams({ from: this.from, to: this.to, group });
    if (group !== 'summary') {
      for (const [key, value] of Object.entries({
        start: this.start, end: this.end, category: this.category, staff: this.staff, attribution: this.attribution,
      })) {
        if (value) params.set(key, value);
      }
    }
    try {
      const report = await this.api.workflow<SalesSummary | SalesDetail>('/reports/sales?' + params);
      if (id !== this.requestNumber) return;
      if ('rows' in report) this.detail.set(report);
      else this.summary.set(report);
      const duration = (Date.parse(report.to) - Date.parse(report.from)) / DAY_MS;
      this.granularity.set(duration > 40 ? 'week' : 'day');
      const usedFilters: string[] = [];
      const category = params.get('category');
      const staff = params.get('staff');
      if (params.has('start') || params.has('end')) {
        usedFilters.push(`${params.get('start') || '00:00'}–${params.get('end') || '23:59'}`);
      }
      if (category) usedFilters.push(this.categoryLabels()[category] ?? category);
      if (staff && 'staffOptions' in report) {
        const name = staff === '0' ? 'Carta digital' : report.staffOptions.find((option) => option.id === Number(staff))?.name;
        if (name) usedFilters.push(`${params.get('attribution') === 'order' ? 'Tomó el pedido' : 'Registró el cobro'} · ${name}`);
      }
      this.applied.set({ from: report.from, to: report.to, description: usedFilters.join(' · ') });
    } catch (error) {
      if (id === this.requestNumber) this.error.set(error instanceof Error ? error.message : 'No se pudo cargar el reporte.');
    } finally {
      if (id === this.requestNumber) this.busy.set(false);
    }
  }

  readonly detailRows = computed(() => {
    const rows = this.detail()?.rows ?? [];
    const names = this.categoryLabels();
    return this.group() === 'category' ? rows.map((row) => ({ ...row, name: names[row.category] ?? row.name })) : rows;
  });

  readonly filteredRows = computed(() => {
    const query = this.search().trim().toLocaleLowerCase('es-PE');
    const names = this.categoryLabels();
    const rows = this.detailRows().filter((row) => {
      const category = names[row.category] ?? row.category;
      return `${row.name} ${category}`.toLocaleLowerCase('es-PE').includes(query);
    });
    const order = this.orderBy();
    if (order === 'amount') return rows;
    return rows.sort((a, b) => order === 'units' ? b.units - a.units : a.name.localeCompare(b.name, 'es'));
  });

  readonly paid = computed(() => this.summary()?.days.reduce((total, entry) => total + entry.orders, 0) ?? 0);
  readonly amount = computed(() => this.summary()?.days.reduce((total, entry) => total + entry.amount_cents, 0)
    ?? this.detailRows().reduce((total, entry) => total + entry.amount_cents, 0));
  readonly orderCount = computed(() => this.summary()?.orders.reduce((total, entry) => total + entry.count, 0) ?? 0);
  readonly statusCounts = computed(() => new Map(this.summary()?.orders.map((entry) => [entry.status, entry.count])));

  readonly series = computed<Series[]>(() => {
    if (this.group() === 'summary') return [{ key: 'sales', name: 'Cobros', color: COLORS[0], amount: this.amount() }];
    const rows = this.detailRows();
    const leaders = rows.slice(0, 5).map((row, i) => ({ key: row.series, name: row.name, color: COLORS[i], amount: row.amount_cents }));
    if (rows.length > 5) {
      leaders.push({ key: 'otros', name: 'Otros', color: COLORS[5], amount: rows.slice(5).reduce((sum, r) => sum + r.amount_cents, 0) });
    }
    return leaders;
  });

  readonly chart = computed(() => {
    const summary = this.summary();
    const detail = this.detail();
    const report = summary ?? detail;
    if (!report) return [];
    const weekly = this.granularity() === 'week';
    const days = summary?.days.map((day) => ({ ...day, series: 'sales' })) ?? detail?.days ?? [];
    const amounts = new Map<string, Map<string, number>>();
    for (const day of days) {
      const key = this.bucket(Date.parse(day.day), weekly);
      const segments = amounts.get(key) ?? new Map<string, number>();
      segments.set(day.series, (segments.get(day.series) ?? 0) + day.amount_cents);
      amounts.set(key, segments);
    }
    const start = Date.parse(report.from);
    const end = Date.parse(report.to);
    const columns = [];
    const step = weekly ? DAY_MS * 7 : DAY_MS;
    let date = Date.parse(this.bucket(start, weekly));
    while (date <= end) {
      const key = new Date(date).toISOString().slice(0, 10);
      const label = this.dayFormat.format(Math.max(date, start));
      const segments = this.series().map((series) => ({ ...series, amount: amounts.get(key)?.get(series.key) ?? 0 }));
      columns.push({ key, label, segments, total: segments.reduce((total, series) => total + series.amount, 0) });
      date += step;
    }
    return columns;
  });

  readonly upperBound = computed(() => {
    const peak = Math.max(0, ...this.chart().map((column) => column.total));
    const step = 10 ** Math.max(2, Math.floor(Math.log10(peak || 1)) - 1);
    return Math.max(step * 4, Math.ceil(peak / (step * 4)) * (step * 4));
  });
  readonly chartSelection = computed(() => this.chart().find((column) => column.key === this.selectedDay()));

  private bucket(date: number, weekly: boolean): string {
    if (weekly) date -= ((new Date(date).getUTCDay() + 6) % 7) * DAY_MS;
    return new Date(date).toISOString().slice(0, 10);
  }

  exportRows(): void {
    const rows = this.filteredRows();
    const period = this.applied();
    if (!rows.length || !period) return;
    const csv = (value: string | number) => {
      const text = String(value);
      const safe = typeof value === 'string' && /^[\s]*[=+\-@]/.test(text) ? "'" + text : text;
      return '"' + safe.replaceAll('"', '""') + '"';
    };
    const lines: (string | number)[][] = [
      [this.group() === 'category' ? 'Categoría' : 'Artículo', ...(this.group() === 'article' ? ['Categoría'] : []), 'Unidades', 'Precio promedio (PEN)', 'Importe (PEN)'],
      ...rows.map((row) => [row.name, ...(this.group() === 'article' ? [this.categoryLabels()[row.category] ?? row.category] : []),
        row.units, (row.amount_cents / (row.units || 1) / 100).toFixed(2), (row.amount_cents / 100).toFixed(2)]),
    ];
    const url = URL.createObjectURL(new Blob(['\ufeff', lines.map((row) => row.map(csv).join(',')).join('\r\n')], { type: 'text/csv;charset=utf-8' }));
    const link = document.createElement('a');
    link.href = url;
    link.download = `ventas-${this.group() === 'article' ? 'articulos' : 'categorias'}-${period.from}-${period.to}.csv`;
    link.click();
    window.setTimeout(() => URL.revokeObjectURL(url), 1000);
  }
}
