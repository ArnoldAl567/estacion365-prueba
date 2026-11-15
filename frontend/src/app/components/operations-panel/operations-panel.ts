import { CurrencyPipe, DatePipe, registerLocaleData } from '@angular/common';
import localeEsPe from '@angular/common/locales/es-PE';
import {
  Component,
  computed,
  ElementRef,
  HostListener,
  inject,
  NgZone,
  OnDestroy,
  OnInit,
  signal,
  viewChild,
} from '@angular/core';
import { FormsModule } from '@angular/forms';
import { LocalOperationsApi, OperationsError } from '../../services/local-operations-api.service';
import {
  ORDER_STATUSES,
  isActiveOrderStatus,
  OrderStatus,
  StaffOrder,
  StaffRole,
  StaffUser,
  staffOrderMethod,
  staffOrderPayment,
} from '../../models/operations.models';
import { OperationsAccess, OperationsCredentials } from '../operations-access/operations-access';
import { AdminPanel } from '../admin-panel/admin-panel';
import { CatalogApiService } from '../../services/catalog-api.service';
import { LocalCatalogAdapter } from '../../services/local-catalog-adapter.service';
import { OperationsKitchen } from '../operations-kitchen/operations-kitchen';
import { OperationsOrderDetail } from '../operations-order-detail/operations-order-detail';
import { OperationsReports } from '../operations-reports/operations-reports';
import { OperationsOrderForm } from '../operations-order-form/operations-order-form';
import { OperationsSalon } from '../operations-salon/operations-salon';

registerLocaleData(localeEsPe);

type OperationsView = 'salon' | 'orders' | 'kitchen' | 'history' | 'users' | 'catalog' | 'reports';
const SOUND_PREFERENCE_KEY = 'estacion-ops-sound';
const VIEW_FRAGMENTS: Record<OperationsView, string> = {
  salon: '#salon',
  orders: '#pedidos',
  kitchen: '#cocina',
  history: '#historial',
  users: '#equipo',
  catalog: '#carta',
  reports: '#reportes',
};

interface StaffForm {
  id?: number;
  name: string;
  username: string;
  role: StaffRole;
  password: string;
}

@Component({
  selector: 'app-operations-panel',
  imports: [
    FormsModule,
    CurrencyPipe,
    DatePipe,
    OperationsAccess,
    AdminPanel,
    OperationsKitchen,
    OperationsOrderDetail,
    OperationsReports,
    OperationsOrderForm,
    OperationsSalon,
  ],
  providers: [{ provide: CatalogApiService, useClass: LocalCatalogAdapter }],
  templateUrl: './operations-panel.html',
  styleUrls: ['./operations-panel.scss', './operations-team.scss', './operations-navigation.scss'],
})
export class OperationsPanel implements OnInit, OnDestroy {
  readonly api = inject(LocalOperationsApi);
  private readonly zone = inject(NgZone);
  readonly loading = signal(true);
  readonly busy = signal(false);
  readonly view = signal<OperationsView>('salon');
  readonly orders = signal<StaffOrder[]>([]);
  readonly summary = signal({ todayCount: 0, todayTotalCents: 0 });
  readonly online = signal(false);
  readonly error = signal('');
  readonly connectionError = signal('');
  readonly notice = signal('');
  readonly search = signal('');
  readonly statusFilter = signal<'all' | OrderStatus>('all');
  readonly selected = signal<StaffOrder | null>(null);
  readonly editor = signal<StaffOrder | 'new' | null>(null);
  readonly editorTable = signal<string | null>(null);
  readonly editorTableId = signal<string | null>(null);
  readonly includeSalon = signal(false);
  readonly staffOrderMethod = staffOrderMethod;
  readonly staffOrderPayment = staffOrderPayment;
  readonly changing = signal(new Set<number>());
  readonly cancellation = signal<StaffOrder | null>(null);
  readonly cancellationError = signal('');
  private readonly cancellationDialog = viewChild<ElementRef<HTMLDialogElement>>('cancellationDialog');
  cancellationReason = '';
  readonly history = signal<StaffOrder[]>([]);
  readonly historyLoading = signal(false);
  readonly pagination = signal({ page: 1, lastPage: 1, total: 0 });
  readonly users = signal<StaffUser[]>([]);
  readonly staffForm = signal<StaffForm | null>(null);
  readonly sound = signal(false);
  readonly soundReady = signal(false);
  readonly now = signal(Date.now());
  readonly statuses = ORDER_STATUSES;
  readonly lanes = ORDER_STATUSES.slice(0, 3);
  readonly isAdmin = computed(() => this.api.user()?.role === 'admin');
  readonly listedOrders = computed(() =>
    this.includeSalon() ? this.orders() : this.orders().filter((order) => order.method !== 'table'),
  );
  readonly counts = computed(
    () =>
      Object.fromEntries(
        this.statuses.map((status) => [
          status.id,
          (this.view() === 'orders' ? this.listedOrders() : this.orders())
            .filter((order) => order.status === status.id).length,
        ]),
      ) as Record<OrderStatus, number>,
  );
  readonly outsidePending = computed(() =>
    this.orders().filter((order) => order.method !== 'table' && order.status === 'pending').length,
  );
  readonly filteredOrders = computed(() => {
    const search = this.search().trim().toLocaleLowerCase('es-PE');
    return this.listedOrders().filter(
      (order) =>
        (this.statusFilter() === 'all' || order.status === this.statusFilter()) &&
        (!search ||
          `${order.number} ${order.customerName} ${order.customerPhone} ${staffOrderMethod(order)}`
            .toLocaleLowerCase('es-PE')
            .includes(search)),
    );
  });
  readonly pageTitle = computed(
    () =>
      ({
        salon: 'Salón',
        orders: 'Para llevar / Delivery',
        kitchen: 'Cocina en vivo',
        history: 'Historial de pedidos',
        users: 'Equipo y accesos',
        catalog: 'Carta digital',
        reports: 'Reportes',
      })[this.view()],
  );
  readonly navigation = [
    { id: 'salon' as const, label: 'Salón', icon: 'M3 3h18v18H3zM3 9h18M3 15h18M9 3v18M15 3v18' },
    { id: 'orders' as const, label: 'Para llevar / Delivery', icon: 'M8 3h8l4 4v14H4V3h4m0 5h8M8 12h8M8 16h5' },
    { id: 'history' as const, label: 'Historial', icon: 'M3 11a9 9 0 1 1 2 7M3 4v7h7M12 7v5l3 2' },
    {
      id: 'kitchen' as const,
      label: 'Cocina',
      icon: 'M4 16h16M5 16a7 7 0 0 1 14 0M12 5V3M9 3h6M3 20h18',
    },
    {
      id: 'catalog' as const,
      label: 'Carta digital',
      icon: 'M3 4h7l2 2 2-2h7v16h-7l-2 2-2-2H3V4m9 2v16M6 8h3M6 12h3M15 8h3M15 12h3',
    },
    {
      id: 'reports' as const,
      label: 'Reportes',
      icon: 'M4 4v16h16M8 15v2M12 11v6M16 7v10',
    },
    {
      id: 'users' as const,
      label: 'Equipo',
      icon: 'M15 21v-2a5 5 0 0 0-10 0v2M10 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8M17 4a4 4 0 0 1 0 7M21 21v-2a5 5 0 0 0-4-4',
    },
  ];
  readonly navGroups: { label: string; views: OperationsView[] }[] = [
    { label: '', views: ['salon'] },
    { label: 'Órdenes', views: ['orders', 'history', 'kitchen'] },
    { label: 'Menú', views: ['catalog'] },
    { label: 'Administración', views: ['reports', 'users'] },
  ];
  readonly collapsedGroups = signal<string[]>([]);
  readonly visibleNav = computed(() => this.navGroups.map((group) => ({
    label: group.label,
    entries: this.navigation.filter((entry) => group.views.includes(entry.id) && this.canOpenView(entry.id)),
  })).filter((group) => group.entries.length));
  historyDate = '';
  historySearch = '';
  private pollTimer?: number;
  private clockTimer?: number;
  private noticeTimer?: number;
  private refreshing = false;
  private feedRevision = 0;
  private historyRequest = 0;
  private knownOrders = new Set<number>();
  private initialFeed = true;
  private audio?: AudioContext;
  private bellOutput?: GainNode;
  private readonly visibilityHandler = () => {
    if (!document.hidden && this.api.user()) {
      this.now.set(Date.now());
      void this.refresh();
    }
  };

  async ngOnInit(): Promise<void> {
    await this.initialize();
    this.zone.runOutsideAngular(() => {
      this.pollTimer = window.setInterval(() => {
        if (this.api.user() && (!document.hidden || this.sound())) {
          void this.zone.run(() => this.refresh());
        }
      }, 3000);
      this.clockTimer = window.setInterval(
        () => {
          if (!document.hidden && this.api.user()) {
            this.zone.run(() => this.now.set(Date.now()));
          }
        },
        1000,
      );
    });
    document.addEventListener('visibilitychange', this.visibilityHandler);
  }

  ngOnDestroy(): void {
    window.clearInterval(this.pollTimer);
    window.clearInterval(this.clockTimer);
    window.clearTimeout(this.noticeTimer);
    document.removeEventListener('visibilitychange', this.visibilityHandler);
    if (this.audio) {
      this.audio.onstatechange = null;
      void this.audio.close();
    }
  }

  async initialize(): Promise<void> {
    this.loading.set(true);
    this.error.set('');
    try {
      await this.api.bootstrap();
      if (this.api.user()) await this.afterLogin();
    } catch (error) {
      this.error.set(this.message(error));
    } finally {
      this.loading.set(false);
    }
  }

  async signIn(credentials: OperationsCredentials): Promise<void> {
    if (this.busy()) return;
    this.busy.set(true);
    this.error.set('');
    try {
      if (this.api.initialized()) await this.api.login(credentials.username, credentials.password);
      else
        await this.api.setup(
          credentials.name,
          credentials.username,
          credentials.password,
          credentials.confirmation,
          credentials.setupCode,
        );
      await this.afterLogin();
    } catch (error) {
      this.error.set(this.message(error));
    } finally {
      this.busy.set(false);
    }
  }

  async signOut(): Promise<void> {
    this.busy.set(true);
    try {
      await this.api.logout();
      this.orders.set([]);
      this.selected.set(null);
      this.editor.set(null);
      this.users.set([]);
      this.staffForm.set(null);
      this.muteSound();
      this.dismissNotice();
      this.error.set('');
    } catch (error) {
      this.error.set(this.message(error));
    } finally {
      this.busy.set(false);
    }
  }

  private async afterLogin(): Promise<void> {
    this.initialFeed = true;
    this.knownOrders.clear();
    this.restoreSound();
    await Promise.all([this.syncViewFromUrl(), this.refresh()]);
  }

  async refresh(): Promise<void> {
    if (this.refreshing || !this.api.user()) return;
    this.refreshing = true;
    const userId = this.api.user()!.id;
    const revision = this.feedRevision;
    try {
      const feed = await this.api.feed();
      if (this.api.user()?.id !== userId || revision !== this.feedRevision) return;
      const arrivals = feed.orders.filter(
        (order) => !this.knownOrders.has(order.id) && order.status === 'pending',
      );
      if (!this.initialFeed && arrivals.length) {
        this.showNotice(
          arrivals.length === 1
            ? `Nuevo pedido #${arrivals[0].number} recibido.`
            : `${arrivals.length} nuevos pedidos recibidos.`,
        );
        void this.playSound();
      }
      feed.orders.forEach((order) => this.knownOrders.add(order.id));
      this.initialFeed = false;
      this.orders.set(feed.orders);
      this.summary.set(feed.summary);
      this.online.set(true);
      this.connectionError.set('');
      const selected = this.selected();
      if (selected && isActiveOrderStatus(selected.status)) {
        const current = feed.orders.find((order) => order.id === selected.id);
        if (
          !current ||
          current.status !== selected.status ||
          current.updatedAt !== selected.updatedAt
        )
          void this.openDetails(current ?? selected);
      }
    } catch (error) {
      this.online.set(false);
      this.connectionError.set(this.message(error));
    } finally {
      this.refreshing = false;
    }
  }

  viewUrl(view: OperationsView): string {
    return `/administrar${VIEW_FRAGMENTS[view]}`;
  }

  @HostListener('window:hashchange')
  async syncViewFromUrl(): Promise<void> {
    if (!this.api.user()) return;
    const { pathname, hash } = window.location;
    const requested =
      pathname.replace(/\/$/, '') === '/panel-administrativo'
        ? 'catalog'
        : this.navigation.find((entry) => VIEW_FRAGMENTS[entry.id] === hash)?.id;
    const fallback = this.isAdmin() ? 'salon' : 'kitchen';
    const view = requested && this.canOpenView(requested) ? requested : fallback;
    const url = this.viewUrl(view);
    if (`${pathname}${hash}` !== url) window.history.replaceState(null, '', url);
    await this.showView(view);
  }

  async selectView(view: OperationsView): Promise<void> {
    if (!this.api.user() || !this.canOpenView(view)) return;
    const url = this.viewUrl(view);
    if (`${window.location.pathname}${window.location.hash}` !== url) {
      window.history.pushState(null, '', url);
    }
    await this.showView(view);
  }

  canOpenView(view: OperationsView): boolean {
    return this.isAdmin() || view === 'orders' || view === 'history' || view === 'kitchen';
  }

  private async showView(view: OperationsView): Promise<void> {
    if (view !== this.view()) this.editor.set(null);
    this.view.set(view);
    requestAnimationFrame(() => window.scrollTo({ top: 0, behavior: 'instant' }));
    this.error.set('');
    this.selected.set(null);
    this.cancellationDialog()?.nativeElement.close();
    if (view === 'history') await this.loadHistory();
    if (view === 'users') await this.loadUsers();
  }

  selectStatus(status: 'all' | OrderStatus): void {
    this.statusFilter.set(status);
    if (this.view() !== 'orders') {
      this.includeSalon.set(true);
      void this.selectView('orders');
    }
  }

  toggleNavGroup(label: string): void {
    this.collapsedGroups.update((groups) => groups.includes(label)
      ? groups.filter((group) => group !== label)
      : [...groups, label]);
  }

  laneOrders(status: OrderStatus): StaffOrder[] {
    return this.orders()
      .filter((order) => order.status === status)
      .reverse();
  }
  statusLabel(status: OrderStatus): string {
    return this.statuses.find((option) => option.id === status)?.label ?? status;
  }
  openEditor(order: StaffOrder | 'new', table: string | null = null, tableId: string | null = null): void {
    this.editorTable.set(table);
    this.editorTableId.set(tableId);
    this.editor.set(order);
  }

  async orderSaved(order: StaffOrder): Promise<void> {
    this.editor.set(null);
    this.showNotice('Pedido #' + order.number + ' guardado.');
    await this.refresh();
  }
  async salonLayoutSaved(): Promise<void> {
    this.showNotice('Plano del salón guardado.');
    await this.refresh();
  }
  nextAction(order: StaffOrder): string {
    return this.statuses.find((option) => option.id === order.status)?.action ?? '';
  }
  quantity(order: StaffOrder): number {
    return order.items.reduce((total, item) => total + item.quantity, 0);
  }
  minutes(order: StaffOrder): number {
    return Math.max(0, Math.floor((this.now() - new Date(order.createdAt).getTime()) / 60000));
  }

  async advance(order: StaffOrder): Promise<void> {
    const next = this.statuses.find((status) => status.id === order.status)?.next;
    if (next) await this.updateStatus(order, next);
  }

  requestCancellation(order: StaffOrder): void {
    const dialog = this.cancellationDialog()?.nativeElement;
    if (!dialog || dialog.open || !isActiveOrderStatus(order.status) || this.changing().has(order.id)) return;
    this.cancellationReason = '';
    this.cancellationError.set('');
    this.cancellation.set(order);
    dialog.showModal();
  }

  async confirmCancellation(): Promise<void> {
    const order = this.cancellation();
    const reason = this.cancellationReason.trim();
    if (order && reason) await this.updateStatus(order, 'cancelled', reason);
  }

  private async updateStatus(order: StaffOrder, next: OrderStatus, reason?: string): Promise<void> {
    if (this.changing().has(order.id)) return;
    this.changing.update((ids) => new Set([...ids, order.id]));
    this.error.set('');
    this.cancellationError.set('');
    const dialog = next === 'cancelled' ? this.cancellationDialog()?.nativeElement : undefined;
    try {
      const result = await this.api.changeStatus(order, next, reason);
      if (next === 'cancelled') {
        this.feedRevision++;
        this.orders.update((orders) => orders.filter((current) => current.id !== order.id));
        dialog?.close();
      }
      if (this.selected()?.id === order.id) this.selected.set(result.order);
      this.showNotice(`Pedido #${order.number} · ${this.statusLabel(next)}.`);
      void this.playSound('status');
      await this.refresh();
      if (this.selected()?.id === order.id) await this.openDetails(result.order);
      if (this.view() === 'history') await this.loadHistory(this.pagination().page);
    } catch (error) {
      await this.refresh();
      if (dialog?.open && !(error instanceof OperationsError && error.status === 409)) {
        this.cancellationError.set(this.message(error));
      } else {
        dialog?.close();
        this.error.set(this.message(error));
      }
    } finally {
      this.changing.update((ids) => {
        const next = new Set(ids);
        next.delete(order.id);
        return next;
      });
    }
  }

  async openDetails(order: StaffOrder): Promise<void> {
    this.selected.set(order);
    try {
      const result = await this.api.detail(order.id);
      if (this.selected()?.id === order.id) this.selected.set(result.order);
      requestAnimationFrame(() => document.getElementById('close-order-detail')?.focus());
    } catch (error) {
      this.error.set(this.message(error));
    }
  }

  async loadHistory(page = 1): Promise<void> {
    const request = ++this.historyRequest;
    this.historyLoading.set(true);
    this.error.set('');
    try {
      const result = await this.api.history(this.historyDate, this.historySearch.trim(), page);
      if (request !== this.historyRequest) return;
      this.history.set(result.orders);
      this.pagination.set(result.pagination);
    } catch (error) {
      this.error.set(this.message(error));
    } finally {
      if (request === this.historyRequest) this.historyLoading.set(false);
    }
  }

  async loadUsers(): Promise<void> {
    try {
      this.users.set((await this.api.users()).users);
    } catch (error) {
      this.error.set(this.message(error));
    }
  }

  editUser(user?: StaffUser): void {
    this.staffForm.set(
      user
        ? { id: user.id, name: user.name, username: user.username, role: user.role, password: '' }
        : { name: '', username: '', role: 'kitchen', password: '' },
    );
    if (window.matchMedia('(max-width: 1200px)').matches) {
      requestAnimationFrame(() =>
        document
          .querySelector('.staff-editor')
          ?.scrollIntoView({ block: 'start', behavior: 'smooth' }),
      );
    }
  }

  async saveUser(): Promise<void> {
    const form = this.staffForm();
    if (!form || this.busy()) return;
    this.busy.set(true);
    this.error.set('');
    try {
      if (form.id) {
        const result = await this.api.updateUser(form.id, {
          name: form.name,
          role: form.role,
          ...(form.password ? { password: form.password } : {}),
        });
        if (this.api.user()?.id === form.id) this.api.user.set(result.user);
      } else
        await this.api.createUser({
          name: form.name,
          username: form.username,
          role: form.role,
          password: form.password,
        });
      this.staffForm.set(null);
      await this.loadUsers();
      this.showNotice('Acceso del equipo guardado.');
    } catch (error) {
      this.error.set(this.message(error));
    } finally {
      this.busy.set(false);
    }
  }

  async toggleUser(user: StaffUser): Promise<void> {
    if (this.busy()) return;
    this.busy.set(true);
    this.error.set('');
    try {
      await this.api.updateUser(user.id, { active: !user.active });
      await this.loadUsers();
      this.showNotice(`${user.name} · acceso ${user.active ? 'desactivado' : 'activado'}.`);
    } catch (error) {
      this.error.set(this.message(error));
    } finally {
      this.busy.set(false);
    }
  }

  private showNotice(message: string): void {
    window.clearTimeout(this.noticeTimer);
    this.notice.set(message);
    this.noticeTimer = this.zone.runOutsideAngular(() =>
      window.setTimeout(() => this.zone.run(() => this.dismissNotice()), 4000),
    );
  }

  dismissNotice(): void {
    window.clearTimeout(this.noticeTimer);
    this.noticeTimer = undefined;
    this.notice.set('');
  }

  async toggleSound(): Promise<void> {
    if (this.sound()) {
      this.muteSound();
      return;
    }
    this.sound.set(true);
    this.saveSoundPreference();
    if (await this.resumeSound()) {
      await this.playSound();
    }
  }

  private muteSound(): void {
    this.sound.set(false);
    this.soundReady.set(false);
    this.saveSoundPreference();
    this.bellOutput?.disconnect();
    this.bellOutput = undefined;
  }

  private saveSoundPreference(): void {
    try {
      const user = this.api.user();
      if (this.sound() && user) window.sessionStorage.setItem(SOUND_PREFERENCE_KEY, String(user.id));
      else window.sessionStorage.removeItem(SOUND_PREFERENCE_KEY);
    } catch {
      // La preferencia sigue activa en memoria si el almacenamiento está bloqueado.
    }
  }

  private restoreSound(): void {
    const user = this.api.user();
    try {
      if (!user || window.sessionStorage.getItem(SOUND_PREFERENCE_KEY) !== String(user.id)) return;
    } catch {
      return;
    }
    this.sound.set(true);
    void this.resumeSound();
  }

  @HostListener('window:click')
  unlockSoundOnInteraction(): void {
    if (this.sound() && !this.soundReady()) void this.resumeSound();
  }

  private async resumeSound(): Promise<boolean> {
    if (!this.sound() || !this.api.user()) return false;
    try {
      if (!this.audio) {
        const audio = new AudioContext();
        audio.onstatechange = () => this.soundReady.set(this.sound() && audio.state === 'running');
        this.audio = audio;
      }
      if (this.audio.state !== 'running') await this.audio.resume();
      const ready = this.sound() && this.audio.state === 'running';
      this.soundReady.set(ready);
      return ready;
    } catch {
      this.soundReady.set(false);
      return false;
    }
  }

  private async playSound(pattern: 'arrival' | 'status' = 'arrival'): Promise<void> {
    const audio = this.audio;
    if (!this.sound() || !audio || audio.state === 'closed') return;
    if (audio.state !== 'running' && !(await this.resumeSound())) return;
    if (!this.sound() || audio.state !== 'running') return;

    // Reemplaza cualquier repique activo para evitar que se sumen los picos.
    this.bellOutput?.disconnect();
    const output = audio.createGain();
    output.connect(audio.destination);
    this.bellOutput = output;

    // Los armónicos agudos se apagan antes, como en una campanilla de metal.
    const partials = [
      { frequency: 1150, level: 0.5, decay: 0.19 },
      { frequency: 1646, level: 0.15, decay: 0.11 },
      { frequency: 2495, level: 0.12, decay: 0.08 },
      { frequency: 3428, level: 0.1, decay: 0.05 },
      { frequency: 4712, level: 0.04, decay: 0.027 },
    ];
    const firstStrike = audio.currentTime + 0.01;
    const strikes = pattern === 'arrival' ? [0, 0.4, 0.8] : [0];
    for (const offset of strikes) {
      const start = firstStrike + offset;
      for (const { frequency, level, decay } of partials) {
        const oscillator = audio.createOscillator();
        const gain = audio.createGain();
        oscillator.frequency.value = frequency;
        gain.gain.setValueAtTime(0, start);
        gain.gain.linearRampToValueAtTime(level, start + 0.004);
        gain.gain.setTargetAtTime(0, start + 0.004, decay);
        oscillator.connect(gain).connect(output);
        oscillator.start(start);
        oscillator.stop(start + 1);
      }
    }
  }

  @HostListener('document:keydown', ['$event']) onDetailKeydown(event: KeyboardEvent): void {
    if (this.cancellationDialog()?.nativeElement.open || !this.selected()) return;
    if (event.key === 'Escape') {
      this.selected.set(null);
      return;
    }
    if (event.key !== 'Tab') return;
    const controls = document.querySelectorAll<HTMLElement>(
      '.order-detail button:not([disabled]), .order-detail a[href]',
    );
    const first = controls[0];
    const last = controls[controls.length - 1];
    if (event.shiftKey && document.activeElement === first) {
      event.preventDefault();
      last?.focus();
    } else if (!event.shiftKey && document.activeElement === last) {
      event.preventDefault();
      first?.focus();
    }
  }
  private message(error: unknown): string {
    return error instanceof Error ? error.message : 'No se pudo completar la operación.';
  }
}
