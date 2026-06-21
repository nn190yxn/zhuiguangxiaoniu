const app = getApp();

Page({
  data: {
    currentType: '',
    currentSubject: '',
    currentAgeGroup: '',
    currentTrainingType: '',
    list: [],
    loading: false,
    page: 1,
    hasMore: true,
    searchKeyword: '',
    loginRequired: false,
    emptyText: '暂无知识库内容'
  },

  onLoad() {
    if (!this.ensureLogin()) return;
    this.loadKnowledge();
  },

  onShow() {
    if (this.data.loginRequired && this.ensureLogin()) {
      this.setData({ loginRequired: false, page: 1, list: [] });
      this.loadKnowledge();
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
    this.setData({ page: 1, list: [] });
    this.loadKnowledge().finally(() => wx.stopPullDownRefresh());
  },

  onReachBottom() {
    if (app.isLoggedIn() && this.data.hasMore && !this.data.loading) {
      this.loadKnowledge(true);
    }
  },

  async loadKnowledge(isLoadMore = false) {
    if (!app.isLoggedIn()) return;
    if (this.data.loading) return;

    const page = isLoadMore ? this.data.page + 1 : 1;
    this.setData({ loading: true });

    try {
      let url = `${app.globalData.apiBase}/knowledge/list.php?page=${page}&page_size=20`;
      if (this.data.currentType) url += `&type=${this.data.currentType}`;
      if (this.data.currentSubject) url += `&subject=${this.data.currentSubject}`;
      if (this.data.currentAgeGroup) url += `&age_group=${encodeURIComponent(this.data.currentAgeGroup)}`;
      if (this.data.currentTrainingType) url += `&training_type=${this.data.currentTrainingType}`;
      if (this.data.searchKeyword) url += `&keyword=${encodeURIComponent(this.data.searchKeyword)}`;

      const res = await app.request({ url });
      if (res.code === 0) {
        const newList = this.normalizeKnowledgeList(res.data.list || []);
        const list = isLoadMore ? [...this.data.list, ...newList] : newList;

        this.setData({
          list,
          page,
          hasMore: newList.length === 20,
          emptyText: this.data.searchKeyword ? '未找到匹配的知识，可尝试体测、ACE、销售话术、教练课程等关键词' : '暂无知识库内容',
          loading: false
        });
      }
    } catch (err) {
      console.error('加载失败:', err);
      wx.showToast({ title: '加载失败，请检查网络', icon: 'none' });
      this.setData({ loading: false });
    }
  },

  selectType(e) {
    if (!this.ensureLogin()) return;
    const type = e.currentTarget.dataset.type;
    this.setData({
      currentType: type,
      currentSubject: '',
      currentAgeGroup: '',
      currentTrainingType: '',
      page: 1,
      list: []
    });
    this.loadKnowledge();
  },

  selectSubject(e) {
    if (!this.ensureLogin()) return;
    const subject = e.currentTarget.dataset.value;
    this.setData({ currentSubject: subject, page: 1, list: [] });
    this.loadKnowledge();
  },

  selectAgeGroup(e) {
    if (!this.ensureLogin()) return;
    const ageGroup = e.currentTarget.dataset.value;
    this.setData({ currentAgeGroup: ageGroup, page: 1, list: [] });
    this.loadKnowledge();
  },

  selectTrainingType(e) {
    if (!this.ensureLogin()) return;
    const trainingType = e.currentTarget.dataset.value;
    this.setData({ currentTrainingType: trainingType, page: 1, list: [] });
    this.loadKnowledge();
  },

  onUnload() {
    clearTimeout(this.searchTimer);
  },

  onSearch(e) {
    if (!this.ensureLogin()) return;
    const keyword = e.detail.value.trim();
    clearTimeout(this.searchTimer);
    this.searchTimer = setTimeout(() => {
      this.setData({ searchKeyword: keyword, page: 1, list: [] });
      this.loadKnowledge();
    }, 300);
  },

  goToDetail(e) {
    if (!this.ensureLogin()) return;
    wx.navigateTo({
      url: `/pages/knowledge/detail?id=${e.currentTarget.dataset.id}`
    });
  },

  normalizeKnowledgeList(list) {
    const subjectNames = { fitness: '体能', sensory: '感统', skill: '技能' };
    const trainingNames = {
      strength: '力量',
      cardio: '心肺',
      flexibility: '柔韧',
      balance: '平衡',
      coordination: '协调'
    };
    const typeNames = { action: '动作', script: '话术', knowledge_card: '知识卡' };

    return list.map(item => ({
      ...item,
      category_type_name: typeNames[item.category_type] || '知识',
      subject_name: subjectNames[item.subject] || '',
      training_name: trainingNames[item.training_type] || ''
    }));
  }
});
