import { TestBed } from '@angular/core/testing';
import { StaffOrder } from '../../models/operations.models';
import { OperationsKitchen } from './operations-kitchen';

describe('Kitchen elapsed time', () => {
  const createdAt = '2026-11-07T18:00:00.000-05:00';
  const order = { createdAt } as StaffOrder;

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
