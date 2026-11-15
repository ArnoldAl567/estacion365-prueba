import { signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { DEFAULT_DAILY_PRICES } from '../models/catalog.models';
import { Product } from '../models/menu.models';
import { CartService } from './cart.service';
import { MenuService } from './menu.service';

describe('Cart catalog synchronization', () => {
  afterEach(() => localStorage.removeItem('la-estacion-365-cart-v1'));

  it('preserves newly created dishes while loading, then uses current prices and availability', () => {
    const dish: Product = {
      id: 'nuevo-personalizado',
      name: 'Nuevo plato',
      categoryId: 'marinos',
      price: 20,
    };
    localStorage.setItem(
      'la-estacion-365-cart-v1',
      JSON.stringify([{ product: dish, quantity: 2, notes: 'Sin picante' }]),
    );
    const menu = {
      products: signal<Product[]>([]),
      ready: signal(false),
      dailyMains: signal([]),
      dailyStarters: signal([]),
      dailyPrices: signal(DEFAULT_DAILY_PRICES),
    };
    TestBed.configureTestingModule({ providers: [{ provide: MenuService, useValue: menu }] });
    const cart = TestBed.inject(CartService);
    TestBed.flushEffects();
    expect(cart.itemCount()).toBe(2);
    menu.products.set([{ ...dish, price: 25 }]);
    menu.ready.set(true);
    TestBed.flushEffects();
    expect(cart.total()).toBe(50);
    expect(cart.items()[0].notes).toBe('Sin picante');
    menu.products.set([{ ...dish, active: false }]);
    TestBed.flushEffects();
    expect(cart.itemCount()).toBe(0);
  });
});
