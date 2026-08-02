const READ_STATES = Object.freeze({
  loading: 'loading',
  empty: 'empty',
  ready: 'ready',
  error: 'error',
  offline: 'offline',
  conflict: 'conflict',
});

const WRITE_STATES = Object.freeze({
  idle: 'idle',
  submitting: 'submitting',
  success: 'success',
  error: 'error',
  offline: 'offline',
  conflict: 'conflict',
});

function readState(status, message) {
  return { status, message: message || '' };
}

function writeState(status, message, recoveryAction) {
  return { status, message: message || '', recoveryAction: recoveryAction || '' };
}

function errorStatus(error) {
  if (error && (error.category === 'network' || error.category === 'timeout')) return READ_STATES.offline;
  if (error && error.category === 'conflict') return READ_STATES.conflict;
  return READ_STATES.error;
}

function fromError(error, fallbackMessage, recoveryAction) {
  return writeState(
    errorStatus(error),
    (error && error.message) || fallbackMessage || '请求失败，请重试',
    (error && error.recoveryAction) || recoveryAction || 'retry'
  );
}

module.exports = {
  READ_STATES,
  WRITE_STATES,
  readState,
  writeState,
  errorStatus,
  fromError,
};
