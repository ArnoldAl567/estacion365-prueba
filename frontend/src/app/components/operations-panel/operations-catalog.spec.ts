import { signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { By } from '@angular/platform-browser';
import { INITIAL_CATALOG } from '../../data/catalog-seed';
import { DEFAULT_DAILY_PRICES } from '../../models/catalog.models';
import { StaffOrder } from '../../models/operations.models';
import { CatalogApiService } from '../../services/catalog-api.service';
import { LocalOperationsApi } from '../../services/local-operations-api.service';
import { AdminPanel } from '../admin-panel/admin-panel';
import { OperationsSalon } from '../operations-salon/operations-salon';
import { OperationsPanel } from './operations-panel';

describe('Local operations panel', () => {
  const soundKey = 'estacion-ops-sound';
  const activeOrder: StaffOrder = {
    id: 17, number: '0017', status: 'pending', method: 'table', tableLabel: '1',
    customerName: '', customerPhone: '', payment: null, address: null, reference: null,
    trackingId: 'test-order', subtotalCents: 0, packagingCents: 0, totalCents: 0, items: [],
    createdAt: '2026-11-15T15:00:00Z', updatedAt: '2026-11-15T15:00:00Z',
  };
  let originalUrl: string;

  beforeEach(() => { originalUrl = window.location.href; });
  afterEach(() => {
    window.history.replaceState(null, '', originalUrl);
    window.sessionStorage.removeItem(soundKey);
  });

  function configure(role: 'admin' | 'kitchen') {
    const user = signal({
      id: 1,
      name: 'Equipo de prueba',
      username: 'prueba',
      role,
      active: true,
    });
    const local = {
      user,
      initialized: signal(true),
      bootstrap: jasmine.createSpy().and.resolveTo(),
      feed: jasmine
        .createSpy()
        .and.resolveTo({ orders: [], summary: { todayCount: 0, todayTotalCents: 0 } }),
      history: jasmine.createSpy().and.resolveTo({
        orders: [], pagination: { page: 1, lastPage: 1, total: 0 },
      }),
      users: jasmine.createSpy().and.resolveTo({ users: [] }),
      dishDemand: jasmine.createSpy().and.resolveTo({
        from: '2026-11-01', to: '2026-11-15', orderCount: 0, rankings: { carta: [], fondos: [], entradas: [] },
      }),
      catalog: jasmine.createSpy().and.resolveTo({
        items: [
          {
            ...INITIAL_CATALOG[0],
            image_url: '/local-api/images/12345678-1234-1234-1234-123456789abc.webp',
          },
        ],
        prices: DEFAULT_DAILY_PRICES,
      }),
      updateCatalogItem: jasmine.createSpy().and.callFake(async (item) => item),
      orderModifiers: jasmine.createSpy().and.resolveTo({ modifiers: [] }),
      salonLayout: jasmine.createSpy().and.resolveTo({
        revision: 0,
        zones: [
          { id: 'salon-a', name: 'Salón A', tables: [{ id: 'table-1', label: 'M1' }, { id: 'table-3', label: 'M3' }] },
          { id: 'salon-b', name: 'Salón B', tables: [{ id: 'table-4', label: 'M4' }] },
        ],
      }),
      saveSalonLayout: jasmine.createSpy().and.callFake(async (layout) => ({ ...layout, revision: 1 })),
    };
    const neon = jasmine.createSpyObj('Neon', ['restoreAdminSession', 'loadItems', 'updateItem']);
    TestBed.configureTestingModule({
      imports: [OperationsPanel],
      providers: [
        { provide: LocalOperationsApi, useValue: local },
        { provide: CatalogApiService, useValue: neon },
      ],
    });
    return { local, neon };
  }

  it('opens Salón by default and starts an order with the selected table', async () => {
    configure('admin');
    window.history.replaceState(null, '', '/administrar');
    const fixture = TestBed.createComponent(OperationsPanel);
    fixture.detectChanges();
    await fixture.whenStable();
    fixture.detectChanges();
    await fixture.whenStable();
    fixture.detectChanges();

    const panel = fixture.componentInstance;
    const root: HTMLElement = fixture.nativeElement;
    expect(window.location.hash).toBe('#salon');
    expect(root.querySelectorAll('.salon-table').length).toBe(3);
    panel.orders.set([activeOrder]);
    fixture.detectChanges();
    expect(root.querySelector('.salon-table.occupied')?.textContent).toContain('#0017');
    root.querySelector<HTMLButtonElement>('[aria-label="Nuevo pedido en mesa M3"]')!.click();
    fixture.detectChanges();
    await fixture.whenStable();
    expect(root.querySelector<HTMLInputElement>('dialog input[name="table"]')?.value).toBe('M3');
    fixture.destroy();
  });

  it('opens the mobile navigation and retains the active route', async () => {
    configure('kitchen');
    window.history.replaceState(null, '', '/administrar#cocina');
    const fixture = TestBed.createComponent(OperationsPanel);
    fixture.detectChanges();
    await fixture.whenStable();
    fixture.detectChanges();

    const root: HTMLElement = fixture.nativeElement;
    root.querySelector<HTMLButtonElement>('.navigation-trigger')!.click();
    const dialog = root.querySelector<HTMLDialogElement>('.mobile-navigation')!;
    expect(dialog.matches(':modal')).toBeTrue();
    expect(dialog.querySelector('[aria-current="page"]')?.getAttribute('href')).toBe('/administrar#cocina');
    expect(dialog.querySelector('[href="/administrar#equipo"]')).toBeNull();
    dialog.querySelector<HTMLButtonElement>('[aria-label="Cerrar menú"]')!.click();
    expect(dialog.open).toBeFalse();
    fixture.destroy();
  });

  it('keeps the order detail open underneath the cancellation dialog', async () => {
    configure('admin');
    window.history.replaceState(null, '', '/administrar#pedidos');
    const fixture = TestBed.createComponent(OperationsPanel);
    fixture.detectChanges();
    await fixture.whenStable();
    fixture.componentInstance.selected.set(activeOrder);
    fixture.detectChanges();

    const root: HTMLElement = fixture.nativeElement;
    const detail = root.querySelector<HTMLDialogElement>('dialog.order-detail')!;
    expect(detail.matches(':modal')).toBeTrue();
    detail.querySelector<HTMLButtonElement>('.detail-cancel')!.click();
    fixture.detectChanges();
    const cancellation = root.querySelector<HTMLDialogElement>('dialog.cancel-dialog')!;
    expect(cancellation.matches(':modal')).toBeTrue();
    cancellation.querySelector<HTMLButtonElement>('button[type="button"]')!.click();
    expect(cancellation.open).toBeFalse();
    expect(detail.open).toBeTrue();
    fixture.destroy();
  });

  it('keeps the history, team and reports reachable after changing the navigation', async () => {
    const { local } = configure('admin');
    window.history.replaceState(null, '', '/administrar#pedidos');
    const fixture = TestBed.createComponent(OperationsPanel);
    fixture.detectChanges();
    await fixture.whenStable();

    for (const [view, heading] of [
      ['history', 'Historial de pedidos'], ['users', 'Equipo y accesos'], ['reports', 'Reportes'],
    ] as const) {
      await fixture.componentInstance.selectView(view);
      fixture.detectChanges();
      await fixture.whenStable();
      fixture.detectChanges();
      expect((fixture.nativeElement as HTMLElement).querySelector('h1')?.textContent).toBe(heading);
    }
    expect(local.history).toHaveBeenCalled();
    expect(local.users).toHaveBeenCalled();
    expect(local.dishDemand).toHaveBeenCalled();
    fixture.destroy();
  });

  it('adds a zone and table to the saved layout used by the board', async () => {
    const { local } = configure('admin');
    window.history.replaceState(null, '', '/administrar#salon');
    const fixture = TestBed.createComponent(OperationsPanel);
    fixture.detectChanges();
    await fixture.whenStable();
    fixture.detectChanges();
    await fixture.whenStable();
    fixture.detectChanges();

    const salon = fixture.debugElement.query(By.directive(OperationsSalon)).componentInstance as OperationsSalon;
    const root: HTMLElement = fixture.nativeElement;
    root.querySelector<HTMLButtonElement>('.salon-tools [aria-haspopup="dialog"]')!.click();
    fixture.detectChanges();
    const dialog = root.querySelector<HTMLDialogElement>('.layout-dialog')!;
    expect(dialog.matches(':modal')).toBeTrue();
    const draft = salon.draft()!;
    salon.addZone(draft);
    const zone = draft.zones[draft.zones.length - 1];
    zone.name = 'Terraza';
    salon.addTable(draft, zone);
    zone.tables[0].label = 'Ventana';
    const escape = new Event('cancel', { cancelable: true });
    dialog.dispatchEvent(escape);
    fixture.detectChanges();
    expect(escape.defaultPrevented).toBeTrue();
    expect(dialog.open).toBeTrue();
    expect(dialog.textContent).toContain('¿Descartar los cambios del plano?');
    salon.continueEditing();
    await salon.saveLayout(draft);
    fixture.detectChanges();

    expect(local.saveSalonLayout).toHaveBeenCalledWith(jasmine.objectContaining({ revision: 0 }));
    expect(root.querySelector('[aria-label="Terraza"] h3')?.textContent).toBe('Ventana');
    expect(dialog.open).toBeFalse();
    expect(root.querySelector('.layout-editor')).toBeNull();
    fixture.destroy();
  });

  it('shares the local session and saves catalog edits through Laravel without a second login', async () => {
    const { local, neon } = configure('admin');
    window.history.replaceState(null, '', '/administrar#carta');
    const fixture = TestBed.createComponent(OperationsPanel);
    fixture.detectChanges();
    await fixture.whenStable();
    fixture.detectChanges();
    await fixture.whenStable();
    fixture.detectChanges();
    const catalog = fixture.debugElement.query(By.directive(AdminPanel))
      .componentInstance as AdminPanel;
    expect(catalog.embedded()).toBeTrue();
    expect(catalog.authenticated()).toBeTrue();
    expect(local.catalog).toHaveBeenCalledTimes(1);
    expect(fixture.nativeElement.querySelector('#admin-username')).toBeNull();
    expect(fixture.nativeElement.querySelector('.admin-header')).toBeNull();
    catalog.edit(catalog.rows()[0]);
    catalog.editForm!.name = 'Nombre local actualizado';
    await catalog.saveItem();
    expect(local.updateCatalogItem).toHaveBeenCalledWith(
      jasmine.objectContaining({ name: 'Nombre local actualizado' }),
    );
    expect(neon.restoreAdminSession).not.toHaveBeenCalled();
    expect(neon.loadItems).not.toHaveBeenCalled();
    expect(neon.updateItem).not.toHaveBeenCalled();
    fixture.destroy();
  });

  it('keeps the catalog editor inaccessible to kitchen accounts', async () => {
    const { local } = configure('kitchen');
    window.history.replaceState(null, '', '/administrar#carta');
    const fixture = TestBed.createComponent(OperationsPanel);
    fixture.detectChanges();
    await fixture.whenStable();
    await fixture.componentInstance.selectView('catalog');
    fixture.detectChanges();
    expect(fixture.componentInstance.view()).toBe('kitchen');
    expect(window.location.hash).toBe('#cocina');
    expect(fixture.nativeElement.querySelector('a[aria-label="Carta digital"]')).toBeNull();
    expect(fixture.debugElement.query(By.directive(AdminPanel))).toBeNull();
    expect(local.catalog).not.toHaveBeenCalled();
    fixture.destroy();
  });

  it('loads a direct history link and follows browser history between modules', async () => {
    const { local } = configure('admin');
    window.history.replaceState(null, '', '/administrar#historial');
    const fixture = TestBed.createComponent(OperationsPanel);
    fixture.detectChanges();
    await fixture.whenStable();
    fixture.detectChanges();
    expect(fixture.componentInstance.view()).toBe('history');
    expect(local.history).toHaveBeenCalledTimes(1);

    const teamLink = fixture.nativeElement.querySelector('a[aria-label="Equipo"]') as HTMLAnchorElement;
    expect(teamLink.getAttribute('href')).toBe('/administrar#equipo');
    const changed = () => new Promise<void>((resolve) =>
      window.addEventListener('hashchange', () => resolve(), { once: true }),
    );
    const teamOpened = changed();
    teamLink.click();
    await teamOpened;
    await fixture.whenStable();
    expect(fixture.componentInstance.view()).toBe('users');
    expect(local.users).toHaveBeenCalledTimes(1);

    const historyRestored = changed();
    window.history.back();
    await historyRestored;
    await fixture.whenStable();
    expect(window.location.hash).toBe('#historial');
    expect(fixture.componentInstance.view()).toBe('history');
    expect(local.history).toHaveBeenCalledTimes(2);
    fixture.destroy();
  });

  it('rings for new orders in a hidden tab only while sound is enabled', async () => {
    const { local } = configure('admin');
    window.history.replaceState(null, '', '/administrar#historial');
    spyOnProperty(document, 'hidden', 'get').and.returnValue(true);
    const timers = spyOn(window, 'setInterval').and.returnValue(0);
    const start = jasmine.createSpy('start');
    const output = {
      gain: jasmine.createSpyObj('AudioParam', [
        'setValueAtTime', 'linearRampToValueAtTime', 'setTargetAtTime',
      ]),
      connect: jasmine.createSpy('connect'),
      disconnect: jasmine.createSpy('disconnect'),
    };
    const audio = {
      state: 'running',
      currentTime: 0,
      resume: jasmine.createSpy('resume').and.resolveTo(),
      close: jasmine.createSpy('close').and.resolveTo(),
      createGain: () => output,
      createOscillator: () => ({ frequency: { value: 0 }, connect: () => output, start, stop: () => {} }),
    };
    spyOn(window, 'AudioContext').and.returnValue(audio as unknown as AudioContext);

    const fixture = TestBed.createComponent(OperationsPanel);
    fixture.detectChanges();
    await fixture.whenStable();
    const panel = fixture.componentInstance;
    const poll = timers.calls.allArgs().find(([, delay]) => delay === 3000)?.[0] as () => void;
    poll();
    expect(local.feed).toHaveBeenCalledTimes(1);

    await panel.toggleSound();
    expect(window.sessionStorage.getItem(soundKey)).toBe('1');
    expect(start).toHaveBeenCalled();
    start.calls.reset();
    local.feed.and.resolveTo({
      orders: [{ id: 7, number: '0007', status: 'pending' }],
      summary: { todayCount: 1, todayTotalCents: 1200 },
    });
    poll();
    await fixture.whenStable();
    expect(start).toHaveBeenCalled();
    expect(panel.notice()).toContain('#0007');

    start.calls.reset();
    poll();
    await fixture.whenStable();
    expect(start).not.toHaveBeenCalled();
    await panel.toggleSound();
    expect(window.sessionStorage.getItem(soundKey)).toBeNull();
    poll();
    expect(local.feed).toHaveBeenCalledTimes(3);
    fixture.destroy();
  });

  it('restores the sound preference without waiting for browser playback permission', async () => {
    configure('admin');
    window.history.replaceState(null, '', '/administrar#cocina');
    window.sessionStorage.setItem(soundKey, '1');
    let allowAudio = false;
    const audio = {
      state: 'suspended',
      resume: jasmine.createSpy('resume').and.callFake(() => {
        if (!allowAudio) return new Promise<void>(() => {});
        audio.state = 'running';
        return Promise.resolve();
      }),
      close: jasmine.createSpy('close').and.resolveTo(),
    };
    spyOn(window, 'AudioContext').and.returnValue(audio as unknown as AudioContext);

    const fixture = TestBed.createComponent(OperationsPanel);
    fixture.detectChanges();
    await fixture.whenStable();
    const panel = fixture.componentInstance;
    expect(panel.loading()).toBeFalse();
    expect(panel.sound()).toBeTrue();
    expect(panel.soundReady()).toBeFalse();

    allowAudio = true;
    window.dispatchEvent(new MouseEvent('click'));
    await fixture.whenStable();
    expect(panel.soundReady()).toBeTrue();
    expect(window.sessionStorage.getItem(soundKey)).toBe('1');
    fixture.destroy();
  });
});
