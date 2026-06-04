const app = getApp();

Page({
  data: {
    totalPoints: 0,
    todayChecked: false,
    categories: [],
    selectedCategoryId: 0,
    courses: [],
    commonKnowledge: [],
    passSummary: null,
    loading: false,
    page: 1,
    hasMore: true
  },

  onLoad() {
    this.loadCategories();
    this.loadCommonKnowledge();
    this.loadPassSummary();
    this.loadCourses();
    this.loadPoints();
  },

  onPullDownRefresh() {
    this.setData({ page: 1, courses: [] });
    Promise.all([
      this.loadCategories(),
      this.loadCommonKnowledge(),
      this.loadPassSummary(),
      this.loadCourses(),
      this.loadPoints()
    ]).finally(() => {
      wx.stopPullDownRefresh();
    });
  },

  onReachBottom() {
    if (this.data.hasMore && !this.data.loading) {
      this.loadCourses(true);
    }
  },

  async loadCategories() {
    try {
      const res = await app.request({
        url: `${app.globalData.apiBase}/learning/category.php`
      });
      if (res.code === 0) {
        this.setData({
          categories: this.normalizeCategories(res.data.list || [])
        });
      }
    } catch (err) {
      console.error('加载分类失败:', err);
    }
  },

  async loadCommonKnowledge() {
    try {
      const res = await app.request({
        url: `${app.globalData.apiBase}/knowledge/list.php?type=knowledge_card&page=1&page_size=4`
      });
      if (res.code === 0) {
        this.setData({ commonKnowledge: res.data.list || [] });
      }
    } catch (err) {
      console.error('加载通用知识失败:', err);
    }
  },

  async loadPassSummary() {
    try {
      const res = await app.request({
        url: `${app.globalData.apiBase}/pass/map.php`
      });
      if (res.code === 0) {
        this.setData({ passSummary: this.normalizePassSummary(res.data || {}) });
      }
    } catch (err) {
      this.setData({ passSummary: null });
    }
  },

  async loadCourses(isLoadMore = false) {
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

  goToPassMap() {
    wx.navigateTo({
      url: '/pages/pass/map'
    });
  },

  goToPassStage(e) {
    const id = e.currentTarget.dataset.id;
    if (!id) return;
    wx.navigateTo({
      url: `/pages/pass/stage?stage_id=${id}`
    });
  },

  async doCheckin() {
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
      category_name: this.normalizeCategoryName(course.category_name),
      cover_image: coverImage,
      coverStyle: coverImage ? `background-image: url('${coverImage}')` : '',
      status_class: course.is_completed ? 'completed' : '',
      status_text: course.is_completed ? '已完成' : (course.is_started ? '学习中' : '未开始'),
      difficulty_name: difficultyNames[course.difficulty] || '初级'
    };
  },

  normalizeCategories(list) {
    const seen = {};
    const categories = [];
    (list || []).forEach(item => {
      const name = this.normalizeCategoryName(item.name);
      if (!name || seen[name]) return;
      seen[name] = true;
      categories.push({
        ...item,
        name
      });
    });
    return categories;
  },

  normalizePassSummary(data) {
    const statusText = {
      locked: '\u672a\u89e3\u9501',
      active: '\u8fdb\u884c\u4e2d',
      completed: '\u5df2\u901a\u5173'
    };
    const stages = (data.stages || []).map(stage => ({
      ...stage,
      status_text: statusText[stage.status] || '\u672a\u89e3\u9501'
    }));
    if (!stages.length) return null;

    const completed = stages.filter(stage => stage.status === 'completed');
    const active = stages.find(stage => stage.status === 'active')
      || stages.find(stage => stage.status !== 'completed')
      || null;
    const certificates = stages.filter(stage => stage.certificate).length;

    return {
      completed_count: completed.length,
      total_count: stages.length,
      certificates,
      active_stage: active,
      recent_stages: stages.slice(0, 3)
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
