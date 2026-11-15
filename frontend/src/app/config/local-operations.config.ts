export const CLOUD_STAGING = window.location.protocol === 'https:';

export const LOCAL_OPERATIONS_ENABLED = CLOUD_STAGING || ['localhost', '127.0.0.1', '[::1]'].includes(
  window.location.hostname,
);
