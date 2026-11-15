import { signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { By } from '@angular/platform-browser';
import { INITIAL_CATALOG } from '../../data/catalog-seed';
import { DEFAULT_DAILY_PRICES } from '../../models/catalog.models';
import { CatalogApiService } from '../../services/catalog-api.service';
import { LocalOperationsApi } from '../../services/local-operations-api.service';
import { AdminPanel } from '../admin-panel/admin-panel';
import { OperationsPanel } from './operations-panel';

describe('Local operations panel', () => {
  const soundKey = 'estacion-ops-sound';
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
