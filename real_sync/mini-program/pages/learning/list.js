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
    hasMore: true
  },

  onLoad() {
    this.loadCategories();
    this.loadCommonKnowledge();
    this.loadCourses();
    this.loadPoints();
    this.loadPassSummary();
  },

  onPullDownRefresh() {
    this.setData({ page: 1, courses: [] });
    Promise.all([
      this.loadCategories(),
      this.loadCommonKnowledge(),
      this.loadCourses(),
      this.loadPoints(),
      this.loadPassSummary()
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
        this.setData({ categories: res.data.list || [] });
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

  goToPassMap() {
    wx.switchTab({
      url: '/pages/pass/map'
    });
  },

  goToPassStage(e) {
    const id = e.currentTarget.dataset.id;
    wx.navigateTo({
      url: `/pages/pass/stage?id=${id}`
    });
  },

  async loadPassSummary() {
    try {
      const res = await app.request({
        url: `${app.globalData.apiBase}/pass/map.php`
      });
      if (res.code === 0) {
        const stages = res.data.stages || [];
        const completed = stages.filter(s => s.status === 'completed');
        const active = stages.find(s => s.status === 'active') || null;

        const statusNames = {
          'completed': '已通关',
          'active': '进行中',
          'locked': '未解锁'
        };

        this.setData({
          passSummary: {
            completed_count: completed.length,
            total_count: stages.length,
            certificates: completed.filter(s => s.certificate).length,
            active_stage: active ? {
              name: active.name,
              completed_count: active.completed_count,
              tasks_count: active.tasks_count,
              progress_percent: active.progress_percent
            } : null,
            recent_stages: stages.slice(0, 6).map(s => ({
              id: s.id,
              name: s.name,
              status: s.status,
              status_text: statusNames[s.status] || '未开始'
            }))
          }
        });
      }
    } catch (err) {
      console.error('加载通关摘要失败:', err);
    }
  },

  normalizeCourse(course) {
    const difficultyNames = ['', '初级', '中级', '高级'];
    return {
      ...course,
      status_class: course.is_completed ? 'completed' : '',
      status_text: course.is_completed ? '已完成' : (course.is_started ? '学习中' : '未开始'),
      difficulty_name: difficultyNames[course.difficulty] || '初级'
    };
  }
});
