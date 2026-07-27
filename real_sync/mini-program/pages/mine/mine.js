const app = getApp();

Page({
  data: {
    userInfo: null,
    avatarText: '用',
    profile: null,
    corrections: [],
    profileLoading: false,
    showPasswordModal: false,
    showCorrectionModal: false,
    passwordForm: { oldPassword: '', newPassword: '', confirmPassword: '' },
    correctionFields: [
      { label: '姓名', value: 'name' },
      { label: '手机号', value: 'phone' },
      { label: '门店 ID', value: 'store_id' },
      { label: '主岗位 ID', value: 'primary_position_id' },
      { label: '入职日期', value: 'entry_date' }
    ],
    correctionFieldIndex: 0,
    correctionValue: '',
    correctionReason: '',
    formBusy: false,
  },

  onLoad() {
    this.syncUserInfo();
  },

  onShow() {
    this.syncUserInfo();
    this.loadProfile();
  },

  async loadProfile() {
    if (!app.globalData.userInfo || this.data.profileLoading) return;
    this.setData({ profileLoading: true });
    try {
      const [profileRes, correctionRes] = await Promise.all([
        app.request({ url: '/staff/profile.php' }),
        app.request({ url: '/staff/profile-corrections.php' })
      ]);
      this.setData({ profile: profileRes.data.item || null, corrections: correctionRes.data.list || [] });
    } catch (err) {
      wx.showToast({ title: err.message || '档案加载失败', icon: 'none' });
    } finally {
      this.setData({ profileLoading: false });
    }
  },

  showPassword() {
    this.setData({ showPasswordModal: true, passwordForm: { oldPassword: '', newPassword: '', confirmPassword: '' } });
  },

  closePassword() {
    if (!this.data.formBusy) this.setData({ showPasswordModal: false });
  },

  onPasswordInput(e) {
    const field = e.currentTarget.dataset.field;
    this.setData({ [`passwordForm.${field}`]: e.detail.value });
  },

  async submitPassword() {
    if (this.data.formBusy) return;
    const form = this.data.passwordForm;
    if (!form.oldPassword) return wx.showToast({ title: '请输入旧密码', icon: 'none' });
    if (form.newPassword.length < 10) return wx.showToast({ title: '新密码至少 10 位', icon: 'none' });
    if (!/[a-z]/.test(form.newPassword) || !/[A-Z]/.test(form.newPassword) || !/\d/.test(form.newPassword) || !/[^A-Za-z0-9]/.test(form.newPassword)) return wx.showToast({ title: '需包含大小写字母、数字和特殊字符', icon: 'none' });
    if (form.newPassword !== form.confirmPassword) return wx.showToast({ title: '两次新密码不一致', icon: 'none' });
    this.setData({ formBusy: true });
    try {
      const res = await app.request({ url: '/auth-change-password.php', method: 'POST', data: { old_password: form.oldPassword, new_password: form.newPassword } });
      if (res.data && res.data.token) app.login(res.data.token, app.globalData.userInfo);
      this.setData({ showPasswordModal: false });
      wx.showToast({ title: '密码修改成功', icon: 'success' });
    } catch (err) {
      wx.showToast({ title: err.message || '密码修改失败', icon: 'none' });
    } finally {
      this.setData({ formBusy: false });
    }
  },

  showCorrection() {
    this.setData({ showCorrectionModal: true, correctionFieldIndex: 0, correctionValue: '', correctionReason: '' });
  },

  closeCorrection() {
    if (!this.data.formBusy) this.setData({ showCorrectionModal: false });
  },

  onCorrectionFieldChange(e) {
    this.setData({ correctionFieldIndex: Number(e.detail.value), correctionValue: '' });
  },

  onCorrectionValueInput(e) {
    this.setData({ correctionValue: e.detail.value });
  },

  onCorrectionReasonInput(e) {
    this.setData({ correctionReason: e.detail.value });
  },

  async submitCorrection() {
    if (this.data.formBusy) return;
    const field = this.data.correctionFields[this.data.correctionFieldIndex];
    if (!this.data.correctionValue.trim()) return wx.showToast({ title: '请输入期望更正值', icon: 'none' });
    if (!this.data.correctionReason.trim()) return wx.showToast({ title: '请输入申请原因', icon: 'none' });
    this.setData({ formBusy: true });
    try {
      await app.request({ url: '/staff/profile-corrections.php', method: 'POST', data: { changes: { [field.value]: this.data.correctionValue.trim() }, request_reason: this.data.correctionReason.trim() } });
      this.setData({ showCorrectionModal: false });
      await this.loadProfile();
      wx.showToast({ title: '更正申请已提交', icon: 'success' });
    } catch (err) {
      wx.showToast({ title: err.message || '申请提交失败', icon: 'none' });
    } finally {
      this.setData({ formBusy: false });
    }
  },

  syncUserInfo() {
    const userInfo = app.globalData.userInfo || null;
    const displayName = userInfo && (userInfo.display_name || userInfo.username);
    this.setData({
      userInfo,
      avatarText: displayName ? String(displayName).slice(0, 1) : '用'
    });
  },

  showComingSoon() {
    wx.showToast({
      title: '功能开发中',
      icon: 'none'
    });
  },

  goToWorkload() {
    wx.navigateTo({ url: '/pages/workload/index' });
  },

  goToNotifications() {
    wx.navigateTo({ url: '/pages/notifications/list' });
  },

  goToReminderSettings() {
    wx.navigateTo({ url: '/pages/reminder/settings' });
  },

  clearCache() {
    wx.showModal({
      title: '清除缓存',
      content: '确定要清除本地缓存吗？',
      success: (res) => {
        if (res.confirm) {
          const keepKeys = ['token', 'jwt_token', 'userInfo', 'user_info', 'device_id'];
          try {
            const info = wx.getStorageInfoSync();
            info.keys.forEach(key => {
              if (!keepKeys.includes(key)) {
                wx.removeStorageSync(key);
              }
            });
          } catch (e) {
            console.error('清除缓存失败:', e);
          }
          wx.showToast({
            title: '清除成功',
            icon: 'success'
          });
        }
      }
    });
  },

  checkUpdate() {
    wx.showToast({
      title: '已是最新版本',
      icon: 'success'
    });
  },

  logout() {
    wx.showModal({
      title: '退出登录',
      content: '确定要退出登录吗？',
      success: (res) => {
        if (res.confirm) {
          app.logout();
          wx.redirectTo({
            url: '/pages/login/login'
          });
        }
      }
    });
  }
});
