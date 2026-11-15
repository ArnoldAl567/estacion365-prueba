import { Component, input, output } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { CLOUD_STAGING } from '../../config/local-operations.config';

export interface OperationsCredentials {
  name: string;
  username: string;
  password: string;
  confirmation: string;
  setupCode?: string;
}

@Component({
  selector: 'app-operations-access',
  imports: [FormsModule],
  templateUrl: './operations-access.html',
  styleUrl: './operations-access.scss',
})
export class OperationsAccess {
  readonly initialized = input(true);
  readonly busy = input(false);
  readonly error = input('');
  readonly credentialsSubmitted = output<OperationsCredentials>();
  readonly retry = output<void>();
  readonly cloudStaging = CLOUD_STAGING;
  login: OperationsCredentials = { name: '', username: '', password: '', confirmation: '' };
  showPassword = false;

  submit(): void {
    this.credentialsSubmitted.emit({ ...this.login });
  }
}
