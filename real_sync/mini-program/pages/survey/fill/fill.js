const app = getApp();

Page({
  data: {
    survey: null,
    questions: [],
    campuses: [],
    selectedCampus: null,
    selectedCampusIndex: -1,
    answers: {},
    submitting: false,
    alreadySubmitted: false,
    surveyCode: '',
    showCampusPicker: false,
    campusPickerValue: [0],
    sections: []
  },

  onLoad(options) {
    const code = options.code || '';
    if (!code) {
      wx.showToast({ title: '无效的问卷链接', icon: 'error' });
      return;
    }
    this.setData({ surveyCode: code });
    this.loadSurvey(code);
  },

  async loadSurvey(code) {
    try {
      wx.showLoading({ title: '加载中...' });
      const res = await app.request({
        url: `${app.globalData.apiBase}/survey/detail.php?code=${code}`,
        method: 'GET'
      });

      const { survey, questions, already_submitted } = res.data;

      if (already_submitted) {
        this.setData({ alreadySubmitted: true });
        wx.hideLoading();
        return;
      }

      // 初始化答案
      const answers = {};
      questions.forEach(q => {
        if (q.question_type === 'checkbox') {
          answers[q.id] = [];
        } else {
          answers[q.id] = '';
        }
      });

      const sections = this.buildSections(questions, answers);

      const defaultCampusIndex = survey.require_campus && (survey.campuses || []).length > 0 ? 0 : -1;

      this.setData({
        survey,
        questions,
        sections,
        campuses: survey.campuses || [],
        answers,
        selectedCampusIndex: defaultCampusIndex,
        campusPickerValue: [Math.max(defaultCampusIndex, 0)],
        selectedCampus: defaultCampusIndex >= 0 ? survey.campuses[defaultCampusIndex] : null,
      });
      wx.hideLoading();
    } catch (e) {
      wx.hideLoading();
      wx.showToast({ title: e.message || '加载失败', icon: 'none' });
    }
  },

  buildSections(questions, answers) {
    const sectionMap = {};
    questions.forEach(q => {
      const section = q.section || '其他';
      if (!sectionMap[section]) sectionMap[section] = [];
      sectionMap[section].push(this.decorateQuestion(q, answers));
    });

    return Object.keys(sectionMap).map(key => ({
      title: key,
      questions: sectionMap[key]
    }));
  },

  decorateQuestion(question, answers) {
    const q = { ...question };
    const answer = answers[q.id];
    const options = Array.isArray(q.options) ? q.options : [];
    q.option_items = options.map(opt => ({
      value: opt,
      active: q.question_type === 'checkbox'
        ? Array.isArray(answer) && answer.indexOf(opt) >= 0
        : answer === opt,
    }));
    q.rating_items = [1, 2, 3, 4, 5].map(score => ({
      score,
      icon: Number(answer || 0) >= score ? '★' : '☆',
      label: ['', '很差', '较差', '一般', '较好', '很好'][score],
      active: Number(answer || 0) >= score,
    }));
    q.nps_items = [0, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10].map(score => ({
      score,
      active: Number(answer) === score,
    }));
    q.text_value = answer || '';
    q.text_length = String(q.text_value).length;
    return q;
  },

  refreshSections(answers) {
    this.setData({
      answers,
      sections: this.buildSections(this.data.questions, answers)
    });
  },

  // 校区选择
  onCampusChange(e) {
    const index = parseInt(e.detail.value);
    const campus = this.data.campuses[index];
    this.setData({
      selectedCampusIndex: index,
      campusPickerValue: [index],
      selectedCampus: campus
    });
  },

  showCampusPicker() {
    this.setData({ showCampusPicker: true });
  },

  hideCampusPicker() {
    this.setData({ showCampusPicker: false });
  },

  confirmCampus() {
    this.setData({ showCampusPicker: false });
  },

  // 单选/评分
  onRadioChange(e) {
    const questionId = parseInt(e.currentTarget.dataset.id);
    const value = e.currentTarget.dataset.value;
    const answers = { ...this.data.answers };
    answers[questionId] = value;
    this.refreshSections(answers);
  },

  // 多选
  onCheckboxChange(e) {
    const questionId = parseInt(e.currentTarget.dataset.id);
    const value = e.currentTarget.dataset.value;
    const answers = { ...this.data.answers };
    const currentValues = Array.isArray(answers[questionId]) ? [...answers[questionId]] : [];
    const index = currentValues.indexOf(value);
    if (index >= 0) {
      currentValues.splice(index, 1);
    } else {
      currentValues.push(value);
    }
    answers[questionId] = currentValues;
    this.refreshSections(answers);
  },

  // 文字输入
  onTextInput(e) {
    const questionId = parseInt(e.currentTarget.dataset.id);
    const value = e.detail.value;
    const answers = { ...this.data.answers };
    answers[questionId] = value;
    this.refreshSections(answers);
  },

  // 评分点击
  onRatingTap(e) {
    const questionId = parseInt(e.currentTarget.dataset.id);
    const score = parseInt(e.currentTarget.dataset.score);
    const answers = { ...this.data.answers };
    answers[questionId] = score;
    this.refreshSections(answers);
  },

  // 验证
  validate() {
    const { survey, answers, questions, selectedCampus } = this.data;

    if (survey.require_campus && !selectedCampus) {
      wx.showToast({ title: '请选择校区', icon: 'none' });
      return false;
    }

    for (const q of questions) {
      if (!q.is_required) continue;
      const ans = answers[q.id];
      if (q.question_type === 'checkbox') {
        if (!ans || ans.length === 0) {
          wx.showToast({ title: '请回答: ' + q.question_text, icon: 'none' });
          return false;
        }
      } else if (q.question_type === 'text') {
        if (!ans || !ans.trim()) {
          wx.showToast({ title: '请回答: ' + q.question_text, icon: 'none' });
          return false;
        }
      } else {
        if (!ans && ans !== 0) {
          wx.showToast({ title: '请回答: ' + q.question_text, icon: 'none' });
          return false;
        }
      }
    }
    return true;
  },

  // 提交
  async onSubmit() {
    if (!this.validate()) return;
    if (this.data.submitting) return;

    this.setData({ submitting: true });
    wx.showLoading({ title: '提交中...' });

    try {
      const { surveyCode, selectedCampus, answers, questions } = this.data;
      const userInfo = app.globalData.userInfo;

      const answerList = [];
      questions.forEach(q => {
        const ans = answers[q.id];
        if (ans === '' || ans === null || ans === undefined) return;

        const item = { question_id: q.id };
        if (q.question_type === 'checkbox') {
          item.answer_values = ans;
        } else if (q.question_type === 'rating' || q.question_type === 'nps') {
          item.rating_score = parseInt(ans);
        } else {
          item.answer_value = String(ans);
        }
        answerList.push(item);
      });

      await app.request({
        url: `${app.globalData.apiBase}/survey/submit.php`,
        method: 'POST',
        data: {
          code: surveyCode,
          campus_id: selectedCampus ? selectedCampus.id : 0,
          submitter_name: userInfo ? (userInfo.nickname || userInfo.real_name || '') : '',
          submitter_phone: userInfo ? (userInfo.phone || '') : '',
          answers: answerList
        }
      });

      wx.hideLoading();
      wx.redirectTo({
        url: `/pages/survey/result/result?title=${encodeURIComponent(this.data.survey.title)}`
      });
    } catch (e) {
      wx.hideLoading();
      wx.showToast({ title: e.message || '提交失败', icon: 'none' });
    } finally {
      this.setData({ submitting: false });
    }
  },

  goHome() {
    wx.switchTab({
      url: '/pages/index/index'
    });
  }
});
