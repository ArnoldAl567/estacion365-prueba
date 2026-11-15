import { CurrencyPipe, DatePipe } from '@angular/common';
import { Component, input, output } from '@angular/core';
import { isActiveOrderStatus, ORDER_STATUSES, OrderStatus, StaffOrder } from '../../models/operations.models';

@Component({
  selector: 'app-operations-order-detail',
  imports: [CurrencyPipe, DatePipe],
  templateUrl: './operations-order-detail.html',
  styleUrl: './operations-order-detail.scss',
})
export class OperationsOrderDetail {
  readonly data = input.required<StaffOrder>();
  readonly busy = input(false);
  readonly closed = output<void>();
  readonly advanced = output<StaffOrder>();
  readonly cancellationRequested = output<StaffOrder>();
  readonly isActiveOrderStatus = isActiveOrderStatus;

  statusLabel(status: OrderStatus): string {
    return ORDER_STATUSES.find((option) => option.id === status)?.label ?? status;
  }
  methodLabel(method: StaffOrder['method']): string {
    return { pickup: 'Retiro en local', delivery: 'A domicilio', table: 'Para mesa' }[method];
  }
  nextAction(order: StaffOrder): string {
    return ORDER_STATUSES.find((status) => status.id === order.status)?.action ?? '';
  }
}
