function requirePrivacyAuthorization() {
  if (typeof wx.requirePrivacyAuthorize !== 'function') {
    return Promise.resolve(true);
  }

  const authorize = () => new Promise((resolve) => {
    wx.requirePrivacyAuthorize({
      success: () => resolve(true),
      fail: () => resolve(false),
    });
  });

  if (typeof wx.getPrivacySetting !== 'function') {
    return authorize();
  }

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
