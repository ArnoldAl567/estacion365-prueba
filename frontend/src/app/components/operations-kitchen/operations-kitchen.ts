import { DatePipe } from '@angular/common';
import { Component, input, output } from '@angular/core';
import { ORDER_STATUSES, OrderStatus, StaffOrder } from '../../models/operations.models';

@Component({
  selector: 'app-operations-kitchen',
  imports: [DatePipe],
  templateUrl: './operations-kitchen.html',
  styleUrl: './operations-kitchen.scss',
})
export class OperationsKitchen {
  readonly orders = input<StaffOrder[]>([]);
  readonly changing = input(new Set<number>());
  readonly now = input(Date.now());
  readonly detailsRequested = output<StaffOrder>();
  readonly advanced = output<StaffOrder>();
  readonly cancellationRequested = output<StaffOrder>();
  readonly lanes = ORDER_STATUSES.slice(0, 3);

  laneOrders(status: OrderStatus): StaffOrder[] {
    return this.orders()
      .filter((order) => order.status === status)
      .reverse();
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
  methodLabel(method: StaffOrder['method']): string {
    return { pickup: 'Retiro en local', delivery: 'A domicilio', table: 'Para mesa' }[method];
  }
  nextAction(order: StaffOrder): string {
    return ORDER_STATUSES.find((status) => status.id === order.status)?.action ?? '';
  }
}
