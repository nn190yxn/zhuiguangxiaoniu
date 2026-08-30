const drill = require('../../../utils/drill-v2');

Page({
  data: {
    view: 'setup',
    loading: false,
    catalog: null,
    sectionIndex: 0,
    sections: [],
    countIndex: 0,
    counts: [5, 10, 20],
    session: null,
    question: null,
    phase: 'answering',
    answerText: '',
    submitting: false,
    scoreResult: null,
    resultItems: [],
    historyItems: [],
    historyLoading: false,
    historyDetail: null
  },

  onLoad() {
    this.loadCatalog();
    this.loadHistory();
  },

  onShow() {
    if (this.data.view === 'setup') {
      this.loadHistory();
    }
  },

  onPullDownRefresh() {
    Promise.all([this.loadCatalog(), this.loadHistory()]).finally(() => wx.stopPullDownRefresh());
  },

  async loadCatalog() {
    try {
      const catalog = await drill.loadQaCatalog();
      const sections = [{ code: 'all', name: '全部篇目（随机混合）', question_count: catalog.total_questions || 0 }]
        .concat(catalog.sections || []);
      this.setData({
        catalog,
        sections,
        counts: (catalog.default_counts && catalog.default_counts.length ? catalog.default_counts : [5, 10, 20])
      });
    } catch (err) {
      this.setData({ catalog: null });
    }
  },

  async loadHistory() {
    if (this.data.historyLoading) return;
    this.setData({ historyLoading: true });
    try {
      const res = await drill.loadQaHistory(20);
      this.setData({ historyItems: res.items || [] });
    } catch (err) {
      this.setData({ historyItems: [] });
    } finally {
      this.setData({ historyLoading: false });
    }
  },

  onSectionChange(e) {
    this.setData({ sectionIndex: Number(e.detail.value) || 0 });
  },

  onCountChange(e) {
    this.setData({ countIndex: Number(e.currentTarget.dataset.index) || 0 });
  },

  async startQa() {
    if (this.data.loading) return;
    const section = this.data.sections[this.data.sectionIndex];
    const count = this.data.counts[this.data.countIndex] || 10;
    this.setData({ loading: true });
    try {
      const res = await drill.createQaSession(section ? section.code : 'all', count);
      this.setData({
        view: 'question',
        session: res.session,
        question: res.question,
        phase: 'answering',
        answerText: '',
        scoreResult: null
      });
    } catch (err) {
      wx.showToast({ title: err.message || '开始失败', icon: 'none' });
    } finally {
      this.setData({ loading: false });
    }
  },

  onInput(e) {
    this.setData({ answerText: e.detail.value });
  },

  async submitAnswer() {
    const answer = (this.data.answerText || '').trim();
    const sessionId = this.data.session && this.data.session.session_id;
    if (!answer || !sessionId || this.data.submitting) return;

    this.setData({ submitting: true });
    try {
      const res = await drill.submitQaAnswer(sessionId, answer);
      if (res.status === 'retry_pending') {
        wx.showToast({ title: '评分服务暂不可用，请重试', icon: 'none' });
        return;
      }
      const next = { session: res.session, scoreResult: res.score_result };
      if (res.session && res.session.status === 'completed') {
        let resultItems = [];
        try {
          const detail = await drill.loadQaDetail(sessionId);
          resultItems = (detail.answers || []).map(a => ({
            question_no: a.question_no,
            question: a.question,
            score: Math.round(a.score)
          }));
        } catch (err) {
          resultItems = [];
        }
        this.setData({ ...next, resultItems, view: 'result' });
        this.loadHistory();
      } else {
        this.setData({ ...next, phase: 'scored' });
      }
    } catch (err) {
      wx.showToast({ title: err.message || '提交失败', icon: 'none' });
    } finally {
      this.setData({ submitting: false });
    }
  },

  nextQuestion() {
    const session = this.data.session;
    if (!session || !session.question) {
      wx.showToast({ title: '题目加载失败', icon: 'none' });
      return;
    }
    this.setData({
      phase: 'answering',
      question: session.question,
      answerText: '',
      scoreResult: null
    });
  },

  goBack() {
    wx.navigateBack();
  },

  restart() {
    this.setData({
      view: 'setup',
      session: null,
      question: null,
      phase: 'answering',
      answerText: '',
      scoreResult: null,
      historyDetail: null
    });
    this.loadHistory();
  },

  async openHistoryDetail(e) {
    const sessionId = Number(e.currentTarget.dataset.id) || 0;
    if (!sessionId) return;
    this.setData({ loading: true });
    try {
      const detail = await drill.loadQaDetail(sessionId);
      this.setData({ historyDetail: detail, view: 'history', loading: false });
    } catch (err) {
      this.setData({ loading: false });
      wx.showToast({ title: err.message || '历史明细加载失败', icon: 'none' });
    }
  },

  backFromHistory() {
    this.setData({ view: 'setup', historyDetail: null });
    this.loadHistory();
  }
});
