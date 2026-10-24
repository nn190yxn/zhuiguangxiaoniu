const app = getApp();
const viewState = require('../../utils/view-state');

const RISK_TEXT_MAP = { high: '高风险', medium: '中风险', low: '低风险', '高': '高风险', '中': '中风险', '低': '低风险' };
const RISK_CLASS_MAP = { high: 'high', medium: 'medium', low: 'low', '高': 'high', '中': 'medium', '低': 'low' };

Page({
  data: {
    currentType: '',
    currentSubject: '',
    currentAgeGroup: '',
    currentTrainingType: '',
    quickMode: '',
    showAdvanced: false,
    currentContentType: '',
    currentRiskLevel: '',
    currentCoachGroup: '',
    contentTypes: [
      { value: 'action', label: '动作' },
      { value: 'game', label: '游戏' },
      { value: 'training_plan', label: '训练计划' },
      { value: 'teaching_organization', label: '教研组织' },
      { value: 'teaching_knowledge', label: '教学知识' },
      { value: 'assessment', label: '体测评估' },
      { value: 'safety', label: '安全' },
      { value: 'coach_growth', label: '教练成长' }
    ],
    coachGroups: [
      { code: 'G01', label: '儿童发展' },
      { code: 'G02', label: '运动与大脑' },
      { code: 'G03', label: '心理与情绪' },
      { code: 'G04', label: '行为与课堂' },
      { code: 'G05', label: '教学法' },
      { code: 'G06', label: '家长协同' },
      { code: 'G07', label: '健康边界' },
      { code: 'G08', label: '教练成长' }
    ],
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
    this.setData({ loading: true, listState: viewState.readState('loading') });

    try {
      let url = `/knowledge/list.php?page=${page}&page_size=20`;
      if (this.data.currentType) url += `&type=${this.data.currentType}`;
      if (this.data.currentSubject) url += `&subject=${this.data.currentSubject}`;
      if (this.data.currentAgeGroup) url += `&age_group=${encodeURIComponent(this.data.currentAgeGroup)}`;
      if (this.data.currentTrainingType) url += `&training_type=${this.data.currentTrainingType}`;
      if (this.data.quickMode === 'favorite') url += '&favorite=1';
      if (this.data.quickMode === 'recent') url += '&recent=1';
      if (this.data.currentContentType) url += `&content_type=${this.data.currentContentType}`;
      if (this.data.currentRiskLevel) url += `&risk_level=${this.data.currentRiskLevel}`;
      if (this.data.currentContentType === 'coach_growth' && this.data.currentCoachGroup) {
        url += `&domain_code=${this.data.currentCoachGroup}`;
      }
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
          loading: false,
          listState: viewState.readState(list.length ? 'ready' : 'empty')
        });
      }
    } catch (err) {
      console.error('加载失败:', err);
      wx.showToast({ title: '加载失败，请检查网络', icon: 'none' });
      this.setData({ loading: false, listState: viewState.fromError(err, '知识加载失败', 'loadKnowledge') });
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
      searchKeyword: '',
      page: 1,
      list: []
    });
    this.loadKnowledge();
  },

  setQuickMode(e) {
    if (!this.ensureLogin()) return;
    const mode = e.currentTarget.dataset.mode || '';
    this.setData({ quickMode: mode, page: 1, list: [] });
    this.loadKnowledge();
  },

  toggleAdvanced() {
    this.setData({ showAdvanced: !this.data.showAdvanced });
  },

  selectContentType(e) {
    if (!this.ensureLogin()) return;
    const value = e.currentTarget.dataset.value || '';
    this.setData({ currentContentType: value, currentCoachGroup: '', page: 1, list: [] });
    this.loadKnowledge();
  },

  selectRiskLevel(e) {
    if (!this.ensureLogin()) return;
    const value = e.currentTarget.dataset.value || '';
    this.setData({ currentRiskLevel: value, page: 1, list: [] });
    this.loadKnowledge();
  },

  selectCoachGroup(e) {
    if (!this.ensureLogin()) return;
    const code = e.currentTarget.dataset.code || '';
    this.setData({ currentCoachGroup: code, page: 1, list: [] });
    this.loadKnowledge();
  },

  resetAdvanced() {
    if (!this.ensureLogin()) return;
    this.setData({
      currentContentType: '',
      currentRiskLevel: '',
      currentCoachGroup: '',
      page: 1,
      list: []
    });
    this.loadKnowledge();
  },

  selectSubject(e) {
    if (!this.ensureLogin()) return;
    const subject = e.currentTarget.dataset.value;
    this.setData({ currentSubject: subject, searchKeyword: '', page: 1, list: [] });
    this.loadKnowledge();
  },

  selectAgeGroup(e) {
    if (!this.ensureLogin()) return;
    const ageGroup = e.currentTarget.dataset.value;
    this.setData({ currentAgeGroup: ageGroup, searchKeyword: '', page: 1, list: [] });
    this.loadKnowledge();
  },

  selectTrainingType(e) {
    if (!this.ensureLogin()) return;
    const trainingType = e.currentTarget.dataset.value;
    this.setData({ currentTrainingType: trainingType, searchKeyword: '', page: 1, list: [] });
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
      training_name: trainingNames[item.training_type] || '',
      risk_text: RISK_TEXT_MAP[item.risk_level] || '',
      risk_class: RISK_CLASS_MAP[item.risk_level] || ''
    }));
  }
});
