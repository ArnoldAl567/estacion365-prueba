import { inject, Injectable } from '@angular/core';
import { CatalogItem, DailyPrices } from '../models/catalog.models';
import { CatalogApiService } from './catalog-api.service';
import { LocalOperationsApi } from './local-operations-api.service';

/** Reutiliza el editor de carta con la sesión y los datos del sistema Laravel. */
@Injectable()
export class LocalCatalogAdapter implements Pick<CatalogApiService, keyof CatalogApiService> {
  private readonly operations = inject(LocalOperationsApi);
  readonly configured = true;
  private loading: ReturnType<LocalOperationsApi['catalog']> | null = null;

  private catalog(): ReturnType<LocalOperationsApi['catalog']> {
    if (!this.loading) {
      this.loading = this.operations.catalog().finally(() => (this.loading = null));
    }
    return this.loading;
  }
  async restoreAdminSession(): Promise<boolean> {
    return this.operations.user()?.role === 'admin';
  }
  async loadItems(): Promise<CatalogItem[]> {
    return (await this.catalog()).items;
  }
  async loadDailyPrices(): Promise<DailyPrices> {
    return (await this.catalog()).prices;
  }
  createItem(item: CatalogItem): Promise<CatalogItem> {
    return this.operations.createCatalogItem(item);
  }
  updateItem(item: CatalogItem): Promise<CatalogItem> {
    return this.operations.updateCatalogItem(item);
  }
  updateDailyPrices(prices: DailyPrices): Promise<void> {
    return this.operations.updateCatalogPrices(prices);
  }
  uploadImage(file: File): Promise<string> {
    return this.operations.uploadCatalogImage(file);
  }
  async seedItems(): Promise<void> {
    /* Laravel prepara el catálogo durante la instalación. */
  }
  async signOut(): Promise<void> {
    await this.operations.logout();
  }
  async signIn(): Promise<void> {
    throw new Error('Inicia sesión desde el sistema de administración.');
  }
  async requestPasswordReset(): Promise<void> {
    throw new Error('Solicita el cambio de contraseña al administrador local.');
  }
  async resetPassword(): Promise<void> {
    throw new Error('Solicita el cambio de contraseña al administrador local.');
  }
  async uploadEmbeddedImage(): Promise<string> {
    throw new Error('Selecciona la fotografía desde tu computadora.');
  }
}
