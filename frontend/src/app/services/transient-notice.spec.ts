import { Component } from '@angular/core';
import { fakeAsync, TestBed, tick } from '@angular/core/testing';
import { createTransientNotice } from './transient-notice';

@Component({ template: '' })
class NoticeHost {
  autoDismiss = true;
  readonly notice = createTransientNotice(() => this.autoDismiss);
}

describe('Transient success notices', () => {
  it('keeps the message for four seconds and then dismisses it', fakeAsync(() => {
    const fixture = TestBed.createComponent(NoticeHost);
    const notice = fixture.componentInstance.notice;
    notice.show('Operación registrada.');
    tick(3999);
    expect(notice.value()).toBe('Operación registrada.');
    tick(1);
    expect(notice.value()).toBe('');
    fixture.destroy();
  }));

  it('restarts the time when another operation produces the same message', fakeAsync(() => {
    const fixture = TestBed.createComponent(NoticeHost);
    const notice = fixture.componentInstance.notice;
    notice.show('Operación registrada.');
    tick(3000);
    notice.show('Operación registrada.');
    tick(3999);
    expect(notice.value()).toBe('Operación registrada.');
    tick(1);
    expect(notice.value()).toBe('');
    fixture.destroy();
  }));

  it('cancels pending dismissal and ignores late completions after leaving the module', fakeAsync(() => {
    const fixture = TestBed.createComponent(NoticeHost);
    const notice = fixture.componentInstance.notice;
    notice.show('Guardado.');
    fixture.destroy();
    notice.show('Respuesta tardía.');
    tick(5000);
    expect(notice.value()).toBe('Guardado.');
  }));

  it('preserves persistent messages when automatic dismissal is disabled', fakeAsync(() => {
    const fixture = TestBed.createComponent(NoticeHost);
    fixture.componentInstance.autoDismiss = false;
    const notice = fixture.componentInstance.notice;
    notice.show('Revisa tu correo para recuperar el acceso.');
    tick(5000);
    expect(notice.value()).toContain('Revisa tu correo');
    notice.show('');
    expect(notice.value()).toBe('');
    fixture.destroy();
  }));
});
