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
          course: this.normalizeCourse(res.data.course || {}),
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
  },

  normalizeCourse(course) {
    const coverImage = this.normalizeImageUrl(course.cover_image);
    return {
      ...course,
      category_name: this.normalizeCategoryName(course.category_name),
      cover_image: coverImage,
      coverStyle: coverImage ? `background-image: url('${coverImage}')` : ''
    };
  },

  normalizeCategoryName(name) {
    const map = {
      '技术岗位': '教练',
      '技术培训': '教练',
      '教练岗位': '教练',
      '教学岗位': '教练',
      '销售岗位': '顾问',
      '销售培训': '顾问',
      '顾问岗位': '顾问',
      '课程顾问': '顾问',
      '管理岗位': '店长',
      '管理培训': '店长',
      '店长岗位': '店长',
      '运营岗位': '店长',
      '产品岗位': '通用',
      '产品培训': '通用',
      '品牌产品': '通用',
      '新人培训': '新员工',
      '新员工培训': '新员工'
    };
    return map[name] || name || '通用';
  },

  normalizeImageUrl(url) {
    if (!url || url === 'null' || url === 'undefined') return '';
    return String(url);
  }
});
