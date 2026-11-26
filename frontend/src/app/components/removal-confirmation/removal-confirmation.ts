import { Component, inject, OnDestroy, signal, viewChild } from '@angular/core';
import { RemovalConfirmation } from '../../services/removal-confirmation.service';
import { RecordDialog } from '../record-dialog/record-dialog';

@Component({
  selector: 'app-removal-confirmation',
  imports: [RecordDialog],
  template: `
    @if (confirmation.pending(); as request) {
      <app-record-dialog [title]="step() === 1 ? request.title : 'Confirmación final'" (closed)="confirmation.finish(false)">
        <div class="removal-content">
          <p>{{ request.message }}</p>
          @if (step() === 2) { <p class="removal-warning">Confirma por segunda vez para completar esta acción.</p> }
          <div class="removal-actions">
            <button type="button" (click)="confirmation.finish(false)">Cancelar</button>
            @if (step() === 1) {
              <button type="button" (click)="review()">Continuar</button>
            } @else {
              <button type="button" class="removal-final" (click)="confirmation.finish(true)">{{ request.action }}</button>
            }
          </div>
        </div>
      </app-record-dialog>
    }
  `,
  styles: `
    .removal-content { display: grid; gap: 20px; }
    p { margin: 0; line-height: 1.6; overflow-wrap: anywhere; }
    .removal-warning { padding: 12px; border-radius: 9px; background: #fff1ee; color: #a33122; }
    .removal-actions { display: flex; justify-content: flex-end; flex-wrap: wrap; gap: 12px; }
    button { min-height: 44px; padding: 10px 18px; border: 1px solid #a8c1cb; border-radius: 9px; background: white; color: #143240; font: inherit; font-weight: 650; cursor: pointer; }
    .removal-final { color: white; background: #ad3227; border-color: #ad3227; }
    button:focus-visible { outline: 3px solid #318fbd; outline-offset: 3px; }
  `,
})
export class RemovalConfirmationDialog implements OnDestroy {
  readonly confirmation = inject(RemovalConfirmation);
  readonly step = signal(1);
  private readonly dialog = viewChild(RecordDialog);
  review(): void {
    this.step.set(2);
    requestAnimationFrame(() => this.dialog()?.focusHeading());
  }
  ngOnDestroy(): void { this.confirmation.finish(false); }
}
