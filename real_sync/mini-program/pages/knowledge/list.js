const app = getApp();

Page({
  data: {
    typeTabs: [
      { type: '', name: '全部', count: 0 },
      { type: 'action', name: '动作库', count: 0 },
      { type: 'script', name: '话术库', count: 0 },
      { type: 'knowledge_card', name: '知识卡', count: 0 }
    ],
    categories: [],
    visibleCategories: [],
    currentType: '',
    currentCategoryId: 0,
    currentSubject: '',
    currentAgeGroup: '',
    currentTrainingType: '',
    list: [],
    total: 0,
    loading: false,
    page: 1,
    hasMore: true,
    searchKeyword: '',
    searchValue: ''
  },

  onLoad() {
    this.loadCategories();
    this.loadKnowledge();
  },

  onPullDownRefresh() {
    this.setData({ page: 1, list: [] });
    Promise.all([
      this.loadCategories(),
      this.loadKnowledge()
    ]).finally(() => wx.stopPullDownRefresh());
  },

  onReachBottom() {
    if (this.data.hasMore && !this.data.loading) {
      this.loadKnowledge(true);
    }
  },

  async loadCategories() {
    try {
      const res = await app.request({
        url: `${app.globalData.apiBase}/knowledge/categories.php`
      });
      if (res.code !== 0) return;

      const categories = this.normalizeCategories(res.data.categories || []);
      const typeTabs = this.mergeTypeTabs(res.data.types || []);
      this.setData({
        categories,
        typeTabs,
        visibleCategories: this.getVisibleCategories(this.data.currentType, categories)
      });
    } catch (err) {
      console.error('加载知识分类失败:', err);
    }
  },

  async loadKnowledge(isLoadMore = false) {
    if (this.data.loading) return;

    const page = isLoadMore ? this.data.page + 1 : 1;
    this.setData({ loading: true });

    try {
      let url = `${app.globalData.apiBase}/knowledge/list.php?page=${page}&page_size=20`;
      if (this.data.currentType) url += `&type=${this.data.currentType}`;
      if (this.data.currentCategoryId > 0) url += `&category_id=${this.data.currentCategoryId}`;
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
          total: Number(res.data.total || 0),
          page,
          hasMore: newList.length === 20,
          loading: false
        });
      } else {
        this.setData({ loading: false });
      }
    } catch (err) {
      console.error('加载知识库失败:', err);
      this.setData({ loading: false });
    }
  },

  selectType(e) {
    const type = e.currentTarget.dataset.type || '';
    this.setData({
      currentType: type,
      currentCategoryId: 0,
      currentSubject: '',
      currentAgeGroup: '',
      currentTrainingType: '',
      visibleCategories: this.getVisibleCategories(type, this.data.categories),
      page: 1,
      list: []
    });
    this.loadKnowledge();
  },

  selectCategory(e) {
    const id = Number(e.currentTarget.dataset.id || 0);
    this.setData({
      currentCategoryId: id,
      page: 1,
      list: []
    });
    this.loadKnowledge();
  },

  selectSubject(e) {
    const subject = e.currentTarget.dataset.value || '';
    this.setData({ currentSubject: subject, page: 1, list: [] });
    this.loadKnowledge();
  },

  selectAgeGroup(e) {
    const ageGroup = e.currentTarget.dataset.value || '';
    this.setData({ currentAgeGroup: ageGroup, page: 1, list: [] });
    this.loadKnowledge();
  },

  selectTrainingType(e) {
    const trainingType = e.currentTarget.dataset.value || '';
    this.setData({ currentTrainingType: trainingType, page: 1, list: [] });
    this.loadKnowledge();
  },

  onSearchInput(e) {
    this.setData({ searchValue: e.detail.value || '' });
  },

  onSearch(e) {
    const keyword = (e.detail.value || this.data.searchValue || '').trim();
    this.setData({
      searchKeyword: keyword,
      searchValue: keyword,
      page: 1,
      list: []
    });
    this.loadKnowledge();
  },

  clearSearch() {
    this.setData({
      searchKeyword: '',
      searchValue: '',
      page: 1,
      list: []
    });
    this.loadKnowledge();
  },

  resetFilters() {
    this.setData({
      currentType: '',
      currentCategoryId: 0,
      currentSubject: '',
      currentAgeGroup: '',
      currentTrainingType: '',
      searchKeyword: '',
      searchValue: '',
      visibleCategories: this.getVisibleCategories('', this.data.categories),
      page: 1,
      list: []
    });
    this.loadKnowledge();
  },

  goToDetail(e) {
    wx.navigateTo({
      url: `/pages/knowledge/detail?id=${e.currentTarget.dataset.id}`
    });
  },

  mergeTypeTabs(types) {
    const fallback = this.data.typeTabs;
    const incoming = {};
    (types || []).forEach(item => {
      incoming[item.type || ''] = item;
    });
    return fallback.map(tab => ({
      ...tab,
      count: Number((incoming[tab.type] && incoming[tab.type].count) || 0)
    }));
  },

  normalizeCategories(list) {
    const typeNames = this.getTypeNames();
    return (list || [])
      .filter(item => Number(item.item_count || 0) > 0)
      .map(item => ({
        ...item,
        id: Number(item.id || 0),
        item_count: Number(item.item_count || 0),
        sort_order: Number(item.sort_order || 0),
        type_name: typeNames[item.type] || '知识',
        display_icon: this.normalizeIcon(item.icon, item.type)
      }));
  },

  getVisibleCategories(type, categories) {
    const list = categories || [];
    if (!type) return list;
    return list.filter(item => item.type === type);
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
    const typeNames = this.getTypeNames();

    return (list || []).map(item => {
      const cover = coverMap[item.category_type] || coverMap.knowledge_card;
      const tags = Array.isArray(item.tags) ? item.tags : [];
      return {
        ...item,
        cover_bg: cover.bg,
        cover_icon: this.normalizeIcon(item.category_icon, item.category_type) || cover.icon,
        type_name: typeNames[item.category_type] || '知识',
        subject_name: subjectNames[item.subject] || '',
        training_name: trainingNames[item.training_type] || '',
        display_tags: tags.slice(0, 2),
        category_name: item.category_name || '未分类'
      };
    });
  },

  normalizeIcon(icon, type) {
    if (icon && String(icon).length <= 2) return icon;
    const map = { action: '动', script: '话', knowledge_card: '知' };
    return map[type] || '知';
  },

  getTypeNames() {
    return {
      action: '动作库',
      script: '话术库',
      knowledge_card: '知识卡'
    };
  }
});
