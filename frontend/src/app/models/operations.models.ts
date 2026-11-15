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
  payment: 'yape' | 'cash';
  createdAt: string;
  updatedAt: string;
}
export interface StaffOrder extends OrderReceipt {
  customerName: string;
  customerPhone: string;
  address: string | null;
  reference: string | null;
  events?: { status: OrderStatus; at: string; by: string; reason?: string | null }[];
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
