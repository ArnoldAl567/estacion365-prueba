import { DatePipe, DecimalPipe } from '@angular/common';
import { Component, inject, OnInit, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { DishDemandReport } from '../../models/operations.models';
import { LocalOperationsApi } from '../../services/local-operations-api.service';

@Component({
  selector: 'app-operations-reports',
  imports: [FormsModule, DatePipe, DecimalPipe],
  templateUrl: './operations-reports.html',
  styleUrl: './operations-reports.scss',
})
export class OperationsReports implements OnInit {
  private readonly api = inject(LocalOperationsApi);
  readonly loading = signal(false);
  readonly error = signal('');
  readonly report = signal<DishDemandReport | null>(null);
  readonly sections = [
    { key: 'carta', title: 'Platos a la carta' },
    { key: 'fondos', title: 'Fondos del menú' },
    { key: 'entradas', title: 'Entradas del menú' },
  ] as const;
  from = '';
  to = '';

  ngOnInit(): void {
    void this.load();
  }

  async load(range?: { from: string; to: string }): Promise<void> {
    if (this.loading()) return;
    this.loading.set(true);
    this.error.set('');
    try {
      const report = await this.api.dishDemand(range);
      this.report.set(report);
      this.from = report.from;
      this.to = report.to;
    } catch (error) {
      this.error.set(error instanceof Error ? error.message : 'No se pudo cargar el reporte.');
    } finally {
      this.loading.set(false);
    }
  }
}
