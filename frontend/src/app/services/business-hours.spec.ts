import { isBusinessOpen } from './business-hours';

describe('business hours in Peru', () => {
  it('accepts orders before 9:00 and after 23:00 on weekdays', () => {
    expect(isBusinessOpen(new Date('2026-10-26T13:59:00Z'))).toBeTrue();  // lunes 8:59
    expect(isBusinessOpen(new Date('2026-10-26T14:00:00Z'))).toBeTrue();  // lunes 9:00
    expect(isBusinessOpen(new Date('2026-10-27T03:59:00Z'))).toBeTrue();  // lunes 22:59
    expect(isBusinessOpen(new Date('2026-10-27T04:00:00Z'))).toBeTrue();  // lunes 23:00
    expect(isBusinessOpen(new Date('2026-10-31T14:00:00Z'))).toBeTrue();  // sábado 9:00
  });

  it('accepts orders all day on Sundays', () => {
    expect(isBusinessOpen(new Date('2026-11-01T05:00:00Z'))).toBeTrue();  // domingo 00:00
    expect(isBusinessOpen(new Date('2026-11-01T14:00:00Z'))).toBeTrue();  // domingo 9:00
    expect(isBusinessOpen(new Date('2026-11-02T03:59:00Z'))).toBeTrue();  // domingo 22:59
    expect(isBusinessOpen(new Date('2026-11-02T04:00:00Z'))).toBeTrue();  // domingo 23:00
  });
});
