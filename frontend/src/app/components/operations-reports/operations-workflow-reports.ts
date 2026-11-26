import { DatePipe, DecimalPipe } from '@angular/common';
import { Component, computed, effect, inject, input, signal, untracked } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { OperationsPageId } from '../../config/operations-navigation';
import { ORDER_STATUSES } from '../../models/operations.models';
import { OperationsReport } from '../../models/workflow.models';
import { LocalOperationsApi } from '../../services/local-operations-api.service';
import { OperationsReports } from './operations-reports';

interface ReportTable { title: string; columns: string[]; rows: (string | number)[][] }

@Component({
  selector: 'app-operations-workflow-reports',
  imports: [FormsModule, DecimalPipe, OperationsReports],
  templateUrl: './operations-workflow-reports.html',
})
export class OperationsWorkflowReports {
  private readonly api = inject(LocalOperationsApi);
  readonly report = signal<OperationsReport | null>(null);
  readonly busy = signal(false);
  readonly error = signal('');
  readonly page = input.required<OperationsPageId>();
  readonly money = (cents: number) => 'S/ ' + (Number(cents) / 100).toFixed(2);
  private readonly date = new DatePipe('es-PE');
  private readonly statuses = Object.fromEntries(ORDER_STATUSES.map((status) => [status.id, status.label]));
  private readonly methods: Record<string, string> = { pickup: 'Para llevar', delivery: 'Delivery', table: 'Salón' };
  private readonly auditLabels: Record<string, string> = {
    'pedido.registrado': 'Pedido registrado', 'pedido.editado': 'Pedido editado', 'pedido.preparing': 'Pedido en preparación',
    'pedido.ready': 'Pedido listo', 'pedido.delivered': 'Pedido entregado', 'pedido.cancelled': 'Pedido cancelado',
    'comanda.preparing': 'Comanda en preparación', 'comanda.ready': 'Comanda lista', 'caja.apertura': 'Apertura de caja',
    'caja.cierre': 'Cierre de caja', 'caja.cobro': 'Cobro registrado', 'caja.gasto': 'Gasto registrado',
    'caja.mesa_cerrada': 'Mesa cerrada', 'salon.configuracion': 'Plano de salón actualizado',
    'config.categoria': 'Categoría actualizada', 'salon.modificador_editado': 'Modificador actualizado',
    'equipo.alta': 'Usuario creado', 'equipo.edicion': 'Acceso actualizado',
  };
  from = '';
  to = '';

  constructor() {
    const today = new Intl.DateTimeFormat('en-CA', { timeZone: 'America/Lima', year: 'numeric', month: '2-digit', day: '2-digit' })
      .formatToParts(new Date());
    const values = Object.fromEntries(today.map((part) => [part.type, part.value]));
    this.to = `${values['year']}-${values['month']}-${values['day']}`;
    this.from = this.to.slice(0, 8) + '01';
    effect(() => {
      if (this.page() !== 'report-demand' && !untracked(this.report)) untracked(() => void this.load());
    });
  }

  async load(expensePage = 1, auditPage = 1): Promise<void> {
    if (this.busy()) return;
    this.busy.set(true);
    this.error.set('');
    try {
      const query = new URLSearchParams({ from: this.from, to: this.to, expensePage: String(expensePage), auditPage: String(auditPage) });
      this.report.set(await this.api.workflow<OperationsReport>('/reports/operations?' + query));
    } catch (error) {
      this.error.set(error instanceof Error ? error.message : 'No se pudo cargar el reporte.');
    } finally {
      this.busy.set(false);
    }
  }

  readonly tables = computed<ReportTable[]>(() => {
    const report = this.report();
    if (!report) return [];
    switch (this.page()) {
      case 'report-orders': return [
        { title: 'Pedidos por modalidad y estado', columns: ['Modalidad', 'Estado actual', 'Pedidos'],
          rows: report.orders.map((r) => [this.methods[r.delivery_method] ?? r.delivery_method, this.statuses[r.status] ?? r.status, r.orders]) },
      ];
      case 'report-tickets': return [
        { title: 'Comandas por área', columns: ['Área', 'Estado actual', 'Comandas'],
          rows: report.tickets.map((r) => [r.name, this.statuses[r.status] ?? r.status, r.orders]) },
      ];
      case 'report-payments': return [
        { title: 'Cobros por medio de pago', columns: ['Medio', 'Pedidos cobrados', 'Importe'],
          rows: report.payments.map((r) => [r.method_name, r.orders, this.money(r.amount_cents)]) },
      ];
      case 'report-expenses': return [
        { title: 'Gastos registrados', columns: ['Concepto', 'Fecha', 'Importe'],
          rows: report.expenses.data.map((r) => [r.description, this.timestamp(r.created_at), this.money(r.amount_cents)]) },
      ];
      case 'report-consumption': return [
        { title: 'Consumo de insumos', columns: ['Insumo', 'Movimiento', 'Cantidad', 'Costo'],
          rows: report.consumption.map((r) => [r.name, r.type, `${r.quantity_milli / 1000} ${r.symbol}`, this.money(r.cost_cents)]) },
      ];
      case 'report-production': return [
        { title: 'Producción', columns: ['Producto', 'Lotes', 'Porciones', 'Costo'],
          rows: report.production.map((r) => [r.name, r.batches, r.portions, this.money(r.cost_cents)]) },
      ];
      case 'report-audit': return [
        { title: 'Registro de actividad', columns: ['Fecha', 'Acción', 'Responsable', 'Referencia'],
          rows: report.audit.data.map((r) => [this.timestamp(r.created_at),
            this.auditLabels[r.action] ?? r.action.replace(/[._]/g, ' '), r.actor ?? 'Carta digital', `${r.entity} · ${r.entity_id}`]) },
      ];
      default: return [];
    }
  });

  private timestamp(date: string): string {
    return this.date.transform(date.replace(' ', 'T') + 'Z', 'dd/MM HH:mm', '-0500') ?? '';
  }

  readonly foodPercent = computed(() => {
    const cost = this.report()?.foodCost;
    return cost && cost.sales_cents > 0 ? 100 * cost.cost_cents / cost.sales_cents : null;
  });
}
