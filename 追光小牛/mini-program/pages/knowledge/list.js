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
        const newList = res.data.list || [];
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
        this.setData({ list: res.data.list || [], hasMore: false });
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
  }
});