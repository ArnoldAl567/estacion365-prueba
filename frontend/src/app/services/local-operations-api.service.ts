import { Injectable, signal } from '@angular/core';
import { CatalogItem, DailyPrices } from '../models/catalog.models';
import { CartItem, CustomerDetails } from '../models/menu.models';
import { optimizeCatalogPhoto } from './catalog-image';
import {
  DishDemandReport,
  isActiveOrderStatus,
  OrderFeed,
  OrderHistory,
  OrderModifier,
  OrderReceipt,
  OrderStatus,
  StaffOrder,
  StaffOrderDraft,
  StaffRole,
  SalonLayout,
  StaffUser,
} from '../models/operations.models';

interface SessionResponse {
  initialized: boolean;
  user: StaffUser | null;
  csrfToken: string;
}
const RECEIPT_KEY = 'estacion-local-last-order-v1';

export class OperationsError extends Error {
  constructor(
    message: string,
    readonly status: number,
  ) {
    super(message);
  }
}

@Injectable({ providedIn: 'root' })
export class LocalOperationsApi {
  readonly user = signal<StaffUser | null>(null);
  readonly initialized = signal(true);
  readonly receipt = signal<OrderReceipt | null>(this.restoreReceipt());
  private csrfToken = '';

  async bootstrap(): Promise<void> {
    this.applySession(await this.request<SessionResponse>('/session'));
  }

  async login(username: string, password: string): Promise<void> {
    this.applySession(
      await this.request<SessionResponse>('/login', 'POST', { username, password }),
    );
  }

  async setup(
    name: string,
    username: string,
    password: string,
    confirmation: string,
    setupCode?: string,
  ): Promise<void> {
    this.applySession(
      await this.request<SessionResponse>('/setup', 'POST', {
        name,
        username,
        password,
        password_confirmation: confirmation,
        setup_code: setupCode,
      }),
    );
  }

  async logout(): Promise<void> {
    this.applySession(await this.request<SessionResponse>('/logout', 'POST', {}));
  }

  catalog(): Promise<{ items: CatalogItem[]; prices: DailyPrices }> {
    return this.request('/catalog');
  }
  async createCatalogItem(item: CatalogItem): Promise<CatalogItem> {
    const result = await this.request<{ item: CatalogItem }>('/catalog/items', 'POST', item);
    this.announceCatalogChange();
    return result.item;
  }
  async updateCatalogItem(item: CatalogItem): Promise<CatalogItem> {
    const result = await this.request<{ item: CatalogItem }>(
      `/catalog/items/${encodeURIComponent(item.item_key)}`,
      'PATCH',
      item,
    );
    this.announceCatalogChange();
    return result.item;
  }
  async updateCatalogPrices(prices: DailyPrices): Promise<void> {
    await this.request('/catalog/prices', 'PUT', prices);
    this.announceCatalogChange();
  }
  async uploadCatalogImage(file: File): Promise<string> {
    const image = await optimizeCatalogPhoto(file);
    const form = new FormData();
    form.append('image', image, 'plato.webp');
    return (await this.request<{ url: string }>('/catalog/images', 'POST', form)).url;
  }
  private announceCatalogChange(): void {
    window.dispatchEvent(new Event('estacion:catalog-changed'));
    try {
      localStorage.setItem('estacion-local-catalog-updated', crypto.randomUUID());
    } catch {
      /* La carta también consulta cambios al recuperar el foco. */
    }
  }
  feed(): Promise<OrderFeed> {
    return this.request('/orders?scope=active');
  }
  history(date: string, search: string, page: number): Promise<OrderHistory> {
    return this.request(
      '/orders?' + new URLSearchParams({ scope: 'history', date, search, page: String(page) }),
    );
  }
  dishDemand(range?: { from: string; to: string }): Promise<DishDemandReport> {
    const query = range ? '?' + new URLSearchParams(range) : '';
    return this.request(`/reports/demand${query}`);
  }
  detail(id: number): Promise<{ order: StaffOrder }> {
    return this.request(`/orders/${id}`);
  }
  changeStatus(order: StaffOrder, status: OrderStatus, reason?: string): Promise<{ order: StaffOrder }> {
    return this.request(`/orders/${order.id}`, 'PATCH', {
      status, expectedStatus: order.status, expectedRevision: order.revision, reason,
    });
  }

  saveStaffOrder(
    draft: StaffOrderDraft,
    order: StaffOrder | null,
    requestId: string,
  ): Promise<{ order: StaffOrder }> {
    return order
      ? this.request(`/staff-orders/${order.id}`, 'PATCH', { ...draft, expectedRevision: order.revision })
      : this.request('/staff-orders', 'POST', { ...draft, requestId });
  }

  orderModifiers(): Promise<{ modifiers: OrderModifier[] }> {
    return this.request('/order-modifiers');
  }

  salonLayout(): Promise<SalonLayout> {
    return this.request('/salon-layout');
  }

  saveSalonLayout(layout: SalonLayout): Promise<SalonLayout> {
    return this.request('/salon-layout', 'PUT', layout);
  }

  createOrderModifier(name: string): Promise<{ modifiers: OrderModifier[] }> {
    return this.request('/order-modifiers', 'POST', { name });
  }

  async deleteOrderModifier(id: number): Promise<void> {
    await this.request(`/order-modifiers/${id}`, 'DELETE');
  }
  users(): Promise<{ users: StaffUser[] }> {
    return this.request('/users');
  }
  createUser(data: {
    name: string;
    username: string;
    password: string;
    role: StaffRole;
  }): Promise<{ user: StaffUser }> {
    return this.request('/users', 'POST', data);
  }
  updateUser(
    id: number,
    data: { name?: string; role?: StaffRole; active?: boolean; password?: string },
  ): Promise<{ user: StaffUser }> {
    return this.request(`/users/${id}`, 'PATCH', data);
  }

  async submitOrder(
    items: CartItem[],
    customer: CustomerDetails,
    requestId: string,
    expectedTotalCents: number,
  ): Promise<OrderReceipt> {
    const result = await this.request<{ order: OrderReceipt }>('/orders', 'POST', {
      requestId,
      customer,
      expectedTotalCents,
      items: items.map((item) => ({
        productId: item.product.id,
        quantity: item.quantity,
        notes: item.notes,
      })),
    });
    this.setReceipt(result.order);
    return result.order;
  }

  async trackOrder(): Promise<void> {
    const receipt = this.receipt();
    if (!receipt) return;
    const response = await this.request<{ order: OrderReceipt }>(
      `/tracking/${encodeURIComponent(receipt.trackingId)}`,
    );
    this.setReceipt(response.order);
  }

  setReceipt(receipt: OrderReceipt | null): void {
    this.receipt.set(receipt);
    try {
      if (receipt && isActiveOrderStatus(receipt.status)) localStorage.setItem(RECEIPT_KEY, JSON.stringify(receipt));
      else localStorage.removeItem(RECEIPT_KEY);
    } catch {
      /* El seguimiento también funciona durante la sesión actual. */
    }
  }

  private applySession(response: SessionResponse): void {
    this.csrfToken = response.csrfToken;
    this.user.set(response.user);
    this.initialized.set(response.initialized);
  }

  private restoreReceipt(): OrderReceipt | null {
    try {
      const receipt = JSON.parse(localStorage.getItem(RECEIPT_KEY) ?? 'null');
      if (receipt && !isActiveOrderStatus(receipt.status)) {
        localStorage.removeItem(RECEIPT_KEY);
        return null;
      }
      return receipt &&
        typeof receipt.trackingId === 'string' &&
        /^[0-9a-f-]{36}$/i.test(receipt.trackingId)
        ? receipt
        : null;
    } catch {
      return null;
    }
  }

  private async request<T>(
    path: string,
    method = 'GET',
    body?: unknown,
    retryCsrf = true,
  ): Promise<T> {
    if (method !== 'GET' && !this.csrfToken) await this.bootstrap();
    const controller = new AbortController();
    const timeout = window.setTimeout(() => controller.abort(), 12_000);
    try {
      const response = await fetch(`/local-api${path}`, {
        method,
        credentials: 'same-origin',
        cache: 'no-store',
        signal: controller.signal,
        headers: {
          Accept: 'application/json',
          ...(method === 'GET' ? {} : { 'X-CSRF-TOKEN': this.csrfToken }),
          ...(body === undefined || body instanceof FormData
            ? {}
            : { 'Content-Type': 'application/json' }),
        },
        ...(body === undefined
          ? {}
          : { body: body instanceof FormData ? body : JSON.stringify(body) }),
      });
      if (response.status === 419 && retryCsrf) {
        await this.bootstrap();
        return this.request(path, method, body, false);
      }
      if (response.status === 204) return undefined as T;
      if (!(response.headers.get('Content-Type') ?? '').includes('application/json')) {
        throw new OperationsError('El servidor de pedidos no respondió. Inténtalo de nuevo en un momento.', response.status);
      }
      const data = await response.json();
      if (!response.ok) {
        if (response.status === 401) this.user.set(null);
        const messages = data.errors ? Object.values(data.errors).flat() : [];
        throw new OperationsError(
          String(messages[0] ?? data.message ?? 'No se pudo completar la operación.'),
          response.status,
        );
      }
      return data as T;
    } catch (error) {
      if (error instanceof OperationsError) throw error;
      throw new OperationsError(
        'No se pudo conectar con el servidor de pedidos. Vuelve a intentar.',
        0,
      );
    } finally {
      window.clearTimeout(timeout);
    }
  }
}
