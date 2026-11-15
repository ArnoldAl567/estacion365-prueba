import { DatePipe } from '@angular/common';
import { Component, computed, input, output } from '@angular/core';
import { isActiveOrderStatus, ORDER_STATUSES, StaffOrder } from '../../models/operations.models';

const SALON_ZONES = [
  { name: 'Salón A', tables: [1, 2, 3, 5, 6, 7, 8] },
  { name: 'Salón B', tables: [4, 9, 10, 11, 12, 13, 14, 15] },
];

interface SalonTable {
  label: string;
  orders: StaffOrder[];
  assignable: boolean;
}

@Component({
  selector: 'app-operations-salon',
  imports: [DatePipe],
  templateUrl: './operations-salon.html',
  styleUrl: './operations-salon.scss',
})
export class OperationsSalon {
  readonly orders = input.required<StaffOrder[]>();
  readonly online = input(false);
  readonly newOrder = output<string>();
  readonly orderDetails = output<StaffOrder>();
  readonly editOrder = output<StaffOrder>();
  readonly statuses = ORDER_STATUSES;
  readonly zones = computed(() => {
    const byTable = new Map<string, SalonTable>();
    for (const order of this.orders()) {
      if (order.method !== 'table' || !isActiveOrderStatus(order.status)) continue;
      const raw = order.tableLabel?.trim() ?? '';
      const numeric = /^(?:mesa\s*|m\s*)?0*(\d+)$/i.exec(raw);
      const key = numeric ? 'M' + numeric[1] : raw.toLocaleUpperCase('es-PE');
      const table = byTable.get(key) ?? { label: numeric ? key : raw || 'Sin mesa asignada', orders: [], assignable: !!raw };
      table.orders.push(order);
      byTable.set(key, table);
    }
    const zones = SALON_ZONES.map(({ name, tables }) => ({
      name,
      tables: tables.map((number) => {
        const label = 'M' + number;
        const table = byTable.get(label);
        byTable.delete(label);
        return table ?? { label, orders: [], assignable: true };
      }),
    }));
    if (byTable.size) zones.push({ name: 'Otras mesas', tables: [...byTable.values()] });
    for (const zone of zones) {
      for (const table of zone.tables) table.orders.sort((a, b) => a.id - b.id);
    }
    return zones;
  });

  statusLabel(order: StaffOrder): string {
    return this.statuses.find(({ id }) => id === order.status)?.label ?? order.status;
  }
}
