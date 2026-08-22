function request(options) {
  return new Promise((resolve, reject) => {
    wx.request(Object.assign({}, options, {
      success: resolve,
      fail: reject
    }));
  });
}

function uploadFile(options, onProgress) {
  return new Promise((resolve, reject) => {
    const task = wx.uploadFile(Object.assign({}, options, {
      success: resolve,
      fail: reject
    }));
    if (task && typeof task.onProgressUpdate === 'function' && typeof onProgress === 'function') {
      task.onProgressUpdate((progress) => onProgress(Number(progress.progress || 0)));
    }
  });
}

module.exports = { request, uploadFile };
