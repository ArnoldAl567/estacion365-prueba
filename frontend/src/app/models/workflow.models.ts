import { StaffOrder } from './operations.models';

export interface PaymentMethod {
  code: string;
  name: string;
  cash: boolean;
  active: boolean;
}

export interface CashShift {
  id: number;
  name: string;
  opened_at: string;
  closed_at: string | null;
  opening_cents: number;
  counted_cents: number | null;
  expected_cents: number;
  income_cents?: number;
  cash_cents?: number;
  expenses_cents?: number;
}

export interface CashView {
  templates?: {id:number;name:string;active:boolean}[];
  shift: CashShift | null;
  orders: (StaffOrder & { paymentRecord: { method_name: string; amount_cents: number; paid_at: string } | null })[];
  ordersPage: number;
  hasMoreOrders: boolean;
  methods: PaymentMethod[];
  history: CashShift[];
}

export interface InventoryUnit { id: number; name: string; symbol: string }
export interface PurchaseDetail {
  id: number; supplier: string; reference: string | null; created_at: string; total_cents: number;
  cancelled_at?: string | null; cancellation_reason?: string | null; cancelled_by_name?: string | null;
  lines: { id: number; name: string; symbol: string; quantity_milli: number; cost_cents: number }[];
}
export interface BatchDetail {
  id: number; name: string; portions: number; remaining: number; cost_cents: number; created_at: string;
  lines: { item_id: number; name: string; symbol: string; quantity_milli: number; cost_cents: number }[];
}
export interface Supplier { id: number; name: string; contact: string | null }
export interface InventoryItem {
  id: number; name: string; unit_id: number; symbol: string; category: string | null;
  category_id?: number | null;
  minimum_milli: number; stock_milli: number; stock_value_cents: number;
}
export interface InventoryRecipe {
  id: number; product_key: string; name: string; mode: 'direct' | 'batch';
  batch_yield?: number;
  components: { item_id: number; quantity_milli: number }[];
}
export interface Page<T> { data: T[]; current_page: number; next_page_url: string | null }
export interface InventoryView {
  categories?: {id: number; name: string; item_count: number}[];
  units: InventoryUnit[];
  items: InventoryItem[];
  suppliers: Supplier[];
  products: { item_key: string; name: string; kind: string }[];
  recipes: InventoryRecipe[];
  purchases: { id: number; supplier: string; total_cents: number; reference: string | null; created_at: string; cancelled_at?: string | null }[];
  batches: Page<{ id: number; name: string; portions: number; remaining: number; cost_cents: number; created_at: string }>;
  movements: Page<{ id: number; type: string; name: string | null; batch_id: number | null; quantity_milli: number; cost_cents: number; reason: string | null; created_at: string }>;
  orders: Page<{ id: number; created_at: string; status: string; table_label: string | null }>;
}

export interface ProductionCenter { code: string; name: string; printer: string | null; active: boolean }
export interface OperationsSettings {
  centers: ProductionCenter[];
  routes: { category: string; center: string }[];
  categories: string[];
  categoryLabels?: Partial<Record<string, string>>;
  methods: PaymentMethod[];
}

export interface OperationsReport {
  from: string;
  to: string;
  orders: { status: string; delivery_method: string; orders: number }[];
  tickets: { name: string; status: string; orders: number }[];
  sales: { day: string; orders: number; amount_cents: number }[];
  payments: { method_name: string; orders: number; amount_cents: number }[];
  expenses: Page<{ id: number; description: string; amount_cents: number; created_at: string }>;
  expenseTotal: number;
  consumption: { name: string; symbol: string; type: string; quantity_milli: number; cost_cents: number }[];
  production: { name: string; batches: number; portions: number; cost_cents: number }[];
  foodCost: { orders: number; cost_cents: number; sales_cents: number; partial: number };
  audit: Page<{ id: number; action: string; actor: string | null; entity: string; entity_id: string; created_at: string }>;
}
