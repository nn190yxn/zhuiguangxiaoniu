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

module.exports = {
  requirePrivacyAuthorization,
};
