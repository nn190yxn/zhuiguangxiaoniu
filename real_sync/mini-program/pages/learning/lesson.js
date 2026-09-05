const app = getApp();
const media = require('../../utils/media');

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
        url: `/learning/lesson.php?id=${id}`
      });

      if (res.code === 0) {
        this.setData({
          lesson: media.normalizeMediaFields(res.data.lesson || {}, ['media_url']),
          navigation: res.data.navigation,
          stateVersion: res.data.progress?.state_version || 1
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
      this.completeLesson(() => wx.navigateTo({ url: `/pages/learning/lesson?id=${next.id}` }));
    } else {
      this.completeLesson(() => wx.navigateBack());
    }
  },

  async completeLesson(onSuccess) {
    try {
      const res = await app.request({
        url: `/learning/lesson.php?id=${this.data.lessonId}`,
        method: 'POST',
        idempotencyKey: `lesson-${this.data.lessonId}-${Date.now()}`,
        stateVersion: this.data.stateVersion
      });
      if (res.code === 0) {
        this.setData({ stateVersion: res.data.progress?.state_version || this.data.stateVersion + 1 });
        onSuccess();
      } else wx.showToast({ title: res.message || '完成失败', icon: 'none' });
    } catch (err) {
      console.error('完成失败:', err);
      wx.showToast({ title: '完成失败', icon: 'none' });
    }
  },

});
