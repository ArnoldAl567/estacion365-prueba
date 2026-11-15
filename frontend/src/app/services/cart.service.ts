import { computed, effect, inject, Injectable, signal } from '@angular/core';
import { CartItem, Product } from '../models/menu.models';
import { dailyMenuProductById } from '../data/daily-menu.data';
import { MenuService } from './menu.service';

const STORAGE_KEY = 'la-estacion-365-cart-v1';

@Injectable({ providedIn: 'root' })
export class CartService {
  private readonly menu = inject(MenuService);
  readonly items = signal<CartItem[]>(this.restoreCart());
  readonly itemCount = computed(() => this.items().reduce((sum, item) => sum + item.quantity, 0));
  readonly total = computed(() => this.items().reduce((sum, item) => sum + (item.product.price ?? 0) * item.quantity, 0));

  constructor() {
    effect(() => {
      try { localStorage.setItem(STORAGE_KEY, JSON.stringify(this.items())); }
      catch { /* El pedido sigue funcionando si el navegador bloquea localStorage. */ }
    });
    effect(() => {
      if (!this.menu.ready()) return;
      const products = this.menu.products();
      this.items.update(items => items.flatMap(item => {
        const product = products.find(current => current.id === item.product.id) ??
          dailyMenuProductById(item.product.id, this.menu.dailyStarters(), this.menu.dailyMains(), this.menu.dailyPrices());
        return product && product.price !== null && product.active !== false ? [{ ...item, product }] : [];
      }));
    });
  }

  add(product: Product): void {
    if (product.price === null) return;
    this.items.update(items => {
      const existing = items.find(item => item.product.id === product.id);
      return existing
        ? items.map(item => item.product.id === product.id ? { ...item, quantity: Math.min(99, item.quantity + 1) } : item)
        : [...items, { product, quantity: 1, notes: '' }];
    });
  }
  setQuantity(productId: string, quantity: number): void {
    if (quantity <= 0) { this.remove(productId); return; }
    this.items.update(items => items.map(item => item.product.id === productId ? { ...item, quantity: Math.min(99, Math.trunc(quantity)) } : item));
  }
  updateNotes(productId: string, notes: string): void { this.items.update(items => items.map(item => item.product.id === productId ? { ...item, notes: notes.slice(0, 100) } : item)); }
  remove(productId: string): void { this.items.update(items => items.filter(item => item.product.id !== productId)); }
  clear(): void { this.items.set([]); }
  private restoreCart(): CartItem[] {
    try {
      const saved = localStorage.getItem(STORAGE_KEY);
      const parsed: unknown = saved ? JSON.parse(saved) : [];
      if (!Array.isArray(parsed)) return [];
      return parsed.flatMap((entry: unknown): CartItem[] => {
        if (!entry || typeof entry !== 'object') return [];
        const item = entry as Partial<CartItem>;
        const product = this.menu.products().find(current => current.id === item.product?.id) ??
          dailyMenuProductById(item.product?.id ?? '', this.menu.dailyStarters(), this.menu.dailyMains(), this.menu.dailyPrices()) ??
          (!this.menu.ready() ? this.pendingProduct(item.product) : null);
        if (!product || product.price === null) return [];
        const quantity = Math.min(99, Math.max(1, Math.trunc(Number(item.quantity) || 1)));
        return [{ product, quantity, notes: typeof item.notes === 'string' ? item.notes.slice(0, 100) : '' }];
      });
    }
    catch { return []; }
  }

  private pendingProduct(product: unknown): Product | null {
    if (!product || typeof product !== 'object') return null;
    const candidate = product as Partial<Product>;
    if (typeof candidate.id !== 'string' || !candidate.id || candidate.id.length > 200 ||
        typeof candidate.name !== 'string' || candidate.name.length > 500 ||
        typeof candidate.categoryId !== 'string' || candidate.categoryId.length > 64 ||
        typeof candidate.price !== 'number' || !Number.isFinite(candidate.price) || candidate.price < 0 || candidate.price > 9999.99) return null;
    // Conserva la selección hasta cargar el catálogo. El servidor siempre valida el precio.
    return { id: candidate.id, name: candidate.name, categoryId: candidate.categoryId, price: candidate.price,
      image: typeof candidate.image === 'string' ? candidate.image : null };
  }
}
