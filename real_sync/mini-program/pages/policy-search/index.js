const app = getApp();

Page({
  data: {
    keyword: '',
    currentCategory: '',
    currentWorkflow: '',
    policies: [],
    total: 0,
    loading: false,
    hasSearched: false,
    emptyText: '未找到匹配的制度'
  },

  onLoad() {
  },

  onInputChange(e) {
    this.setData({ keyword: e.detail.value });
  },

  switchCategory(e) {
    const category = e.currentTarget.dataset.value;
    this.setData({ currentCategory: category });
    if (this.data.hasSearched) {
      this.doSearch();
    }
  },

  switchWorkflow(e) {
    const workflow = e.currentTarget.dataset.value;
    this.setData({ currentWorkflow: workflow });
    if (this.data.hasSearched) {
      this.doSearch();
    }
  },

  quickSearch(e) {
    const keyword = e.currentTarget.dataset.keyword;
    this.setData({ keyword });
    this.doSearch();
  },

  async doSearch() {
    const { keyword, currentCategory, currentWorkflow } = this.data;

    if (!keyword && !currentCategory && !currentWorkflow) {
      wx.showToast({ title: '请输入搜索关键词', icon: 'none' });
      return;
    }

    this.setData({ loading: true, hasSearched: true });

    try {
      let url = `${app.globalData.apiBase}/search/global.php?q=${encodeURIComponent(keyword || currentCategory || currentWorkflow)}&type=${encodeURIComponent('制度')}`;
      const usePolicyFilter = currentCategory || currentWorkflow;
      if (usePolicyFilter) {
        url = `${app.globalData.apiBase}/policy/search.php?page=1&page_size=50`;
        if (keyword) url += `&keyword=${encodeURIComponent(keyword)}`;
        if (currentCategory) url += `&category=${encodeURIComponent(currentCategory)}`;
        if (currentWorkflow) url += `&workflow=${encodeURIComponent(currentWorkflow)}`;
      }

      const res = await app.request({ url });
      if (res.code === 0) {
        const policies = usePolicyFilter
          ? (res.data.list || [])
          : ((res.data.results && res.data.results.policies) || []);
        const total = usePolicyFilter
          ? (res.data.pagination?.total || policies.length)
          : (res.data.total || policies.length);
        this.setData({
          policies,
          total,
          emptyText: total === 0 ? '未找到匹配的制度，可尝试门店、体测、绩效、请假等关键词' : ''
        });
      }
    } catch (err) {
      console.error('搜索失败:', err);
      wx.showToast({ title: '搜索失败', icon: 'none' });
    } finally {
      this.setData({ loading: false });
    }
  },

  viewPolicy(e) {
    const id = e.currentTarget.dataset.id;
    wx.navigateTo({
      url: `/pages/policy/detail?id=${id}`
    });
  }
});
