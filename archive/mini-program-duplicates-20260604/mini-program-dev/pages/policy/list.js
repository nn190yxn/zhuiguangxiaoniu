const app = getApp();

Page({
  data: {
    policies: [],
    currentCategory: '',
    keyword: '',
    loading: false,
    page: 1,
    hasMore: true
  },

  onLoad() {
    this.loadPolicies();
  },

  onShow() {
    // 刷新列表
    this.setData({ page: 1, policies: [] });
    this.loadPolicies();
  },

  onReachBottom() {
    if (this.data.hasMore && !this.data.loading) {
      this.loadPolicies(true);
    }
  },

  loadPolicies(isLoadMore = false) {
    if (this.data.loading) return;

    const page = isLoadMore ? this.data.page + 1 : 1;

    this.setData({ loading: true });

    const params = {
      page,
      page_size: 20
    };

    if (this.data.currentCategory) {
      params.category = this.data.currentCategory;
    }

    if (this.data.keyword) {
      params.keyword = this.data.keyword;
    }

    const query = Object.keys(params)
      .map(k => `${k}=${encodeURIComponent(params[k])}`)
      .join('&');

    app.request({
      url: `${app.globalData.apiBase}/policy/search.php?${query}`
    }).then(res => {
      const newList = res.data.list || [];
      const policies = isLoadMore
        ? [...this.data.policies, ...newList]
        : newList;

      this.setData({
        policies,
        page,
        hasMore: newList.length === 20,
        loading: false
      });
    }).catch(err => {
      console.error('加载制度失败:', err);
      this.setData({ loading: false });
    });
  },

  onUnload() {
    clearTimeout(this.searchTimer);
  },

  onSearch(e) {
    const keyword = e.detail.value;
    this.setData({
      keyword,
      page: 1,
      policies: []
    });

    // 防抖
    clearTimeout(this.searchTimer);
    this.searchTimer = setTimeout(() => {
      this.loadPolicies();
    }, 300);
  },

  switchCategory(e) {
    const category = e.currentTarget.dataset.category;
    if (category === this.data.currentCategory) return;

    this.setData({
      currentCategory: category,
      page: 1,
      policies: []
    });
    this.loadPolicies();
  },

  viewPolicy(e) {
    const id = e.currentTarget.dataset.id;
    wx.navigateTo({
      url: `/pages/policy/detail?id=${id}`
    });
  }
});
