import { TestBed } from '@angular/core/testing';
import { LocalOperationsApi } from '../../services/local-operations-api.service';
import { OperationsCash } from './operations-cash';
import { OPERATIONS_PAGES, resolveOperationsPage } from '../../config/operations-navigation';

describe('Cash registration dialogs', () => {
  it('configures shifts while a cash shift is already open without opening another one', async () => {
    const data={shift:{id:1,name:'Turno Mañana'},templates:[{id:1,name:'Turno Mañana',active:true}],orders:[],methods:[],history:[],expenses:[],ordersPage:1};
    const workflow=jasmine.createSpy('workflow').and.resolveTo(data);
    TestBed.configureTestingModule({providers:[{provide:LocalOperationsApi,useValue:{workflow}}]});
    const fixture=TestBed.createComponent(OperationsCash);fixture.componentRef.setInput('page','cash-templates');
    fixture.detectChanges();await fixture.whenStable();fixture.detectChanges();
    expect(fixture.nativeElement.textContent).toContain('Turnos configurados');
    fixture.componentInstance.definition={id:undefined,name:'Turno Tarde',active:true};fixture.componentInstance.openEditor('template');fixture.detectChanges();
    expect(fixture.nativeElement.querySelector('dialog[open] form')).not.toBeNull();
    await fixture.componentInstance.saveTemplate();fixture.detectChanges();
    expect(workflow).toHaveBeenCalledWith('/cash/templates',jasmine.objectContaining({name:'Turno Tarde',active:true}));
    expect(workflow.calls.allArgs().some(a=>a[0]==='/cash/open')).toBeFalse();
    fixture.destroy();
  });

  it('opens a configured shift from the selector', async () => {
    const data={shift:null,templates:[{id:1,name:'Turno Mañana',active:true},{id:2,name:'Turno Tarde',active:true},{id:3,name:'Turno Noche',active:true}],orders:[],methods:[],history:[],expenses:[],ordersPage:1};
    const workflow=jasmine.createSpy('workflow').and.resolveTo(data);
    TestBed.configureTestingModule({providers:[{provide:LocalOperationsApi,useValue:{workflow}}]});
    const fixture=TestBed.createComponent(OperationsCash);fixture.detectChanges();await fixture.whenStable();fixture.detectChanges();
    fixture.componentInstance.openEditor('open');fixture.detectChanges();await fixture.whenStable();
    const form:HTMLFormElement=fixture.nativeElement.querySelector('dialog form');const select=form.querySelector<HTMLSelectElement>('select')!;
    expect(select.options.length).toBe(4);select.selectedIndex=2;select.dispatchEvent(new Event('change'));
    fixture.detectChanges();await fixture.whenStable();form.dispatchEvent(new Event('submit',{bubbles:true,cancelable:true}));await fixture.whenStable();
    expect(workflow).toHaveBeenCalledWith('/cash/open',{template_id:2,opening_cents:0});fixture.destroy();
  });
  it('displays shift opening and closing in Lima time', async () => {
    const shift = { id: 1, name: 'Turno de prueba', opened_at: '2026-11-18 14:00:00', closed_at: '2026-11-19T04:00:00Z', opening_cents: 10000, expected_cents: 10000, counted_cents: 10000 };
    const workflow = jasmine.createSpy('workflow').and.resolveTo({ shift, orders: [], methods: [], history: [shift], ordersPage: 1,
      expenses: [{ id: 1, description: 'Movilidad', amount_cents: 500, created_at: '2026-11-19 02:30:00' }] });
    TestBed.configureTestingModule({ providers: [{ provide: LocalOperationsApi, useValue: { workflow } }] });
    const fixture = TestBed.createComponent(OperationsCash);
    fixture.detectChanges();
    await fixture.whenStable();
    fixture.detectChanges();
    expect(fixture.nativeElement.textContent).toContain('18/11/2026 09:00');
    fixture.componentRef.setInput('page', 'cash-history');
    fixture.detectChanges();
    expect(fixture.nativeElement.textContent).toContain('18/11/2026 09:00');
    expect(fixture.nativeElement.textContent).toContain('18/11/2026 23:00');
    fixture.destroy();
  });

  it('removes expense registration and preserves reconciliation for previously recorded outflows', async () => {
    const cash = { shift: { id: 1, name: 'Turno de prueba', opened_at: '2026-11-25 14:00:00',
      opening_cents: 10000, expected_cents: 9500, expenses_cents: 500 },
      orders: [], methods: [], history: [], ordersPage: 1 };
    const workflow = jasmine.createSpy('workflow').and.resolveTo(cash);
    TestBed.configureTestingModule({ providers: [{ provide: LocalOperationsApi, useValue: { workflow } }] });
    const fixture = TestBed.createComponent(OperationsCash);
    fixture.detectChanges(); await fixture.whenStable(); fixture.detectChanges();
    const root: HTMLElement = fixture.nativeElement;
    expect(OPERATIONS_PAGES.some(page => String(page.path) === 'caja/gastos')).toBeFalse();
    expect(resolveOperationsPage('/administrar/caja/gastos', '')?.id).toBe('cash');
    expect(root.textContent).not.toContain('Registrar gasto');
    expect(root.textContent).toContain('Salidas anteriores registradas');
    expect(root.textContent).toContain('S/ 95.00');
    fixture.componentInstance.openEditor('close'); fixture.detectChanges();
    fixture.componentInstance.counted = 95;
    fixture.componentInstance.confirmingClose = true;
    await fixture.componentInstance.closeShift(1);
    expect(workflow).toHaveBeenCalledWith('/cash/close', { shift_id: 1, counted_cents: 9500 });
    expect(workflow.calls.allArgs().some(args => args[0] === '/cash/expenses')).toBeFalse();
    fixture.destroy();
  });
});
