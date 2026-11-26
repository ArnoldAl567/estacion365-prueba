import { DatePipe } from '@angular/common';
import { Component, computed, input, output, signal } from '@angular/core';
import { ORDER_STATUSES, OrderStatus, ProductionTicket, StaffOrder, staffOrderMethod } from '../../models/operations.models';

@Component({
  selector: 'app-operations-kitchen',
  imports: [DatePipe],
  templateUrl: './operations-kitchen.html',
  styleUrl: './operations-kitchen.scss',
})
export class OperationsKitchen {
  readonly orders = input<StaffOrder[]>([]);
  readonly canPrepare = input(false);
  readonly canEdit = input(false);
  readonly editRequested = output<StaffOrder>();
  readonly changing = input(new Set<number>());
  readonly now = input(Date.now());
  readonly detailsRequested = output<StaffOrder>();
  readonly advanced = output<StaffOrder>();
  readonly ticketAdvanced = output<{ order: StaffOrder; ticket: ProductionTicket }>();
  readonly cancellationRequested = output<StaffOrder>();
  readonly lanes = ORDER_STATUSES.slice(0, 3);
  readonly printError = signal('');
  readonly printing = signal(false);

  readonly ticketsByLane = computed(() => {
    const lanes = new Map<OrderStatus, { order: StaffOrder; ticket: ProductionTicket }[]>(
      this.lanes.map((lane) => [lane.id, []]),
    );
    for (const order of [...this.orders()].reverse()) {
      if (order.status === 'delivered' || order.status === 'cancelled') continue;
      const ticket = order.tickets?.find((entry) => entry.center === 'kitchen');
      if (ticket) lanes.get(ticket.status)?.push({ order, ticket });
    }
    return lanes;
  });

  async print(order: StaffOrder, ticket: ProductionTicket): Promise<void> {
    if (!ticket.printer || this.printing()) return;
    this.printing.set(true);
    this.printError.set('');
    try {
      const { default: qz } = await import('qz-tray');
      if (!qz.websocket.isActive()) await qz.websocket.connect();
      const body = document.createElement('main');
      const header = document.createElement('h2');
      header.textContent = `#${order.number} · ${ticket.name}`;
      body.append(header);
      const method = document.createElement('p');
      method.textContent = staffOrderMethod(order);
      body.append(method);
      for (const item of ticket.items) {
        const line = document.createElement('p');
        line.textContent = [`${item.quantity} × ${item.main ?? item.name}`, item.starter,
          item.modifiers?.join(', '), item.notes].filter(Boolean).join(' · ');
        body.append(line);
      }
      await qz.print(qz.configs.create(ticket.printer, { units: 'mm', size: { width: 72 } }),
        [{ type: 'pixel', format: 'html', flavor: 'plain', data: body.outerHTML }]);
    } catch {
      this.printError.set('No se pudo imprimir. Abre QZ Tray, acepta la conexión y comprueba el nombre de la impresora.');
    } finally {
      this.printing.set(false);
    }
  }
  minutes(order: StaffOrder): number {
    return Math.max(0, Math.floor((this.now() - new Date(order.createdAt).getTime()) / 60000));
  }
  elapsedTime(order: StaffOrder): string {
    const seconds = Math.max(
      0,
      Math.floor((this.now() - new Date(order.createdAt).getTime()) / 1000),
    );
    const minutes = Math.floor(seconds / 60).toString().padStart(2, '0');
    return `${minutes} min ${(seconds % 60).toString().padStart(2, '0')} s`;
  }
  readonly staffOrderMethod = staffOrderMethod;
}
