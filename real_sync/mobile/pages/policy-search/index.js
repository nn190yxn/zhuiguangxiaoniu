const app = getApp();

Page({
  data: {
    keyword: '',
    currentCategory: '',
    currentWorkflow: '',
    policies: [],
    total: 0,
    loading: false,
    hasSearched: false
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
      let url = `${app.globalData.apiBase}/policy/search.php?page=1&page_size=50`;
      if (keyword) url += `&keyword=${encodeURIComponent(keyword)}`;
      if (currentCategory) url += `&category=${encodeURIComponent(currentCategory)}`;
      if (currentWorkflow) url += `&workflow=${encodeURIComponent(currentWorkflow)}`;

      const res = await app.request({ url });
      if (res.code === 0) {
        this.setData({
          policies: res.data.list || [],
          total: res.data.pagination?.total || res.data.list?.length || 0
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