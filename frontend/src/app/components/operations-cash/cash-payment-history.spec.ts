import { TestBed } from '@angular/core/testing';
import { LocalOperationsApi } from '../../services/local-operations-api.service';
import { CashPaymentHistory } from './cash-payment-history';
import { operationsPageUrl, resolveOperationsPage } from '../../config/operations-navigation';

describe('Cash payment history', () => {
  const payment = { id: 7, order_id: 16, amount_cents: 1300, method: 'cash', method_name: 'Efectivo',
    paid_at: '2026-11-25 15:00:00', shift_id: 3, shift_name: 'Turno Mañana', cashier_name: 'Cajero prueba',
    delivery_method: 'table', table_label: 'M1', table_closed_at: '2026-11-25 15:05:00', status: 'delivered' };
  const history = { records: { data: [payment], current_page: 1, last_page: 2, total: 31 }, total_cents: 40300,
    tables: ['M1', 'M10'], shifts: ['Turno Mañana', 'Turno Tarde'],
    methods: [{ method: 'cash', method_name: 'Efectivo' }] };

  it('shows a closed table and opens its payment and products in a read-only modal', async () => {
    const workflow = jasmine.createSpy('workflow').and.callFake((path: string) => Promise.resolve(
      path === '/cash/payments/7' ? { payment, order: { number: '0016', method: 'table', tableLabel: 'M1',
        tableClosedAt: payment.table_closed_at, customerName: '', subtotalCents: 1200, packagingCents: 100,
        items: [{ name: 'Ají de gallina', quantity: 1, unitPriceCents: 1200, subtotalCents: 1200, notes: 'Sin ají' }] } } : history));
    TestBed.configureTestingModule({ providers: [{ provide: LocalOperationsApi, useValue: { workflow } }] });
    const fixture = TestBed.createComponent(CashPaymentHistory);
    fixture.detectChanges(); await fixture.whenStable(); fixture.detectChanges();
    const root: HTMLElement = fixture.nativeElement;
    expect(root.textContent).toContain('Cerrada');
    expect(root.textContent).toContain('25/11/2026 10:00');
    expect(root.textContent).toContain('Cajero prueba');
    const tableSelect = root.querySelector<HTMLSelectElement>('select[name="table"]')!;
    const shiftSelect = root.querySelector<HTMLSelectElement>('select[name="shift"]')!;
    expect(Array.from(tableSelect.options).map(option => option.textContent)).toEqual(['Todas las mesas', 'M1', 'M10']);
    expect(Array.from(shiftSelect.options).map(option => option.textContent)).toEqual(['Todos los turnos', 'Turno Mañana', 'Turno Tarde']);
    tableSelect.value = 'M1'; tableSelect.dispatchEvent(new Event('change'));
    shiftSelect.value = 'Turno Tarde'; shiftSelect.dispatchEvent(new Event('change'));
    root.querySelector('form')!.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
    await fixture.whenStable(); fixture.detectChanges();
    const query = new URLSearchParams(workflow.calls.mostRecent().args[0].split('?')[1]);
    expect(query.get('table')).toBe('M1'); expect(query.get('shift')).toBe('Turno Tarde');

    Array.from(root.querySelectorAll('button')).find(button => button.textContent?.includes('Ver detalle'))!.click();
    fixture.detectChanges(); await fixture.whenStable(); fixture.detectChanges();
    expect(root.querySelector('dialog[open]')?.textContent).toContain('Ají de gallina');
    expect(root.querySelector('dialog[open]')?.textContent).toContain('Sin ají');
    expect(root.querySelector('dialog form')).toBeNull();
    expect(workflow.calls.allArgs().every(args => args.length === 1)).toBeTrue();
    fixture.destroy();
  });

  it('keeps applied filters across pages and clears stale results on failure', async () => {
    const workflow = jasmine.createSpy('workflow').and.resolveTo(history);
    TestBed.configureTestingModule({ providers: [{ provide: LocalOperationsApi, useValue: { workflow } }] });
    const fixture = TestBed.createComponent(CashPaymentHistory);
    fixture.detectChanges(); await fixture.whenStable();
    const component = fixture.componentInstance;
    component.filters = { from: '2026-11-01', to: '2026-11-25', table: 'M1', shift: 'Mañana', method: 'cash' };
    await component.applyFilters();
    let query = new URLSearchParams(workflow.calls.mostRecent().args[0].split('?')[1]);
    expect(query.get('method')).toBe('cash'); expect(query.get('shift')).toBe('Mañana');
    component.filters.table = 'M2';
    await component.load(2);
    query = new URLSearchParams(workflow.calls.mostRecent().args[0].split('?')[1]);
    expect(query.get('table')).toBe('M1'); expect(query.get('page')).toBe('2');
    workflow.and.rejectWith(new Error('No se pudo conectar'));
    await component.applyFilters(); fixture.detectChanges();
    expect(component.data()).toBeNull();
    expect(component.options().tables).toEqual(['M1', 'M10']);
    expect(component.options().shifts).toContain('Turno Tarde');
    expect(fixture.nativeElement.textContent).toContain('No se pudo conectar');
    expect(operationsPageUrl('cash-payments')).toBe('/administrar/caja/historial-cobros');
    expect(resolveOperationsPage('/administrar/caja/historial-cobros', '')?.id).toBe('cash-payments');
    fixture.destroy();
  });
});
