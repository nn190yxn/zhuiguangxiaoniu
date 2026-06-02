const app = getApp();

Page({
  data: {
    lessonId: null,
    lesson: {},
    navigation: { prev: null, next: null }
  },

  onLoad(options) {
    if (options.id) {
      this.setData({ lessonId: options.id });
      this.loadLesson(options.id);
    }
  },

  async loadLesson(id) {
    wx.showLoading({ title: '加载中...' });

    try {
      const res = await app.request({
        url: `${app.globalData.apiBase}/learning/lesson.php?id=${id}`
      });

      if (res.code === 0) {
        this.setData({
          lesson: res.data.lesson,
          navigation: res.data.navigation
        });
        wx.setNavigationBarTitle({ title: res.data.lesson.course_title });
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

  goPrev() {
    const prev = this.data.navigation.prev;
    if (prev) {
      wx.navigateTo({
        url: `/pages/learning/lesson?id=${prev.id}`
      });
    }
  },

  goNext() {
    const next = this.data.navigation.next;
    if (next) {
      wx.navigateTo({
        url: `/pages/learning/lesson?id=${next.id}`
      });
    } else {
      wx.showToast({ title: '已完成所有章节学习！', icon: 'success' });
      setTimeout(() => {
        wx.navigateBack();
      }, 1500);
    }
  }
});