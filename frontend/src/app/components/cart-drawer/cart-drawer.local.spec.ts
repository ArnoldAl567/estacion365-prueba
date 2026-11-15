import { registerLocaleData } from '@angular/common';
import localeEsPe from '@angular/common/locales/es-PE';
import { ComponentFixture, fakeAsync, TestBed, tick } from '@angular/core/testing';
import { OrderReceipt } from '../../models/operations.models';
import { CartService } from '../../services/cart.service';
import { LocalOperationsApi, OperationsError } from '../../services/local-operations-api.service';
import { CartDrawer } from './cart-drawer';

registerLocaleData(localeEsPe);

describe('CartDrawer local checkout', () => {
  let fixture: ComponentFixture<CartDrawer>;
  let drawer: CartDrawer;
  let submit: jasmine.Spy;
  let clear: jasmine.Spy;
  let operations: LocalOperationsApi;
  let items: jasmine.Spy;

  beforeEach(() => {
    localStorage.removeItem('estacion-local-pending-order');
    localStorage.removeItem('estacion-local-last-order-v1');
    operations = new LocalOperationsApi();
    submit = spyOn(operations, 'submitOrder');
    spyOn(operations, 'trackOrder').and.resolveTo();
    clear = jasmine.createSpy('clear');
    items = jasmine.createSpy('items').and.returnValue([
      {
        product: { id: 'arroz', name: 'Arroz', price: 20, categoryId: 'marinos' },
        quantity: 2,
        notes: 'Sin picante',
      },
    ]);
    TestBed.configureTestingModule({
      providers: [
        { provide: LocalOperationsApi, useValue: operations },
        {
          provide: CartService,
          useValue: {
            items,
            total: () => 40,
            clear,
          },
        },
      ],
    });
    fixture = TestBed.createComponent(CartDrawer);
    fixture.componentRef.setInput('open', false);
    fixture.componentRef.setInput('directOrdering', true);
    drawer = fixture.componentInstance;
    spyOn(drawer, 'currentTime').and.returnValue(new Date('2026-11-07T16:00:00Z'));
    drawer.customer = {
      name: 'Cliente de prueba',
      phone: '987654321',
      method: 'pickup',
      payment: 'cash',
      address: '',
      reference: '',
    };
  });

  afterEach(() => {
    fixture.destroy();
    localStorage.removeItem('estacion-local-pending-order');
    operations.setReceipt(null);
  });

  it('confirms locally and clears the cart only after a successful save', async () => {
    const open = spyOn(window, 'open');
    submit.and.resolveTo({ id: 1 });
    await drawer.sendOrder();
    expect(submit).toHaveBeenCalledTimes(1);
    expect(submit.calls.mostRecent().args[3]).toBe(4200);
    expect(clear).toHaveBeenCalledTimes(1);
    expect(open).not.toHaveBeenCalled();
    expect(drawer.sending()).toBeFalse();
  });

  it('preserves the cart and reuses the request identifier after a connection failure', async () => {
    submit.and.rejectWith(new OperationsError('Sin conexión', 0));
    await drawer.sendOrder();
    const firstId = submit.calls.mostRecent().args[2];
    expect(clear).not.toHaveBeenCalled();
    expect(drawer.errorMessage()).toBe('Sin conexión');
    submit.and.resolveTo({ id: 1 });
    await drawer.sendOrder();
    expect(submit.calls.mostRecent().args[2]).toBe(firstId);
    expect(clear).toHaveBeenCalledTimes(1);
  });

  it('blocks double clicks while saving and refreshes a changed catalog', async () => {
    let reject!: (error: unknown) => void;
    submit.and.returnValue(new Promise((_, fail) => (reject = fail)));
    const refresh = spyOn(drawer.catalogRefreshRequested, 'emit');
    const first = drawer.sendOrder();
    await drawer.sendOrder();
    expect(submit).toHaveBeenCalledTimes(1);
    reject(new OperationsError('El precio cambió', 409));
    await first;
    expect(refresh).toHaveBeenCalledTimes(1);
    expect(clear).not.toHaveBeenCalled();
  });

  it('keeps active tracking and dismisses the delivered receipt after 20 seconds', fakeAsync(() => {
    const receipt: OrderReceipt = {
      id: 11,
      number: '0011',
      trackingId: '9514ef9e-0850-4ee4-90dc-4e5a00a63ecd',
      status: 'ready',
      items: [],
      subtotalCents: 1200,
      packagingCents: 100,
      totalCents: 1300,
      method: 'pickup',
      payment: 'cash',
      createdAt: '2026-11-07T16:00:00Z',
      updatedAt: '2026-11-07T16:10:00Z',
    };
    const close = spyOn(drawer.closed, 'emit');
    const panel: HTMLElement = fixture.nativeElement;
    items.and.returnValue([]);
    operations.setReceipt(receipt);
    fixture.componentRef.setInput('open', true);
    fixture.detectChanges();

    tick(21_000);
    expect(new LocalOperationsApi().receipt()?.status).toBe('ready');
    expect(panel.querySelector('.order-receipt')?.textContent).toContain('Tu pedido está listo');
    expect(close).not.toHaveBeenCalled();

    operations.setReceipt({ ...receipt, status: 'delivered' });
    fixture.detectChanges();
    tick(19_999);
    expect(panel.querySelector('.order-receipt')?.textContent).toContain('Pedido entregado');
    expect(new LocalOperationsApi().receipt()).toBeNull();
    expect(close).not.toHaveBeenCalled();

    tick(1);
    fixture.detectChanges();
    expect(panel.querySelector('.order-receipt')).toBeNull();
    expect(close).toHaveBeenCalledTimes(1);
    fixture.componentRef.setInput('open', false);
    fixture.detectChanges();
  }));
});
