import { TestBed } from '@angular/core/testing';
import { StaffOrder } from '../../models/operations.models';
import { OperationsKitchen } from './operations-kitchen';

describe('Kitchen elapsed time', () => {
  const createdAt = '2026-11-07T18:00:00.000-05:00';
  const order = { createdAt } as StaffOrder;

  it('shows food and drinks in one kitchen ticket without area tabs', () => {
    const fixture = TestBed.createComponent(OperationsKitchen);
    const items = [{ name: 'Arroz con mariscos', quantity: 1 }, { name: 'Limonada', quantity: 2 }];
    fixture.componentRef.setInput('orders', [{ id: 1, number: '0001', createdAt, status: 'pending', method: 'table', tableLabel: 'M1',
      tickets: [{ center: 'kitchen', name: 'Cocina', status: 'pending', items }] }]);
    fixture.detectChanges();
    const root: HTMLElement = fixture.nativeElement;
    expect(root.textContent).toContain('Arroz con mariscos');
    expect(root.textContent).toContain('Limonada');
    expect(root.querySelectorAll('.kitchen-ticket').length).toBe(1);
    expect(root.querySelector('nav')).toBeNull();
    expect(root.textContent).not.toContain('Barra');
    fixture.destroy();
  });

  it('identifies all three service types and restricts a mesero to delivery actions', () => {
    const fixture = TestBed.createComponent(OperationsKitchen);
    fixture.componentRef.setInput('canEdit', true);
    const ticket = { center: 'kitchen', name: 'Cocina', status: 'pending', items: [] };
    fixture.componentRef.setInput('orders', [
      { id: 1, number: '0001', createdAt, status: 'pending', method: 'table', tableLabel: 'M1', tickets: [ticket] },
      { id: 2, number: '0002', createdAt, status: 'pending', method: 'delivery', tickets: [ticket] },
      { id: 3, number: '0003', createdAt, status: 'ready', method: 'pickup', tickets: [{ ...ticket, status: 'ready' }] },
    ]);
    fixture.detectChanges();
    const root: HTMLElement = fixture.nativeElement;
    expect(root.textContent).toContain('Mesa · M1'); expect(root.textContent).toContain('Delivery'); expect(root.textContent).toContain('Para recoger');
    expect(root.textContent).not.toContain('Iniciar preparación');
    expect(root.textContent).toContain('Editar pedido');
    const delivered = spyOn(fixture.componentInstance.advanced, 'emit');
    root.querySelector<HTMLButtonElement>('.ticket-action.ready')!.click();
    expect(delivered).toHaveBeenCalledWith(jasmine.objectContaining({ id: 3 }));
    fixture.componentRef.setInput('canPrepare', true); fixture.detectChanges();
    expect(root.textContent).toContain('Iniciar preparación');
    fixture.destroy();
  });

  it('rolls seconds into minutes without losing elapsed time', () => {
    const fixture = TestBed.createComponent(OperationsKitchen);
    fixture.componentRef.setInput('now', Date.parse(createdAt) + 59000);
    expect(fixture.componentInstance.elapsedTime(order)).toBe('00 min 59 s');
    fixture.componentRef.setInput('now', Date.parse(createdAt) + 60000);
    expect(fixture.componentInstance.elapsedTime(order)).toBe('01 min 00 s');
    fixture.destroy();
  });

  it('keeps counting minutes beyond one hour', () => {
    const fixture = TestBed.createComponent(OperationsKitchen);
    fixture.componentRef.setInput('now', Date.parse(createdAt) + 3601000);
    expect(fixture.componentInstance.elapsedTime(order)).toBe('60 min 01 s');
    fixture.destroy();
  });

  it('does not display negative time when the computer clock is behind', () => {
    const fixture = TestBed.createComponent(OperationsKitchen);
    fixture.componentRef.setInput('now', Date.parse(createdAt) - 1000);
    expect(fixture.componentInstance.elapsedTime(order)).toBe('00 min 00 s');
    fixture.destroy();
  });
});
