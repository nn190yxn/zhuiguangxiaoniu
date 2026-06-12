const app = getApp();

Page({
  data: {
    totalPoints: 0,
    todayChecked: false,
    categories: [],
    selectedCategoryId: 0,
    courses: [],
    commonKnowledge: [],
    loading: false,
    page: 1,
    hasMore: true,
    loginRequired: false
  },

  onLoad() {
    if (!this.ensureLogin()) return;
    this.loadCategories();
    this.loadCommonKnowledge();
    this.loadCourses();
    this.loadPoints();
  },

  onShow() {
    if (this.data.loginRequired && this.ensureLogin()) {
      this.setData({ loginRequired: false, page: 1, courses: [] });
      this.loadCategories();
      this.loadCommonKnowledge();
      this.loadCourses();
      this.loadPoints();
    }
  },

  ensureLogin() {
    if (app.isLoggedIn()) return true;
    this.setData({ loginRequired: true, loading: false });
    wx.navigateTo({ url: '/pages/login/login' });
    return false;
  },

  onPullDownRefresh() {
    if (!this.ensureLogin()) {
      wx.stopPullDownRefresh();
      return;
    }
    this.setData({ page: 1, courses: [] });
    Promise.all([
      this.loadCategories(),
      this.loadCommonKnowledge(),
      this.loadCourses(),
      this.loadPoints()
    ]).finally(() => {
      wx.stopPullDownRefresh();
    });
  },

  onReachBottom() {
    if (app.isLoggedIn() && this.data.hasMore && !this.data.loading) {
      this.loadCourses(true);
    }
  },

  async loadCategories() {
    if (!app.isLoggedIn()) return;
    try {
      const res = await app.request({
        url: `${app.globalData.apiBase}/learning/category.php`
      });
      if (res.code === 0) {
        this.setData({ categories: res.data.list || [] });
      }
    } catch (err) {
      console.error('加载分类失败:', err);
    }
  },

  async loadCommonKnowledge() {
    if (!app.isLoggedIn()) return;
    try {
      const res = await app.request({
        url: `${app.globalData.apiBase}/knowledge/list.php?type=knowledge_card&page=1&page_size=4`
      });
      if (res.code === 0) {
        this.setData({ commonKnowledge: (res.data.list || []).map(item => this.normalizeKnowledge(item)) });
      }
    } catch (err) {
      console.error('加载通用知识失败:', err);
    }
  },

  async loadCourses(isLoadMore = false) {
    if (!app.isLoggedIn()) return;
    if (this.data.loading) return;

    const page = isLoadMore ? this.data.page + 1 : 1;
    this.setData({ loading: true });

    try {
      let url = `${app.globalData.apiBase}/learning/list.php?page=${page}&page_size=10`;
      if (this.data.selectedCategoryId > 0) {
        url += `&category_id=${this.data.selectedCategoryId}`;
      }

      const res = await app.request({ url });
      if (res.code === 0) {
        const newList = (res.data.list || []).map(course => this.normalizeCourse(course));
        const courses = isLoadMore
          ? [...this.data.courses, ...newList]
          : newList;

        this.setData({
          courses,
          page,
          hasMore: newList.length === 10,
          loading: false
        });
      }
    } catch (err) {
      console.error('加载课程失败:', err);
      this.setData({ loading: false });
    }
  },

  async loadPoints() {
    if (!app.isLoggedIn()) return;
    try {
      const res = await app.request({
        url: `${app.globalData.apiBase}/points/index.php`
      });
      if (res.code === 0) {
        this.setData({
          totalPoints: res.data.total_points,
          todayChecked: res.data.today_checked
        });
      }
    } catch (err) {
      console.error('加载积分失败:', err);
    }
  },

  selectCategory(e) {
    const categoryId = e.currentTarget.dataset.id;
    this.setData({
      selectedCategoryId: categoryId,
      page: 1,
      courses: []
    });
    this.loadCourses();
  },

  goToCourse(e) {
    const courseId = e.currentTarget.dataset.id;
    wx.navigateTo({
      url: `/pages/learning/detail?id=${courseId}`
    });
  },

  goToPolicy() {
    wx.navigateTo({
      url: '/pages/policy-search/index'
    });
  },

  goToKnowledge() {
    wx.switchTab({
      url: '/pages/knowledge/list'
    });
  },

  goToKnowledgeDetail(e) {
    const id = e.currentTarget.dataset.id;
    wx.navigateTo({
      url: `/pages/knowledge/detail?id=${id}`
    });
  },

  async doCheckin() {
    if (!this.ensureLogin()) return;
    if (this.data.todayChecked) {
      wx.showToast({ title: '今日已签到', icon: 'none' });
      return;
    }

    try {
      const res = await app.request({
        url: `${app.globalData.apiBase}/points/checkin.php`,
        method: 'POST'
      });
      if (res.code === 0) {
        this.setData({
          todayChecked: true,
          totalPoints: res.data.balance
        });
        wx.showToast({
          title: `签到成功！+${res.data.points}积分`,
          icon: 'success'
        });
      } else {
        wx.showToast({ title: res.message, icon: 'none' });
      }
    } catch (err) {
      wx.showToast({ title: '签到失败', icon: 'none' });
    }
  },

  normalizeCourse(course) {
    const difficultyNames = ['', '初级', '中级', '高级'];
    const coverImage = this.normalizeImageUrl(course.cover_image);
    return {
      ...course,
      cover_image: coverImage,
      has_cover_image: !!coverImage,
      status_class: course.is_completed ? 'completed' : '',
      status_text: course.is_completed ? '已完成' : (course.is_started ? '学习中' : '未开始'),
      difficulty_name: difficultyNames[course.difficulty] || '初级'
    };
  },

  normalizeKnowledge(item) {
    const typeNames = {
      action: '动作库',
      script: '话术库',
      knowledge_card: '知识卡'
    };
    const iconMap = {
      action: '动',
      script: '话',
      knowledge_card: '知'
    };
    return {
      ...item,
      type_name: typeNames[item.category_type] || '知识',
      icon_text: iconMap[item.category_type] || '知',
      summary_text: item.summary || '暂无摘要，点击查看详情'
    };
  },

  normalizeImageUrl(url) {
    const value = typeof url === 'string' ? url.trim() : '';
    if (!value || value === 'null' || value === 'undefined') return '';
    if (/^https?:\/\//.test(value)) return value;
    const siteBase = (app.globalData.apiBase || '').replace(/\/api\/?$/, '');
    if (value.indexOf('/') === 0) return `${siteBase}${value}`;
    return `${siteBase}/${value}`;
  }
});
