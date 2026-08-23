function requirePrivacyAuthorization() {
  const authorize = () => {
    if (typeof wx.requirePrivacyAuthorize !== 'function') return Promise.resolve(false);
    return new Promise((resolve) => {
      wx.requirePrivacyAuthorize({
        success: () => resolve(true),
        fail: () => resolve(false),
      });
    });
  };

  if (typeof wx.requirePrivacyAuthorize === 'function') {
    return authorize();
  }

  if (typeof wx.getPrivacySetting !== 'function') return Promise.resolve(true);

  return new Promise((resolve) => {
    wx.getPrivacySetting({
      success: (setting) => {
        if (!setting.needAuthorization) {
          resolve(true);
          return;
        }
        authorize().then(resolve);
      },
      fail: () => authorize().then(resolve),
    });
  });
}

function requireRecordAuthorization() {
  return requirePrivacyAuthorization().then((privacyAuthorized) => {
    if (!privacyAuthorized || typeof wx.getSetting !== 'function' || typeof wx.authorize !== 'function') {
      return privacyAuthorized;
    }
    return new Promise((resolve) => {
      wx.getSetting({
        success: (setting) => {
          const current = setting.authSetting && setting.authSetting['scope.record'];
          if (current === true) {
            resolve(true);
            return;
          }
          wx.authorize({
            scope: 'scope.record',
            success: () => resolve(true),
            fail: () => resolve(false),
          });
        },
        fail: () => resolve(false),
      });
    });
  });
}

module.exports = {
  requirePrivacyAuthorization,
  requireRecordAuthorization,
};
