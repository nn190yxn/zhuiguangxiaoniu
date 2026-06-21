const api = require('../../../utils/api');

const SCENES = [
  { key: 'new_sale', name: '新签复盘', icon: '🤝' },
  { key: 'renewal', name: '续费复盘', icon: '🔄' },
  { key: 'assessment', name: '体测解读复盘', icon: '📊' },
];

Page({
  data: {
    scenes: SCENES,
    activeScene: 0,
    filePath: '',
    fileName: '',
    isRecording: false,
    recordTime: 0,
    submitting: false,
  },

  recorderManager: null,
  recordTimer: null,
  recorderCallbacksBound: false,

  onShow() {
    this.setData({
      activeScene: 0,
      filePath: '',
      fileName: '',
      isRecording: false,
      recordTime: 0,
    });
    this._stopRecord();
    this._initRecorder();
  },

  onUnload() {
    this._stopRecord();
    this._clearRecordTimer();
  },

  _initRecorder() {
    if (this.recorderCallbacksBound) return;
    const rm = wx.getRecorderManager();
    this.recorderManager = rm;

    rm.onStart(() => {
      this.setData({ isRecording: true, recordTime: 0 });
      this.recordTimer = setInterval(() => {
        this.setData({ recordTime: this.data.recordTime + 1 });
      }, 1000);
    });

    rm.onStop((res) => {
      this._clearRecordTimer();
      this.setData({
        isRecording: false,
        filePath: res.tempFilePath,
        fileName: `录音_${this._formatTime(this.data.recordTime)}.m4a`,
      });
    });

    rm.onError((err) => {
      console.error('录音错误:', err);
      this._clearRecordTimer();
      this.setData({ isRecording: false });
      wx.showToast({ title: '录音失败', icon: 'none' });
    });

    this.recorderCallbacksBound = true;
  },

  _stopRecord() {
    if (this.recorderManager) {
      try { this.recorderManager.stop(); } catch (e) {}
    }
  },

  _clearRecordTimer() {
    if (this.recordTimer) {
      clearInterval(this.recordTimer);
      this.recordTimer = null;
    }
  },

  _formatTime(seconds) {
    const m = Math.floor(seconds / 60);
    const s = seconds % 60;
    return `${m < 10 ? '0' : ''}${m}:${s < 10 ? '0' : ''}${s}`;
  },

  switchScene(e) {
    this.setData({ activeScene: Number(e.currentTarget.dataset.index) });
  },

  chooseFile() {
    wx.chooseMedia({
      count: 1,
      mediaType: ['audio'],
      sourceType: ['album'],
      success: (res) => {
        const file = res.tempFiles[0];
        const tempPath = file.tempFilePath || '';
        const name = file.name || 'audio';
        const ext = name.split('.').pop().toLowerCase();
        const allowedAudioExts = ['mp3', 'wav', 'm4a', 'aac', 'ogg', 'webm'];
        if (!allowedAudioExts.includes(ext)) {
          wx.showToast({ title: '请选择音频文件', icon: 'none' });
          return;
        }
        this.setData({
          filePath: tempPath,
          fileName: name,
          isRecording: false,
          recordTime: 0,
        });
        this._stopRecord();
        this._clearRecordTimer();
      },
    });
  },

  toggleRecord() {
    if (this.data.isRecording) {
      this._stopRecord();
    } else {
      this._startRecord();
    }
  },

  _startRecord() {
    wx.authorize({ scope: 'scope.record', fail: () => {} });
    if (!this.recorderManager) this._initRecorder();

    this.recorderManager.start({
      duration: 600000,
      sampleRate: 16000,
      numberOfChannels: 1,
      encodeBitRate: 48000,
      format: 'aac',
    });
  },

  submitReview() {
    if (!this.data.filePath) {
      wx.showToast({ title: '请先录制或选择录音', icon: 'none' });
      return;
    }

    this.setData({ submitting: true });
    wx.showLoading({ title: '上传中...' });

    const scene = SCENES[this.data.activeScene];
    const apiBase = getApp().globalData.apiBase;
    const uploadUrl = `${apiBase}/skill/upload-recording.php`;
    const token = wx.getStorageSync('token') || wx.getStorageSync('jwt_token') || '';

    const uploadTask = wx.uploadFile({
      url: uploadUrl,
      filePath: this.data.filePath,
      name: 'recording',
      formData: { scene_type: scene.key },
      header: { Authorization: `Bearer ${token}` },
      success: (res) => {
        try {
          const data = JSON.parse(res.data);
          if (data.code === 0) {
            wx.hideLoading();
            this.setData({ submitting: false });
            wx.navigateTo({
              url: `/pages/skill-review/result?id=${data.data.record_id}&scene=${scene.name}`,
            });
          } else {
            wx.hideLoading();
            this.setData({ submitting: false });
            wx.showToast({ title: data.message || '上传失败', icon: 'none' });
          }
        } catch (e) {
          wx.hideLoading();
          this.setData({ submitting: false });
          wx.showToast({ title: '上传失败', icon: 'none' });
        }
      },
      fail: () => {
        wx.hideLoading();
        this.setData({ submitting: false });
        wx.showToast({ title: '网络错误', icon: 'none' });
      },
    });

    let lastProgress = 0;
    uploadTask.onProgressUpdate((res) => {
      if (res.progress - lastProgress >= 10 || res.progress === 100) {
        wx.showLoading({ title: `上传 ${res.progress}%` });
        lastProgress = res.progress;
      }
    });
  },
});
