const app = getApp();

Page({
  data: {
    selectedScene: "",
    isRecording: false,
    hasRecording: false,
    recordDuration: 0,
    recordDurationText: "00:00",
    tempFilePath: "",
    uploading: false,
    uploadProgress: 0,
    errorMsg: ""
  },

  recorderManager: null,
  timer: null,

  onLoad() {
    this.recorderManager = wx.getRecorderManager();

    this.recorderManager.onStart(() => {
      this.setData({ isRecording: true, errorMsg: "" });
      this.startTimer();
    });

    this.recorderManager.onStop((res) => {
      this.stopTimer();
      this.setData({
        isRecording: false,
        hasRecording: true,
        tempFilePath: res.tempFilePath
      });
    });

    this.recorderManager.onError((err) => {
      this.stopTimer();
      this.setData({
        isRecording: false,
        errorMsg: "录音失败：" + (err.errMsg || "未知错误")
      });
    });
  },

  onUnload() {
    if (this.timer) {
      clearInterval(this.timer);
    }
    if (this.data.isRecording) {
      this.recorderManager.stop();
    }
  },

  selectScene(e) {
    const scene = e.currentTarget.dataset.scene;
    this.setData({ selectedScene: scene, errorMsg: "" });
  },

  toggleRecord() {
    if (this.data.isRecording) {
      this.recorderManager.stop();
    } else {
      this.setData({ hasRecording: false, recordDuration: 0, recordDurationText: "00:00" });
      this.recorderManager.start({
        format: "mp3",
        duration: 600000
      });
    }
  },

  startTimer() {
    this.timer = setInterval(() => {
      const duration = this.data.recordDuration + 1;
      const minutes = Math.floor(duration / 60);
      const seconds = duration % 60;
      this.setData({
        recordDuration: duration,
        recordDurationText: `${String(minutes).padStart(2, "0")}:${String(seconds).padStart(2, "0")}`
      });
    }, 1000);
  },

  stopTimer() {
    if (this.timer) {
      clearInterval(this.timer);
      this.timer = null;
    }
  },

  uploadRecording() {
    if (!this.data.selectedScene) {
      this.setData({ errorMsg: "请先选择复盘场景" });
      return;
    }

    if (!this.data.tempFilePath) {
      this.setData({ errorMsg: "请先录音" });
      return;
    }

    this.setData({ uploading: true, uploadProgress: 0, errorMsg: "" });

    const uploadTask = wx.uploadFile({
      url: `${app.globalData.apiBase}/skill/upload-recording.php`,
      filePath: this.data.tempFilePath,
      name: "recording",
      formData: {
        scene_type: this.data.selectedScene
      },
      header: {
        Authorization: `Bearer ${app.globalData.token}`
      },
      success: (res) => {
        let data = null;
        try {
          data = typeof res.data === 'string' ? JSON.parse(res.data) : res.data;
        } catch (err) {
          this.setData({ uploading: false, errorMsg: '上传返回异常，请稍后重试' });
          return;
        }
        if (data.code === 0) {
          wx.navigateTo({
            url: `/pages/skill/result/result?record_id=${data.data.record_id}`
          });
        } else {
          this.setData({
            uploading: false,
            errorMsg: data.message || "上传失败"
          });
        }
      },
      fail: (err) => {
        this.setData({
          uploading: false,
          errorMsg: "网络错误，请重试"
        });
      },
      complete: () => {
        if (this.data.uploading) {
          this.setData({ uploading: false });
        }
      }
    });

    uploadTask.onProgressUpdate((res) => {
      this.setData({ uploadProgress: res.progress });
    });
  },

  goToHistory() {
    wx.navigateTo({ url: "/pages/skill/history/history" });
  }
});
