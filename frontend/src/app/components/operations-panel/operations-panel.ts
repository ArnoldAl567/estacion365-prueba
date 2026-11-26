import { CurrencyPipe, DatePipe, NgTemplateOutlet, registerLocaleData } from '@angular/common';
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
  STAFF_ROLES,
  isActiveOrderStatus,
  OrderStatus,
  ProductionTicket,
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
import { OperationsOrderForm } from '../operations-order-form/operations-order-form';
import { OperationsTheme } from './operations-theme';
import { OperationsSalon } from '../operations-salon/operations-salon';
import { OperationsCash } from '../operations-cash/operations-cash';
import { OperationsInventory } from '../operations-inventory/operations-inventory';
import { InventoryIncidents } from '../inventory-incidents/inventory-incidents';
import { OperationsSettingsPanel } from '../operations-settings/operations-settings';
import { OperationsWorkflowReports } from '../operations-reports/operations-workflow-reports';
import { OperationsSalesReports } from '../operations-reports/operations-sales-reports';
import { OperationsMenuSettings } from '../operations-menu-settings/operations-menu-settings';
import { RecordDialog } from '../record-dialog/record-dialog';
import { RemovalConfirmationDialog } from '../removal-confirmation/removal-confirmation';
import { RemovalConfirmation } from '../../services/removal-confirmation.service';
import {
  OPERATIONS_NAV, OPERATIONS_PAGES, OperationsPageId, OperationsView,
  operationsPageUrl, resolveOperationsPage,
} from '../../config/operations-navigation';

registerLocaleData(localeEsPe);

const SOUND_PREFERENCE_KEY = 'estacion-ops-sound';

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
    NgTemplateOutlet,
    OperationsAccess,
    AdminPanel,
    OperationsKitchen,
    OperationsOrderDetail,
    OperationsOrderForm,
    OperationsTheme,
    OperationsSalon,
    OperationsCash,
    OperationsInventory,
    InventoryIncidents,
    OperationsSettingsPanel,
    OperationsWorkflowReports,
    OperationsSalesReports,
    OperationsMenuSettings,
    RecordDialog,
    RemovalConfirmationDialog,
  ],
  providers: [{ provide: CatalogApiService, useClass: LocalCatalogAdapter }],
  templateUrl: './operations-panel.html',
  styleUrls: ['./operations-panel.scss', './operations-team.scss', './operations-navigation.scss'],
})
export class OperationsPanel implements OnInit, OnDestroy {
  readonly removalConfirmation = inject(RemovalConfirmation);
  readonly api = inject(LocalOperationsApi);
  private readonly zone = inject(NgZone);
  readonly loading = signal(true);
  readonly busy = signal(false);
  readonly page = signal<OperationsPageId>('salon');
  readonly activePage = computed(() => OPERATIONS_PAGES.find((entry) => entry.id === this.page())!);
  readonly view = computed(() => this.activePage().view);
  readonly activeGroup = computed(() => OPERATIONS_NAV.find((group) => group.pages.some((page) => page.id === this.page()))?.label ?? '');
  readonly viewUrl = operationsPageUrl;
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
  readonly methodFilter = signal<'all' | StaffOrder['method']>('all');
  readonly staffOrderMethod = staffOrderMethod;
  readonly staffOrderPayment = staffOrderPayment;
  readonly changing = signal(new Set<number>());
  readonly cancellation = signal<StaffOrder | null>(null);
  readonly cancellationError = signal('');
  readonly cancellationReviewed = signal(false);
  private readonly cancellationDialog = viewChild<ElementRef<HTMLDialogElement>>('cancellationDialog');
  private readonly navigationDialog = viewChild<ElementRef<HTMLDialogElement>>('navigationDialog');
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
  readonly canTakeOrders = computed(() => this.isAdmin() || this.api.user()?.role === 'waiter');
  readonly canViewOrders = computed(() => this.canOpenView('kitchen'));
  readonly canPrepare = computed(() => this.isAdmin() || this.api.user()?.role === 'kitchen');
  readonly roleLabels = STAFF_ROLES;
  readonly roles = Object.entries(STAFF_ROLES);
  readonly currentRole = computed(() => {
    const role = this.api.user()?.role;
    return role ? STAFF_ROLES[role] : '';
  });
  readonly roleAccess = [
    ['Mesero', 'Toma pedidos por mesa, consulta la carta digital de los clientes, edita pedidos, consulta el historial y registra entregas o cancelaciones.'],
    ['Cocina', 'Actualiza comandas, registra entregas y consulta los pedidos.'],
    ['Caja', 'Administra turnos, cobra pedidos y cierra mesas.'],
    ['Inventario', 'Gestiona insumos, compras, recetas, producción y consumo.'],
    ['Administrador', 'Tiene acceso a todos los módulos, incluidos reportes y configuración.'],
  ];
  readonly counts = computed(() => Object.fromEntries(this.statuses.map(status =>
    [status.id, this.orders().filter(order => order.status === status.id).length])) as Record<OrderStatus, number>);
  readonly filteredOrders = computed(() => {
    const search = this.search().trim().toLocaleLowerCase('es-PE');
    return this.orders().filter(
      (order) =>
        (this.statusFilter() === 'all' || order.status === this.statusFilter()) &&
        (this.methodFilter() === 'all' || order.method === this.methodFilter()) &&
        (!search ||
          `${order.number} ${order.customerName} ${order.customerPhone} ${staffOrderMethod(order)}`
            .toLocaleLowerCase('es-PE')
            .includes(search)),
    );
  });
  readonly pageTitle = computed(() => ['inventory-categories', 'inventory-units'].includes(this.page()) ? 'Insumos' : this.activePage().label);
  readonly pageDescription = computed(() => ({
    salon: this.page() === 'settings-tables'
      ? 'Consulta el plano y edita los salones y mesas del restaurante.'
      : 'Consulta las mesas y toma pedidos por zona.',
    kitchen: 'Consulta y actualiza la preparación de todos los pedidos.',
    history: 'Consulta los pedidos entregados y cancelados.',
    users: 'Administra las cuentas y los permisos del equipo.',
    catalog: 'Actualiza los platos, precios, fotos y disponibilidad de la carta.',
    categories: 'Edita los nombres de las categorías y los destinos de sus comandas.',
    modifiers: 'Guarda indicaciones de preparación para usarlas al tomar pedidos.',
    reports: '',
    analytics: '',
    cash: '',
    inventory: '',
    settings: '',
  })[this.view()]);
  readonly expandedGroups = signal<string[]>([]);
  readonly visibleNav = computed(() => OPERATIONS_NAV.map((group) => ({
    ...group,
    entries: group.pages.filter((entry) => this.canOpenPage(entry.id) && entry.id !== 'inventory-categories' && entry.id !== 'inventory-units'),
  })).filter((group) => group.entries.length));
  private displayedPage?: OperationsPageId;
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
    this.removalConfirmation.finish(false);
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
      this.displayedPage = undefined;
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
    if (this.refreshing || !this.api.user() || !this.canViewOrders()) return;
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
          current.updatedAt !== selected.updatedAt ||
          current.paidAt !== selected.paidAt ||
          current.revision !== selected.revision ||
          current.tickets?.some((ticket) => !selected.tickets?.some(
            (known) => known.center === ticket.center && known.status === ticket.status,
          ))
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

  openNavigation(): void {
    const dialog = this.navigationDialog()?.nativeElement;
    if (!dialog) return;
    const group = this.activeGroup();
    this.expandedGroups.update((groups) => groups.includes(group) ? groups : [...groups, group]);
    dialog.showModal();
    requestAnimationFrame(() => {
      dialog.querySelector('[aria-current="page"]')?.scrollIntoView({ block: 'nearest' });
    });
  }

  closeNavigation(): void {
    this.navigationDialog()?.nativeElement.close();
  }

  @HostListener('window:popstate')
  @HostListener('window:hashchange')
  async syncViewFromUrl(): Promise<void> {
    if (!this.api.user()) return;
    const { pathname, hash } = window.location;
    const requested = resolveOperationsPage(pathname, hash)?.id;
    const role = this.api.user()?.role;
    const fallback = this.canTakeOrders() ? 'salon' : role === 'cashier' ? 'cash' : role === 'stock' ? 'inventory' : 'kitchen';
    const page = requested && this.canOpenPage(requested) ? requested : fallback;
    const url = this.viewUrl(page);
    if (pathname + hash !== url) window.history.replaceState(null, '', url);
    await this.showPage(page);
  }

  navigate(event: MouseEvent, page: OperationsPageId): void {
    if (event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
    event.preventDefault();
    this.closeNavigation();
    void this.selectView(page);
  }

  isNavPageActive(id: OperationsPageId): boolean {
    return this.page() === id || id === 'inventory' && ['inventory-categories', 'inventory-units'].includes(this.page());
  }

  async selectView(page: OperationsPageId): Promise<void> {
    if (!this.api.user() || !this.canOpenPage(page)) return;
    const url = this.viewUrl(page);
    if (window.location.pathname + window.location.hash !== url) window.history.pushState(null, '', url);
    await this.showPage(page);
  }

  private canOpenPage(page: OperationsPageId): boolean {
    if (page === 'cash-templates') return this.isAdmin();
    if (page === 'settings-tables') return this.isAdmin();
    return this.canOpenView(OPERATIONS_PAGES.find((entry) => entry.id === page)!.view);
  }

  canOpenView(view: OperationsView): boolean {
    if (this.isAdmin()) return true;
    const role = this.api.user()?.role;
    if (role === 'waiter') return view === 'salon' || view === 'kitchen' || view === 'history';
    if (role === 'kitchen') return view === 'history' || view === 'kitchen';
    return role === 'cashier' && view === 'cash' || role === 'stock' && view === 'inventory';
  }

  private async showPage(page: OperationsPageId): Promise<void> {
    if (this.displayedPage === page) return;
    this.displayedPage = page;
    this.removalConfirmation.finish(false);
    this.editor.set(null);
    this.page.set(page);
    const group = this.activeGroup();
    this.expandedGroups.update((groups) => groups.includes(group) ? groups : [...groups, group]);
    requestAnimationFrame(() => window.scrollTo({ top: 0, behavior: 'instant' }));
    this.error.set('');
    this.selected.set(null);
    this.cancellationDialog()?.nativeElement.close();
    if (page === 'history') await this.loadHistory();
    if (page === 'users') await this.loadUsers();
  }

  selectStatus(status: 'all' | OrderStatus): void {
    this.statusFilter.set(status);

  }

  toggleNavGroup(label: string): void {
    this.expandedGroups.update((groups) => groups.includes(label)
      ? groups.filter((group) => group !== label)
      : [...groups, label]);
  }

  statusLabel(status: OrderStatus): string {
    return this.statuses.find((option) => option.id === status)?.label ?? status;
  }
  openEditor(order: StaffOrder | 'new', table: string | null = null, tableId: string | null = null): void {
    if (!this.canTakeOrders()) return;
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
  async advance(order: StaffOrder): Promise<void> {
    const next = this.statuses.find((status) => status.id === order.status)?.next;
    if (next && (next === 'delivered' || this.canPrepare())) await this.updateStatus(order, next);
  }

  async advanceTicket({ order, ticket }: { order: StaffOrder; ticket: ProductionTicket }): Promise<void> {
    if (!this.canPrepare() || this.changing().has(order.id)) return;
    this.changing.update((ids) => new Set([...ids, order.id]));
    this.error.set('');
    try {
      await this.api.advanceTicket(order, ticket.center, ticket.status);
      this.feedRevision++;
      const state = ticket.status === 'pending' ? 'Preparando' : 'Listo';
      this.showNotice(`Pedido #${order.number} · ${ticket.name} · ${state}.`);
      void this.playSound('status');
    } catch (error) {
      this.error.set(this.message(error));
    } finally {
      await this.refresh();
      if (this.selected()?.id === order.id) await this.openDetails(order);
      this.changing.update((ids) => new Set([...ids].filter((id) => id !== order.id)));
    }
  }

  requestCancellation(order: StaffOrder): void {
    const dialog = this.cancellationDialog()?.nativeElement;
    if (!dialog || dialog.open || !isActiveOrderStatus(order.status) || this.changing().has(order.id)) return;
    this.cancellationReason = '';
    this.cancellationReviewed.set(false);
    this.cancellationError.set('');
    this.cancellation.set(order);
    dialog.showModal();
  }

  async confirmCancellation(): Promise<void> {
    const order = this.cancellation();
    const reason = this.cancellationReason.trim();
    if (!order || !reason) return;
    if (!this.cancellationReviewed()) {
      this.cancellationReviewed.set(true);
      return;
    }
    await this.updateStatus(order, 'cancelled', reason);
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
    if (user.active && !(await this.removalConfirmation.ask('Desactivar acceso', 'Se desactivará el acceso de «' + user.name + '».', 'Desactivar acceso'))) return;
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

  private message(error: unknown): string {
    return error instanceof Error ? error.message : 'No se pudo completar la operación.';
  }
}
