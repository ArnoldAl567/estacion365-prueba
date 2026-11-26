import { Component, input, output, signal } from '@angular/core';
import { NgTemplateOutlet } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { CLOUD_STAGING } from '../../config/local-operations.config';
import { RecordDialog } from '../record-dialog/record-dialog';

export interface OperationsCredentials {
  name: string;
  username: string;
  password: string;
  confirmation: string;
  setupCode?: string;
}

@Component({
  selector: 'app-operations-access',
  imports: [FormsModule, NgTemplateOutlet, RecordDialog],
  templateUrl: './operations-access.html',
  styleUrl: './operations-access.scss',
})
export class OperationsAccess {
  readonly initialized = input(true);
  readonly busy = input(false);
  readonly error = input('');
  readonly credentialsSubmitted = output<OperationsCredentials>();
  readonly retry = output<void>();
  readonly setupOpen = signal(false);
  readonly cloudStaging = CLOUD_STAGING;
  login: OperationsCredentials = { name: '', username: '', password: '', confirmation: '' };
  showPassword = false;

  submit(): void {
    this.credentialsSubmitted.emit({ ...this.login });
  }
}
