const app = getApp();
const viewState = require('../../utils/view-state');

const FILTERS = {
  types: [
    { value: '', label: '全部' },
    { value: 'action', label: '动作库' },
    { value: 'script', label: '话术库' },
    { value: 'knowledge_card', label: '知识卡' }
  ],
  contentTypes: [
    { value: '', label: '全部' },
    { value: 'action', label: '动作' },
    { value: 'game', label: '游戏' },
    { value: 'training_plan', label: '训练计划' },
    { value: 'teaching_organization', label: '教学组织' },
    { value: 'teaching_knowledge', label: '教学知识' },
    { value: 'assessment', label: '测评' },
    { value: 'safety', label: '安全与禁忌' },
    { value: 'method', label: '方法' },
    { value: 'principle', label: '原理' },
    { value: 'case', label: '案例' },
    { value: 'checklist', label: '清单' }
  ],
  domains: [
    { value: '', label: '全部' },
    { value: 'fitness', label: '体能' },
    { value: 'sensory', label: '感统' },
    { value: 'sales', label: '销售' },
    { value: 'coach', label: '教练' },
    { value: 'operation', label: '运营' }
  ],
  subjects: [
    { value: '', label: '全部' },
    { value: 'fitness', label: '体能' },
    { value: 'sensory', label: '感统' },
    { value: 'skill', label: '技能' }
  ],
  ageGroups: [
    { value: '', label: '全部' },
    { value: '3-6', label: '3-6岁' },
    { value: '7-12', label: '7-12岁' },
    { value: '13-18', label: '13-18岁' },
    { value: '成人', label: '成人' }
  ],
  trainingTypes: [
    { value: '', label: '全部' },
    { value: 'strength', label: '力量' },
    { value: 'cardio', label: '心肺' },
    { value: 'flexibility', label: '柔韧' },
    { value: 'balance', label: '平衡' },
    { value: 'coordination', label: '协调' }
  ],
  difficulties: [
    { value: '', label: '全部' },
    { value: '1', label: '1级' },
    { value: '2', label: '2级' },
    { value: '3', label: '3级' },
    { value: '4', label: '4级' },
    { value: '5', label: '5级' }
  ],
  risks: [
    { value: '', label: '全部' },
    { value: 'low', label: '低风险' },
    { value: 'medium', label: '中风险' },
    { value: 'high', label: '高风险' }
  ]
};

Page({
  data: {
    ...FILTERS,
    currentType: '',
    currentContentType: '',
    currentDomainCode: '',
    currentSubject: '',
    currentAgeGroup: '',
    currentTrainingType: '',
    currentDifficulty: '',
    currentRiskLevel: '',
    quickMode: 'all',
    filtersExpanded: false,
    list: [],
    loading: false,
    page: 1,
    hasMore: true,
    searchKeyword: '',
    loginRequired: false,
    emptyText: '暂无知识库内容',
    listState: viewState.readState('empty')
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
    this.reload().finally(() => wx.stopPullDownRefresh());
  },

  onReachBottom() {
    if (app.isLoggedIn() && this.data.hasMore && !this.data.loading) {
      this.loadKnowledge(true);
    }
  },

  async loadKnowledge(isLoadMore = false) {
    if (!app.isLoggedIn() || this.data.loading) return;
    const page = isLoadMore ? this.data.page + 1 : 1;
    this.setData({ loading: true, listState: viewState.readState('loading') });

    try {
      const params = this.buildQuery(page);
      const res = await app.request({ url: `/knowledge/list.php?${params}` });
      if (res.code === 0) {
        const newList = this.normalizeKnowledgeList(res.data.list || []);
        const list = isLoadMore ? [...this.data.list, ...newList] : newList;
        this.setData({
          list,
          page,
          hasMore: newList.length === 20,
          emptyText: this.emptyText(),
          loading: false,
          listState: viewState.readState(list.length ? 'ready' : 'empty')
        });
      } else {
        throw new Error(res.message || '知识加载失败');
      }
    } catch (err) {
      wx.showToast({ title: '加载失败，请检查网络', icon: 'none' });
      this.setData({ loading: false, listState: viewState.fromError(err, '知识加载失败', 'loadKnowledge') });
    }
  },

  buildQuery(page) {
    const params = [`page=${page}`, 'page_size=20'];
    const map = {
      type: this.data.currentType,
      content_type: this.data.currentContentType,
      domain_code: this.data.currentDomainCode,
      subject: this.data.currentSubject,
      age_group: this.data.currentAgeGroup,
      training_type: this.data.currentTrainingType,
      difficulty: this.data.currentDifficulty,
      risk_level: this.data.currentRiskLevel,
      keyword: this.data.searchKeyword
    };
    Object.keys(map).forEach(key => {
      if (map[key]) params.push(`${key}=${encodeURIComponent(map[key])}`);
    });
    if (this.data.quickMode === 'favorite') params.push('favorite=1');
    if (this.data.quickMode === 'recent') params.push('recent=1');
    return params.join('&');
  },

  emptyText() {
    if (this.data.quickMode === 'favorite') return '暂无收藏知识';
    if (this.data.quickMode === 'recent') return '暂无最近浏览';
    if (this.data.searchKeyword) return '未找到匹配的知识';
    return '暂无知识库内容';
  },

  selectQuickMode(e) {
    if (!this.ensureLogin()) return;
    this.setData({ quickMode: e.currentTarget.dataset.mode || 'all', page: 1, list: [] });
    this.loadKnowledge();
  },

  toggleFilters() {
    this.setData({ filtersExpanded: !this.data.filtersExpanded });
  },

  selectFilter(e) {
    if (!this.ensureLogin()) return;
    const field = e.currentTarget.dataset.field;
    const value = e.currentTarget.dataset.value || '';
    const stateMap = {
      type: 'currentType',
      contentType: 'currentContentType',
      domainCode: 'currentDomainCode',
      subject: 'currentSubject',
      ageGroup: 'currentAgeGroup',
      trainingType: 'currentTrainingType',
      difficulty: 'currentDifficulty',
      riskLevel: 'currentRiskLevel'
    };
    const key = stateMap[field];
    if (!key) return;
    this.setData({ [key]: value, page: 1, list: [] });
    this.loadKnowledge();
  },

  clearFilters() {
    this.setData({
      currentType: '',
      currentContentType: '',
      currentDomainCode: '',
      currentSubject: '',
      currentAgeGroup: '',
      currentTrainingType: '',
      currentDifficulty: '',
      currentRiskLevel: '',
      searchKeyword: '',
      quickMode: 'all',
      page: 1,
      list: []
    });
    this.loadKnowledge();
  },

  reload() {
    this.setData({ page: 1, list: [] });
    return this.loadKnowledge();
  },

  onUnload() {
    clearTimeout(this.searchTimer);
  },

  onSearch(e) {
    if (!this.ensureLogin()) return;
    const keyword = String(e.detail.value || '').trim();
    clearTimeout(this.searchTimer);
    this.searchTimer = setTimeout(() => {
      this.setData({ searchKeyword: keyword, page: 1, list: [] });
      this.loadKnowledge();
    }, 300);
  },

  goToDetail(e) {
    if (!this.ensureLogin()) return;
    wx.navigateTo({ url: `/pages/knowledge/detail?id=${e.currentTarget.dataset.id}` });
  },

  normalizeKnowledgeList(list) {
    const label = (items, value) => (items.find(item => item.value === String(value || '')) || {}).label || '';
    const typeNames = { action: '动作', script: '话术', knowledge_card: '知识卡' };
    const riskNames = { low: '低风险', medium: '中风险', high: '高风险', '低': '低风险', '中': '中风险', '高': '高风险' };
    const riskClasses = { low: 'low', medium: 'medium', high: 'high', '低': 'low', '中': 'medium', '高': 'high' };
    return list.map(item => ({
      ...item,
      category_type_name: typeNames[item.category_type] || '知识',
      content_type_name: label(FILTERS.contentTypes, item.content_type),
      domain_name: label(FILTERS.domains, item.domain_code),
      subject_name: label(FILTERS.subjects, item.subject),
      training_name: label(FILTERS.trainingTypes, item.training_type),
      risk_name: riskNames[item.risk_level] || '',
      risk_class: riskClasses[item.risk_level] || '',
      favorite_text: Number(item.is_favorite || 0) > 0 ? '已收藏' : ''
    }));
  }
});
