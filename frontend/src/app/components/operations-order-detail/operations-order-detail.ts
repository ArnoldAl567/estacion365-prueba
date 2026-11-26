import { CurrencyPipe, DatePipe } from '@angular/common';
import { AfterViewInit, Component, ElementRef, input, output, viewChild } from '@angular/core';
import { isActiveOrderStatus, ORDER_STATUSES, OrderStatus, StaffOrder, staffOrderMethod, staffOrderPayment } from '../../models/operations.models';

@Component({
  selector: 'app-operations-order-detail',
  imports: [CurrencyPipe, DatePipe],
  templateUrl: './operations-order-detail.html',
  styleUrl: './operations-order-detail.scss',
})
export class OperationsOrderDetail implements AfterViewInit {
  readonly data = input.required<StaffOrder>();
  readonly busy = input(false);
  readonly canPrepare = input(false);
  readonly closed = output<void>();
  readonly advanced = output<StaffOrder>();
  readonly cancellationRequested = output<StaffOrder>();
  readonly isActiveOrderStatus = isActiveOrderStatus;
  readonly dialog = viewChild.required<ElementRef<HTMLDialogElement>>('orderDetail');

  ngAfterViewInit(): void {
    this.dialog().nativeElement.showModal();
  }

  statusLabel(status: OrderStatus): string {
    return ORDER_STATUSES.find((option) => option.id === status)?.label ?? status;
  }
  readonly staffOrderMethod = staffOrderMethod;
  readonly staffOrderPayment = staffOrderPayment;
  nextAction(order: StaffOrder): string {
    return ORDER_STATUSES.find((status) => status.id === order.status)?.action ?? '';
  }
}
