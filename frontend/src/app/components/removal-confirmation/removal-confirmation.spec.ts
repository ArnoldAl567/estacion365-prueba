import { TestBed } from '@angular/core/testing';
import { RemovalConfirmation } from '../../services/removal-confirmation.service';
import { RemovalConfirmationDialog } from './removal-confirmation';

describe('Removal confirmation', () => {
  it('requires both confirmations before allowing removal', async () => {
    const confirmation = TestBed.inject(RemovalConfirmation);
    const removed = jasmine.createSpy('removed');
    const pending = confirmation.ask('Eliminar modificador', 'Se eliminará «Sin cebolla».')
      .then((confirmed) => { if (confirmed) removed(); });
    const fixture = TestBed.createComponent(RemovalConfirmationDialog);
    fixture.detectChanges();
    const root: HTMLElement = fixture.nativeElement;
    expect(root.querySelector('dialog[open]')).not.toBeNull();
    expect(removed).not.toHaveBeenCalled();
    const button = (label: string) => Array.from(root.querySelectorAll<HTMLButtonElement>('button'))
      .find((entry) => entry.textContent?.trim() === label)!;
    button('Continuar').click();
    fixture.detectChanges();
    expect(root.textContent).toContain('Confirmación final');
    expect(removed).not.toHaveBeenCalled();
    button('Eliminar').click();
    await pending;
    expect(removed).toHaveBeenCalledTimes(1);
    fixture.destroy();
  });

  it('keeps the record when cancelled at the final step', async () => {
    const confirmation = TestBed.inject(RemovalConfirmation);
    const pending = confirmation.ask('Quitar mesa', 'Mesa M1');
    const fixture = TestBed.createComponent(RemovalConfirmationDialog);
    fixture.detectChanges();
    const root: HTMLElement = fixture.nativeElement;
    const buttons = () => Array.from(root.querySelectorAll<HTMLButtonElement>('button'));
    buttons().find((button) => button.textContent?.trim() === 'Continuar')!.click();
    fixture.detectChanges();
    buttons().find((button) => button.textContent?.trim() === 'Cancelar')!.click();
    expect(await pending).toBeFalse();
    expect(confirmation.pending()).toBeNull();
    fixture.destroy();
  });
});
