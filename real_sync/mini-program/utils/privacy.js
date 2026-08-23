function getRecordAuthorizationStatus() {
  const authorize = () => {
    if (typeof wx.requirePrivacyAuthorize !== 'function') return Promise.resolve({ authorized: true, privacy_status: 'unsupported', record_status: 'unknown', action: 'none' });
    return new Promise((resolve) => {
      wx.requirePrivacyAuthorize({
        success: () => resolve({ authorized: true, privacy_status: 'authorized', record_status: 'unknown', action: 'none' }),
        fail: () => resolve({ authorized: false, privacy_status: 'failed', record_status: 'unknown', action: 'open_privacy' }),
      });
    });
  };

  if (typeof wx.requirePrivacyAuthorize === 'function') {
    return authorize().then((privacyResult) => {
      if (!privacyResult.authorized) return privacyResult;
      return getRecordPermission(privacyResult);
    });
  }

  if (typeof wx.getPrivacySetting !== 'function') return getRecordPermission({ authorized: true, privacy_status: 'unsupported' });

  return new Promise((resolve) => {
    wx.getPrivacySetting({
      success: (setting) => {
        if (!setting.needAuthorization) {
          resolve(getRecordPermission({ authorized: true, privacy_status: 'authorized' }));
          return;
        }
        authorize().then((result) => resolve(result.authorized ? getRecordPermission(result) : result));
      },
      fail: () => authorize().then((result) => resolve(result.authorized ? getRecordPermission(result) : result)),
    });
  });
}

function getRecordPermission(privacyResult) {
  if (typeof wx.getSetting !== 'function' || typeof wx.authorize !== 'function') return privacyResult;
  return new Promise((resolve) => {
    wx.getSetting({
      success: (setting) => {
        const current = setting.authSetting && setting.authSetting['scope.record'];
        if (current === true) {
          resolve({ ...privacyResult, authorized: true, record_status: 'authorized', action: 'none' });
          return;
        }
        wx.authorize({
          scope: 'scope.record',
          success: () => resolve({ ...privacyResult, authorized: true, record_status: 'authorized', action: 'none' }),
          fail: () => resolve({ ...privacyResult, authorized: false, record_status: current === false ? 'denied' : 'failed', action: 'open_settings' }),
        });
      },
      fail: () => resolve({ ...privacyResult, authorized: false, record_status: 'failed', action: 'open_settings' }),
    });
  });
}

function requirePrivacyAuthorization() {
  return getRecordAuthorizationStatus().then((result) => result.authorized);
}

function requireRecordAuthorization() {
  return getRecordAuthorizationStatus().then((result) => result.authorized);
}

function showAuthorizationPrompt(result) {
  const privacyFailed = result && result.action === 'open_privacy';
  wx.showModal({
    title: '录音权限说明',
    content: privacyFailed ? '需要先同意小程序隐私保护指引，才能使用语音回答。' : '需要开启系统录音权限，才能使用语音回答。你可以继续使用文字回答。',
    confirmText: privacyFailed ? '查看指引' : '打开设置',
    cancelText: '使用文字',
    success: (res) => {
      if (!res.confirm) return;
      if (privacyFailed && typeof wx.openPrivacyContract === 'function') {
        wx.openPrivacyContract({});
      } else if (typeof wx.openSetting === 'function') {
        wx.openSetting({});
      }
    }
  });
}

module.exports = {
  getRecordAuthorizationStatus,
  requirePrivacyAuthorization,
  requireRecordAuthorization,
  showAuthorizationPrompt,
};
