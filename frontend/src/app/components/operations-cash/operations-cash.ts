import { CashPaymentHistory } from './cash-payment-history';
import { createTransientNotice } from '../../services/transient-notice';
import { Component, computed, inject, input, OnInit, output, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { ORDER_STATUSES, StaffOrder, staffOrderMethod } from '../../models/operations.models';
import { OperationsPageId } from '../../config/operations-navigation';
import { CashView } from '../../models/workflow.models';
import { LocalOperationsApi } from '../../services/local-operations-api.service';
import { RecordDialog } from '../record-dialog/record-dialog';
import { OperationTimestampPipe } from '../../pipes/operation-timestamp.pipe';
import { RemovalConfirmation } from '../../services/removal-confirmation.service';

@Component({
  selector: 'app-operations-cash',
  imports: [FormsModule, RecordDialog, OperationTimestampPipe, CashPaymentHistory],
  templateUrl: './operations-cash.html',
})
export class OperationsCash implements OnInit {
  readonly changed = output<void>();
  readonly data = signal<CashView | null>(null);
  readonly busy = signal(false);
  readonly error = signal('');
  private readonly successNotice = createTransientNotice();
  readonly notice = this.successNotice.value;
  readonly editor = signal<'open' | 'close' | 'payment' | 'table' | 'template' | null>(null);
  readonly selectedOrder = signal<StaffOrder | null>(null);
  readonly editorTitle = computed(() => {
    switch (this.editor()) {
      case 'open': return 'Abrir turno de caja';
      case 'template': return this.definition.id ? 'Editar turno configurado' : 'Configurar turno';
      case 'close': return 'Arqueo y cierre de caja';
      case 'payment': return 'Cobrar pedido #' + this.selectedOrder()?.number;
      case 'table': return 'Cerrar mesa';
      default: return '';
    }
  });
  readonly page = input<OperationsPageId>('cash');
  readonly closedShift = output<void>();
  readonly view = computed(() => {
    switch (this.page()) {
      case 'cash-orders': return 'orders';
      case 'cash-payments': return 'payments';
      case 'cash-templates': return 'templates';
      case 'cash-history': return 'history';
      default: return 'shift';
    }
  });
  readonly staffOrderMethod = staffOrderMethod;
  readonly statusLabel = (status: string) => ORDER_STATUSES.find((item) => item.id === status)?.label ?? status;
  readonly money = (cents: number) => 'S/ ' + (cents / 100).toFixed(2);
  shiftName = 'Turno de caja';
  templateId: number | null = null;
  definition = {id:undefined as number|undefined,name:'',active:true};
  private readonly confirmation = inject(RemovalConfirmation);
  opening: number | null = 0;
  counted: number | null = null;
  confirmingClose = false;
  methods: Record<number, string> = {};
  private readonly api = inject(LocalOperationsApi);

  ngOnInit(): void { void this.run(); }

  openEditor(editor: 'open' | 'close' | 'payment' | 'table' | 'template', order: StaffOrder | null = null): void {
    if (this.busy()) return;
    this.selectedOrder.set(order);
    this.confirmingClose = false;
    this.error.set('');
    if (editor === 'open') this.templateId = null;
    this.editor.set(editor);
  }

  async run(path?: string, body?: object, success?: string, page = this.data()?.ordersPage ?? 1): Promise<boolean> {
    if (this.busy()) return false;
    this.busy.set(true);
    this.error.set('');
    this.successNotice.show('');
    let saved = false;
    try {
      if (path) {
        await this.api.workflow(path, body);
        saved = true;
        this.successNotice.show(success ?? 'Operación registrada.');
        this.changed.emit();
        this.editor.set(null);
      }
      this.data.set(await this.api.workflow<CashView>('/cash?ordersPage=' + page));
      return true;
    } catch (error) {
      this.error.set(error instanceof Error ? error.message : 'No se pudo completar la operación.');
      return saved;
    } finally {
      this.busy.set(false);
    }
  }

  async openShift(): Promise<void> {
    if (!this.templateId) return;
    await this.run('/cash/open', {template_id:this.templateId,opening_cents:this.cents(this.opening)}, 'Turno abierto.');
  }
  async saveTemplate(): Promise<void> { await this.run('/cash/templates', this.definition, 'Turno configurado.'); }
  async deleteTemplate(id:number,name:string): Promise<void> {
    if(this.busy()||!(await this.confirmation.ask('Eliminar turno configurado','Se eliminará «'+name+'» si todavía no tiene aperturas registradas. Si ya fue utilizado, puedes desactivarlo.')))return;
    await this.run('/cash/templates/'+id+'/delete',{},'Turno configurado eliminado.');
  }

  async closeShift(id: number): Promise<void> {
    if (this.counted === null || !this.confirmingClose) return;
    if (await this.run('/cash/close', { shift_id: id, counted_cents: this.cents(this.counted) }, 'Turno cerrado.')) {
      this.counted = null;
      this.confirmingClose = false;
      this.closedShift.emit();
    }
  }

  cents(amount: number | null): number { return Math.round((amount ?? 0) * 100); }
}
