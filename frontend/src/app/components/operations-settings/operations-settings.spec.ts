import { TestBed } from '@angular/core/testing';
import { LocalOperationsApi } from '../../services/local-operations-api.service';
import { OperationsSettingsPanel } from './operations-settings';

describe('Settings editing dialogs', () => {
  it('opens the existing payment method in a modal and preserves its code when saving', async () => {
    const method = { code: 'cash', name: 'Efectivo', cash: true, active: true };
    const workflow = jasmine.createSpy('workflow').and.resolveTo({ centers: [], routes: [], categories: [], methods: [method] });
    TestBed.configureTestingModule({ providers: [{ provide: LocalOperationsApi, useValue: { workflow } }] });
    const fixture = TestBed.createComponent(OperationsSettingsPanel);
    fixture.componentRef.setInput('page', 'settings-methods');
    fixture.detectChanges();
    await fixture.whenStable();
    fixture.detectChanges();
    const root: HTMLElement = fixture.nativeElement;
    expect(root.querySelector('form')).toBeNull();
    root.querySelector<HTMLButtonElement>('[aria-label="Editar medio de pago Efectivo"]')!.click();
    fixture.detectChanges();
    await fixture.whenStable();
    expect(root.querySelector<HTMLInputElement>('dialog input[name="code"]')?.readOnly).toBeTrue();
    expect(root.querySelector<HTMLInputElement>('dialog input[name="name"]')?.value).toBe('Efectivo');
    fixture.componentInstance.method.name = 'Efectivo en caja';
    await fixture.componentInstance.save('methods');
    fixture.detectChanges();
    expect(workflow).toHaveBeenCalledWith('/operations-settings/methods', { ...method, name: 'Efectivo en caja' });
    expect(root.querySelector('dialog')).toBeNull();
    fixture.destroy();
  });
});
