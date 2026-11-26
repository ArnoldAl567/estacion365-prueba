import { formatDate } from '@angular/common';
import { Pipe, PipeTransform } from '@angular/core';

@Pipe({ name: 'operationTimestamp' })
export class OperationTimestampPipe implements PipeTransform {
  transform(value: string | null | undefined): string {
    if (!value) return '—';
    const timestamp = value.trim().replace(' ', 'T');
    // PostgreSQL timestamps without an offset are stored in UTC by Laravel.
    const utc = /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(?::\d{2}(?:\.\d+)?)?$/.test(timestamp)
      ? timestamp + 'Z' : timestamp;
    const date = new Date(utc);
    if (Number.isNaN(date.getTime())) return '—';
    return formatDate(date, 'dd/MM/yyyy HH:mm', 'en-US', '-0500');
  }
}
