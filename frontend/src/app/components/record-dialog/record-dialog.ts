import { AfterViewInit, Component, ElementRef, input, OnDestroy, output, viewChild } from '@angular/core';

let nextDialogId = 0;

@Component({
  selector: 'app-record-dialog',
  template: `
    <dialog #dialog class="record-dialog" [attr.aria-labelledby]="titleId" (cancel)="cancel($event)">
      <header>
        <div class="dialog-title"><span class="dialog-eyebrow">LA ESTACIÓN 365 · GESTIÓN</span><h2 [id]="titleId" #heading tabindex="-1">{{ title() }}</h2></div>
        <button type="button" [disabled]="busy()" (click)="closed.emit()" [attr.aria-label]="'Cerrar ' + title()"><span>Cerrar</span><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m6 6 12 12M6 18 18 6" /></svg></button>
      </header>
      <div class="record-dialog-body" [attr.aria-busy]="busy()"><ng-content /></div>
    </dialog>
  `,
  styleUrl: './record-dialog.scss',
})
export class RecordDialog implements AfterViewInit, OnDestroy {
  readonly titleId = 'record-dialog-title-' + nextDialogId++;
  readonly title = input.required<string>();
  readonly busy = input(false);
  readonly closed = output<void>();
  private readonly dialog = viewChild.required<ElementRef<HTMLDialogElement>>('dialog');
  private readonly heading = viewChild.required<ElementRef<HTMLHeadingElement>>('heading');

  ngAfterViewInit(): void {
    this.dialog().nativeElement.showModal();
    this.focusHeading();
  }

  focusHeading(): void { this.heading().nativeElement.focus(); }

  ngOnDestroy(): void {
    this.dialog().nativeElement.close();
  }

  cancel(event: Event): void {
    event.preventDefault();
    if (!this.busy()) this.closed.emit();
  }
}
