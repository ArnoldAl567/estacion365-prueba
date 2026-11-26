import { createTransientNotice } from '../../services/transient-notice';
import { Component, computed, inject, input, OnInit, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { OperationsPageId } from '../../config/operations-navigation';
import { OperationsSettings, PaymentMethod } from '../../models/workflow.models';
import { LocalOperationsApi } from '../../services/local-operations-api.service';
import { RecordDialog } from '../record-dialog/record-dialog';
import { RemovalConfirmation } from '../../services/removal-confirmation.service';

@Component({
  selector: 'app-operations-settings',
  imports: [FormsModule, RecordDialog],
  templateUrl: './operations-settings.html',
})
export class OperationsSettingsPanel implements OnInit {
  private readonly api = inject(LocalOperationsApi);
  private readonly confirmation = inject(RemovalConfirmation);
  readonly data = signal<OperationsSettings | null>(null);
  readonly busy = signal(false);
  readonly error = signal('');
  private readonly successNotice = createTransientNotice();
  readonly notice = this.successNotice.value;
  readonly editor = signal<'methods' | 'printers' | null>(null);
  readonly editorTitle = computed(() => {
    switch (this.editor()) {
      case 'methods': return this.editingMethod ? 'Editar medio de pago' : 'Nuevo medio de pago';
      case 'printers': return 'Configurar impresora';
      default: return '';
    }
  });
  readonly page = input<OperationsPageId>('settings');
  method: PaymentMethod = this.newMethod();
  editingMethod = false;
  printer = { code: '', printer: '' };

  ngOnInit(): void { void this.save(); }

  async removeRecord(kind: string, key: string, name: string): Promise<void> {
    if (this.busy()) return;
    const message = kind === 'routes' ? 'Se eliminará la asignación de «' + name + '». Los nuevos pedidos de esa categoría irán a Cocina.'
      : kind === 'printers' ? 'Se quitará la impresora configurada en «' + name + '». El centro se conservará.'
      : 'Se eliminará «' + name + '» si no tiene registros relacionados. Esta acción no se puede deshacer.';
    if (!(await this.confirmation.ask('Eliminar configuración', message))) return;
    this.busy.set(true); this.error.set('');
    try {
      this.data.set(await this.api.workflow<OperationsSettings>('/operations-settings/remove/' + kind + '/' + encodeURIComponent(key), {}));
      this.successNotice.show('Configuración eliminada.');
    } catch (error) { this.error.set(error instanceof Error ? error.message : 'No se pudo eliminar la configuración.'); }
    finally { this.busy.set(false); }
  }

  newMethod(): PaymentMethod { return { code: '', name: '', cash: false, active: true }; }

  async save(tab?: 'methods' | 'printers'): Promise<void> {
    if (this.busy()) return;
    this.busy.set(true);
    this.error.set('');
    this.successNotice.show('');
    try {
      const models = { methods: this.method, printers: this.printer };
      const data = await this.api.workflow<OperationsSettings>(tab ? '/operations-settings/' + tab : '/operations-settings', tab ? models[tab] : undefined);
      this.data.set(data);
      if (tab) {
        this.successNotice.show('Configuración guardada.');
        this.editor.set(null);
      }
    } catch (error) {
      this.error.set(error instanceof Error ? error.message : 'No se pudo cargar la configuración.');
    } finally {
      this.busy.set(false);
    }
  }

  selectPrinter(code: string): void {
    this.printer = { code, printer: this.data()?.centers.find((entry) => entry.code === code)?.printer ?? '' };
  }

  openEditor(editor: 'methods' | 'printers'): void {
    if (this.busy()) return;
    this.error.set('');
    this.editor.set(editor);
  }
}
