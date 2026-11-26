import { OperationTimestampPipe } from './operation-timestamp.pipe';

describe('Operational timestamps in Lima', () => {
  const pipe = new OperationTimestampPipe();

  it('converts UTC database timestamps across the local date boundary', () => {
    expect(pipe.transform('2026-11-19 02:30:00')).toBe('18/11/2026 21:30');
  });

  it('respects explicit offsets without applying the conversion twice', () => {
    expect(pipe.transform('2026-11-19T02:30:00.000000Z')).toBe('18/11/2026 21:30');
    expect(pipe.transform('2026-11-18T21:30:00-05:00')).toBe('18/11/2026 21:30');
  });

  it('shows a placeholder for missing or invalid dates without breaking the list', () => {
    for (const value of [null, undefined, '', 'fecha no disponible']) {
      expect(pipe.transform(value)).toBe('—');
    }
  });
});
