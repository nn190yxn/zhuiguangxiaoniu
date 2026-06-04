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
    this.setData({ policies: [], total: 0, loading: false, hasSearched: false });
    wx.showToast({ title: '功能已取消', icon: 'none' });
  },

  viewPolicy(e) {
    wx.showToast({ title: '功能已取消', icon: 'none' });
  }
});
