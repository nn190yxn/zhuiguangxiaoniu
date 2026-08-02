const app = getApp();
const api = require('../../utils/api');
const viewState = require('../../utils/view-state');

Page({
  data: {
    activeTab: 'overview',
    overview: null,
    ranking: [],
    myRanking: null,
    records: [],
    mallItems: [],
    overviewState: viewState.readState('loading'),
    rankingState: viewState.readState('loading'),
    recordsState: viewState.readState('loading'),
    mallState: viewState.readState('loading'),
    writeState: viewState.writeState('idle'),
    exchangeItem: null,
    exchangeForm: { receiver_name: '', receiver_phone: '', receiver_address: '' },
    pendingOperation: null,
  },

  onLoad() {
    this.networkListener = ({ isConnected }) => {
      if (isConnected) this.retryOfflineReads();
    };
    if (typeof wx.onNetworkStatusChange === 'function') wx.onNetworkStatusChange(this.networkListener);
    this.loadAll();
  },

  onUnload() {
    if (this.networkListener && typeof wx.offNetworkStatusChange === 'function') {
      wx.offNetworkStatusChange(this.networkListener);
    }
  },

  onPullDownRefresh() {
    const refresh = this.data.activeTab === 'records' ? this.loadRecords() : this.loadAll();
    refresh.finally(() => wx.stopPullDownRefresh());
  },

  loadAll() {
    return Promise.all([this.reloadOverview(), this.loadRanking(), this.reloadMall()]);
  },

  selectOverview() {
    this.setData({ activeTab: 'overview' });
  },

  selectRanking() {
    this.setData({ activeTab: 'ranking' });
  },

  selectRecords() {
    this.setData({ activeTab: 'records' });
    this.loadRecords();
  },

  selectMall() {
    this.setData({ activeTab: 'mall' });
  },

  async reloadOverview() {
    this.setData({ overviewState: viewState.readState('loading') });
    try {
      const response = await app.request({ url: '/points/index.php' });
      const overview = response.data || null;
      this.setData({
        overview,
        overviewState: viewState.readState(overview ? 'ready' : 'empty'),
      });
    } catch (error) {
      this.setData({ overviewState: viewState.fromError(error, '积分概览加载失败', 'reloadOverview') });
    }
  },

  async loadRanking() {
    this.setData({ rankingState: viewState.readState('loading') });
    try {
      const response = await app.request({ url: '/points/ranking.php?limit=20' });
      const ranking = response.data.ranking || [];
      this.setData({
        ranking,
        myRanking: response.data.me || null,
        rankingState: viewState.readState(ranking.length ? 'ready' : 'empty'),
      });
    } catch (error) {
      this.setData({ rankingState: viewState.fromError(error, '积分排行加载失败', 'loadRanking') });
    }
  },

  async loadRecords() {
    this.setData({ recordsState: viewState.readState('loading') });
    try {
      const response = await app.request({ url: '/points/records.php?page=1&page_size=50' });
      const records = response.data.list || [];
      this.setData({
        records,
        recordsState: viewState.readState(records.length ? 'ready' : 'empty'),
      });
    } catch (error) {
      this.setData({ recordsState: viewState.fromError(error, '积分记录加载失败', 'loadRecords') });
    }
  },

  async reloadMall() {
    this.setData({ mallState: viewState.readState('loading') });
    try {
      const response = await app.request({ url: '/points/exchange.php' });
      const mallItems = response.data.items || [];
      this.setData({
        mallItems,
        mallState: viewState.readState(mallItems.length ? 'ready' : 'empty'),
      });
    } catch (error) {
      this.setData({ mallState: viewState.fromError(error, '积分商城加载失败', 'reloadMall') });
    }
  },

  retryOfflineReads() {
    if (this.data.overviewState.status === 'offline') this.reloadOverview();
    if (this.data.rankingState.status === 'offline') this.loadRanking();
    if (this.data.recordsState.status === 'offline') this.loadRecords();
    if (this.data.mallState.status === 'offline') this.reloadMall();
  },

  openExchange(e) {
    const item = this.data.mallItems.find(({ id }) => String(id) === String(e.currentTarget.dataset.id));
    if (!item) return;
    this.setData({ exchangeItem: item, writeState: viewState.writeState('idle') });
  },

  closeExchange() {
    if (this.data.writeState.status !== 'submitting') {
      this.setData({ exchangeItem: null, pendingOperation: null, writeState: viewState.writeState('idle') });
    }
  },

  onExchangeInput(e) {
    this.setData({ [`exchangeForm.${e.currentTarget.dataset.field}`]: e.detail.value });
  },

  submitExchange() {
    if (!this.data.exchangeItem || this.data.writeState.status === 'submitting') return;
    const operation = {
      type: 'exchange',
      data: { item_id: this.data.exchangeItem.id, ...this.data.exchangeForm },
      idempotencyKey: api.createIdempotencyKey('points_exchange'),
    };
    this.runWrite(operation);
  },

  submitCheckin() {
    if (this.data.writeState.status === 'submitting' || (this.data.overview && this.data.overview.today_checked)) return;
    this.runWrite({ type: 'checkin', data: {}, idempotencyKey: api.createIdempotencyKey('daily_checkin') });
  },

  async runWrite(operation) {
    this.setData({ pendingOperation: operation, writeState: viewState.writeState('submitting', '正在提交...') });
    try {
      const isExchange = operation.type === 'exchange';
      const response = await app.request({
        url: isExchange ? '/points/exchange.php?action=exchange' : '/points/checkin.php',
        method: 'POST',
        data: operation.data,
        idempotencyKey: operation.idempotencyKey,
      });
      this.setData({
        writeState: viewState.writeState('success', response.message || (isExchange ? '兑换成功' : '签到成功')),
        pendingOperation: null,
      });
      await Promise.all([this.reloadOverview(), isExchange ? this.reloadMall() : Promise.resolve()]);
      if (isExchange) this.setData({ exchangeItem: null, exchangeForm: { receiver_name: '', receiver_phone: '', receiver_address: '' } });
      wx.showToast({ title: response.message || '操作成功', icon: 'success' });
    } catch (error) {
      this.setData({ writeState: viewState.fromError(error, '提交失败，请重试', operation.type === 'exchange' ? 'retryExchange' : 'retryCheckin') });
    }
  },

  retryExchange() {
    if (this.data.pendingOperation && this.data.pendingOperation.type === 'exchange') this.runWrite(this.data.pendingOperation);
  },

  retryCheckin() {
    if (this.data.pendingOperation && this.data.pendingOperation.type === 'checkin') this.runWrite(this.data.pendingOperation);
  },
});
