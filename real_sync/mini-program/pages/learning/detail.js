const app = getApp();

Page({
  data: {
    courseId: null,
    course: {},
    lessons: [],
    exam: null
  },

  onLoad(options) {
    if (options.id) {
      this.setData({ courseId: options.id });
      this.loadCourseDetail(options.id);
    }
  },

  async loadCourseDetail(id) {
    wx.showLoading({ title: '加载中...' });

    try {
      const res = await app.request({
        url: `${app.globalData.apiBase}/learning/detail.php?id=${id}`
      });

      if (res.code === 0) {
        this.setData({
          course: res.data.course,
          lessons: res.data.lessons || [],
          exam: res.data.exam
        });
      } else {
        wx.showToast({ title: res.message, icon: 'none' });
      }
    } catch (err) {
      console.error('加载失败:', err);
      wx.showToast({ title: '加载失败', icon: 'none' });
    } finally {
      wx.hideLoading();
    }
  },

  goToLesson(e) {
    const lessonId = e.currentTarget.dataset.id;
    wx.navigateTo({
      url: `/pages/learning/lesson?id=${lessonId}`
    });
  },

  goToExam(e) {
    const examId = e.currentTarget.dataset.id;
    wx.navigateTo({
      url: `/pages/exam/exam?id=${examId}`
    });
  }
});