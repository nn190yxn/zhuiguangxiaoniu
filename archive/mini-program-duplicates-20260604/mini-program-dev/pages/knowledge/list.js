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
    searchKeyword: ''
  },

  onLoad() {
    this.loadKnowledge();
  },

  onPullDownRefresh() {
    this.setData({ page: 1, list: [] });
    this.loadKnowledge().finally(() => wx.stopPullDownRefresh());
  },

  onReachBottom() {
    if (this.data.hasMore && !this.data.loading && !this.data.searchKeyword) {
      this.loadKnowledge(true);
    }
  },

  async loadKnowledge(isLoadMore = false) {
    if (this.data.loading) return;

    const page = isLoadMore ? this.data.page + 1 : 1;
    this.setData({ loading: true });

    try {
      let url = `${app.globalData.apiBase}/knowledge/list.php?page=${page}&page_size=20`;
      if (this.data.currentType) url += `&type=${this.data.currentType}`;
      if (this.data.currentSubject) url += `&subject=${this.data.currentSubject}`;
      if (this.data.currentAgeGroup) url += `&age_group=${encodeURIComponent(this.data.currentAgeGroup)}`;
      if (this.data.currentTrainingType) url += `&training_type=${this.data.currentTrainingType}`;

      const res = await app.request({ url });
      if (res.code === 0) {
        const newList = this.normalizeKnowledgeList(res.data.list || []);
        const list = isLoadMore ? [...this.data.list, ...newList] : newList;

        this.setData({
          list,
          page,
          hasMore: newList.length === 20,
          loading: false
        });
      }
    } catch (err) {
      console.error('加载失败:', err);
      this.setData({ loading: false });
    }
  },

  selectType(e) {
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
    const subject = e.currentTarget.dataset.value;
    this.setData({ currentSubject: subject, page: 1, list: [] });
    this.loadKnowledge();
  },

  selectAgeGroup(e) {
    const ageGroup = e.currentTarget.dataset.value;
    this.setData({ currentAgeGroup: ageGroup, page: 1, list: [] });
    this.loadKnowledge();
  },

  selectTrainingType(e) {
    const trainingType = e.currentTarget.dataset.value;
    this.setData({ currentTrainingType: trainingType, page: 1, list: [] });
    this.loadKnowledge();
  },

  onSearch(e) {
    const keyword = e.detail.value.trim();
    if (keyword) {
      this.setData({ searchKeyword: keyword });
      this.searchKnowledge(keyword);
    }
  },

  async searchKnowledge(keyword) {
    this.setData({ loading: true });

    try {
      let url = `${app.globalData.apiBase}/knowledge/search.php?keyword=${encodeURIComponent(keyword)}`;
      if (this.data.currentType) url += `&type=${this.data.currentType}`;
      if (this.data.currentSubject) url += `&subject=${this.data.currentSubject}`;
      if (this.data.currentAgeGroup) url += `&age_group=${encodeURIComponent(this.data.currentAgeGroup)}`;
      if (this.data.currentTrainingType) url += `&training_type=${this.data.currentTrainingType}`;

      const res = await app.request({
        url: url
      });
      if (res.code === 0) {
        this.setData({ list: this.normalizeKnowledgeList(res.data.list || []), hasMore: false });
      }
    } catch (err) {
      wx.showToast({ title: '搜索失败', icon: 'none' });
    } finally {
      this.setData({ loading: false });
    }
  },

  goToDetail(e) {
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
    const coverMap = {
      action: { bg: '#fff3e0', icon: '动' },
      script: { bg: '#e3f2fd', icon: '话' },
      knowledge_card: { bg: '#f3e5f5', icon: '知' }
    };

    return list.map(item => {
      const cover = coverMap[item.category_type] || coverMap.knowledge_card;
      return {
        ...item,
        cover_bg: cover.bg,
        cover_icon: cover.icon,
        subject_name: subjectNames[item.subject] || '',
        training_name: trainingNames[item.training_type] || ''
      };
    });
  }
});
