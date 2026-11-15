import { inject, Injectable, signal } from '@angular/core';
import { CATEGORIES, PRODUCTS } from '../data/menu.data';
import { DAILY_MAINS, DAILY_STARTERS } from '../data/daily-menu.data';
import { catalogConfigured } from '../config/neon.config';
import { DailyPrices, DEFAULT_DAILY_PRICES } from '../models/catalog.models';
import { CatalogApiService } from './catalog-api.service';
import { mapCatalogRows } from './catalog-menu.mapper';
import { LOCAL_OPERATIONS_ENABLED } from '../config/local-operations.config';
import { LocalOperationsApi } from './local-operations-api.service';

@Injectable({ providedIn: 'root' })
export class MenuService {
  private readonly api = inject(CatalogApiService);
  private readonly localApi = inject(LocalOperationsApi);
  readonly categories = signal(CATEGORIES);
  readonly products = signal(PRODUCTS);
  readonly dailyStarters = signal(DAILY_STARTERS.filter(dish => dish.active !== false));
  readonly dailyMains = signal(DAILY_MAINS.filter(dish => dish.active !== false));
  readonly dailyPrices = signal<DailyPrices>(DEFAULT_DAILY_PRICES);
  readonly syncError = signal('');
  readonly ready = signal(!catalogConfigured && !LOCAL_OPERATIONS_ENABLED);
  private refreshing = false;
  private refreshQueued = false;
  private lastRefreshAt = 0;
  private readonly refreshIntervalMs = 5 * 60_000;

  constructor() {
    if (!catalogConfigured && !LOCAL_OPERATIONS_ENABLED) return;
    if (LOCAL_OPERATIONS_ENABLED) {
      window.addEventListener('estacion:catalog-changed', () => void this.refresh());
      window.addEventListener('storage', event => {
        if (event.key === 'estacion-local-catalog-updated') void this.refresh();
      });
    }
    void this.refresh();
    window.setInterval(() => {
      if (!document.hidden && (LOCAL_OPERATIONS_ENABLED || Date.now() - this.lastRefreshAt >= this.refreshIntervalMs)) void this.refresh();
    }, this.refreshIntervalMs);
    document.addEventListener('visibilitychange', () => {
      if (!document.hidden && (LOCAL_OPERATIONS_ENABLED || Date.now() - this.lastRefreshAt >= this.refreshIntervalMs)) void this.refresh();
    });
  }

  async refresh(): Promise<void> {
    if (!catalogConfigured && !LOCAL_OPERATIONS_ENABLED) return;
    if (this.refreshing) { this.refreshQueued = true; return; }
    this.refreshing = true;
    try {
      const [rows, prices] = LOCAL_OPERATIONS_ENABLED
        ? await this.localApi.catalog().then(response => [response.items, response.prices] as const)
        : await Promise.all([this.api.loadItems(), this.api.loadDailyPrices()]);
      this.dailyPrices.set(prices);
      if (!rows.length) {
        this.syncError.set('La carta se está preparando. Vuelve a intentarlo en un momento.');
        this.ready.set(false);
        return;
      }
      const catalog = mapCatalogRows(rows);
      this.products.set(catalog.products);
      this.dailyMains.set(catalog.dailyMains);
      this.dailyStarters.set(catalog.dailyStarters);
      this.syncError.set('');
      this.ready.set(true);
      this.lastRefreshAt = Date.now();
    } catch {
      this.syncError.set('No se pudo actualizar la carta. Comprueba tu conexión e inténtalo de nuevo.');
    } finally {
      this.refreshing = false;
      if (this.refreshQueued) {
        this.refreshQueued = false;
        void this.refresh();
      }
    }
  }

}
