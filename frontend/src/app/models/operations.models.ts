export type OrderStatus = 'pending' | 'preparing' | 'ready' | 'delivered' | 'cancelled';
export type StaffRole = 'admin' | 'kitchen';
export interface StaffUser {
  id: number;
  name: string;
  username: string;
  role: StaffRole;
  active: boolean;
}
export interface OrderLine {
  productId: string;
  name: string;
  quantity: number;
  unitPriceCents: number;
  subtotalCents: number;
  notes: string;
  modifiers?: string[];
  categoryId: string;
  main: string | null;
  starter: string | null;
  menuType: string | null;
}
export interface OrderReceipt {
  id: number;
  number: string;
  trackingId: string;
  status: OrderStatus;
  items: OrderLine[];
  subtotalCents: number;
  packagingCents: number;
  totalCents: number;
  method: 'pickup' | 'delivery' | 'table';
  payment: 'yape' | 'cash' | null;
  createdAt: string;
  updatedAt: string;
}
export interface StaffOrder extends OrderReceipt {
  customerName: string;
  customerPhone: string;
  address: string | null;
  reference: string | null;
  tableLabel?: string | null;
  revision?: number;
  events?: { status: OrderStatus; at: string; by: string; reason?: string | null; edited?: boolean }[];
}

export interface StaffOrderDraft {
  customer: {
    name: string;
    phone: string;
    method: OrderReceipt['method'];
    payment: OrderReceipt['payment'];
    tableLabel: string;
    tableId?: string;
    address: string;
    reference: string;
  };
  items: Pick<OrderLine, 'productId' | 'quantity' | 'notes' | 'modifiers'>[];
  expectedTotalCents: number;
}

export interface OrderModifier {
  id: number;
  name: string;
}

export interface SalonTable {
  id: string;
  label: string;
}

export interface SalonLayout {
  revision: number;
  zones: { id: string; name: string; tables: SalonTable[] }[];
}

export function staffOrderMethod(order: StaffOrder): string {
  if (order.method === 'table') return order.tableLabel ? 'Mesa ' + order.tableLabel : 'Salón';
  return order.method === 'delivery' ? 'Delivery' : 'Para llevar';
}

export function staffOrderPayment(order: StaffOrder): string {
  return order.payment === 'yape' ? 'Yape' : order.payment === 'cash' ? 'Efectivo' : 'Por definir';
}
export interface OrderFeed {
  orders: StaffOrder[];
  summary: { todayCount: number; todayTotalCents: number };
}
export interface OrderHistory {
  orders: StaffOrder[];
  pagination: { page: number; lastPage: number; total: number };
}

export interface DishDemandRow {
  key: string;
  name: string;
  quantity: number;
}

export interface DishDemandReport {
  from: string;
  to: string;
  orderCount: number;
  rankings: Record<'carta' | 'fondos' | 'entradas', DishDemandRow[]>;
}
export const ORDER_STATUSES: {
  id: OrderStatus;
  label: string;
  action: string;
  next: OrderStatus | null;
}[] = [
  { id: 'pending', label: 'Pendiente', action: 'Iniciar preparación', next: 'preparing' },
  { id: 'preparing', label: 'Preparando', action: 'Marcar como listo', next: 'ready' },
  { id: 'ready', label: 'Listo', action: 'Marcar entregado', next: 'delivered' },
  { id: 'delivered', label: 'Entregado', action: '', next: null },
  { id: 'cancelled', label: 'Cancelado', action: '', next: null },
];

export function isActiveOrderStatus(status: OrderStatus): boolean {
  return status === 'pending' || status === 'preparing' || status === 'ready';
}
