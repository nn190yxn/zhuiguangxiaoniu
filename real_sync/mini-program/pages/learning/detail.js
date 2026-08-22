const app = getApp();
const media = require('../../utils/media');

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
        url: `/learning/detail.php?id=${id}`
      });

      if (res.code === 0) {
        const course = media.normalizeMediaFields(res.data.course || {}, ['cover_image', 'cover_url']);
        const coverImage = String(course.cover_image || '').trim();
        this.setData({
          course: {
            ...course,
            cover_image: coverImage,
            cover_style: coverImage && coverImage !== 'null'
              ? `background-image: url('${coverImage}')`
              : 'background: linear-gradient(135deg, #ffe3d6 0%, #ffd0bb 100%)'
          },
          lessons: (res.data.lessons || []).map(lesson => media.normalizeMediaFields(lesson, ['media_url'])),
          exam: res.data.exam
        });
      } else {
        wx.showToast({ title: res.message, icon: 'none' });
      }
    } catch (err) {
      console.error('课程详情加载失败:', err && err.url ? err.url : '', err);
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
