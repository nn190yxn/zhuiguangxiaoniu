const app = getApp();

Page({
  data: {
    examId: null,
    sourceExamId: null,
    selectedExamId: null,
    paperCode: 'A',
    exam: {},
    questions: [],
    questionNavList: [],
    currentIndex: 0,
    answers: {},
    textAnswer: '',
    timerText: '00:00',
    startTime: null,
    baseDuration: 0,
    timerInterval: null,
    submitted: false,
    result: {},
    canUseVoice: false,
    isRecording: false,
    currentQuestionTypeName: '',
    judgeVSelected: false,
    judgeXSelected: false
  },

  questionTypeNames: { 1: '单选题', 2: '多选题', 3: '判断题', 4: '问答/情景题' },
  optionLetters: ['A', 'B', 'C', 'D', 'E', 'F'],

  onLoad(options) {
    if (options.id) {
      const sourceId = Number(options.id) || 0;
      this.setData({ examId: sourceId, sourceExamId: sourceId });
      this.initExamSession(sourceId);
    }

    // 检查录音权限
    const manager = wx.getRecorderManager ? wx.getRecorderManager() : null;
    if (manager) {
      this.recorderManager = manager;

      this.recorderManager.onStart(() => {
        this.setData({ isRecording: true });
      });

      this.recorderManager.onStop((res) => {
        this.setData({ isRecording: false });
        this.uploadVoice(res.tempFilePath);
      });

      this.recorderManager.onError(() => {
        this.setData({ isRecording: false });
        wx.showToast({ title: '语音识别失败', icon: 'none' });
      });
    }
  },

  onUnload() {
    this.flushAutoSave();
    if (this.data.timerInterval) {
      clearInterval(this.data.timerInterval);
    }
    if (this.recorderManager && this.data.isRecording) {
      this.recorderManager.stop();
    }
  },

  onHide() {
    this.flushAutoSave();
  },

  async initExamSession(sourceExamId) {
    wx.showLoading({ title: '加载中...' });
    try {
      const resumeRes = await app.request({
        url: `${app.globalData.apiBase}/exam/resume.php?exam_type=course_exam&source_exam_id=${sourceExamId}`
      });

      if (resumeRes.code === 0 && resumeRes.data && resumeRes.data.has_progress && Number(resumeRes.data.selected_exam_id || 0) > 0) {
        const selectedId = Number(resumeRes.data.selected_exam_id);
        await this.loadExam(sourceExamId, selectedId, String(resumeRes.data.paper_code || 'A'), {
          answers: resumeRes.data.answers || {},
          duration: Number(resumeRes.data.duration || 0)
        });
        return;
      }

      const assignRes = await app.request({
        url: `${app.globalData.apiBase}/exam/index.php?action=assign&id=${sourceExamId}`
      });
      if (assignRes.code !== 0 || !assignRes.data || !assignRes.data.selected_exam_id) {
        wx.showToast({ title: assignRes.message || '分配试卷失败', icon: 'none' });
        return;
      }

      await this.loadExam(sourceExamId, Number(assignRes.data.selected_exam_id), String(assignRes.data.paper_code || 'A'), null);
    } catch (err) {
      console.error('初始化考试会话失败:', err);
      wx.showToast({ title: '加载失败', icon: 'none' });
    } finally {
      wx.hideLoading();
    }
  },

  async loadExam(sourceExamId, selectedExamId, paperCode, resumePayload) {
    try {
      const [detailRes, questionsRes] = await Promise.all([
        app.request({ url: `${app.globalData.apiBase}/exam/index.php?action=detail&id=${selectedExamId}` }),
        app.request({ url: `${app.globalData.apiBase}/exam/index.php?action=questions&id=${selectedExamId}` })
      ]);

      if (detailRes.code === 0 && questionsRes.code === 0) {
        const questions = questionsRes.data.questions || [];
        const exam = detailRes.data.exam;

        // 预处理题目数据
        const processedQuestions = questions.map(q => {
          if (q.question_type === 1 || q.question_type === 2) {
            const options = q.options || [];
            return {
              ...q,
              optionList: options.map((opt, i) => ({
                letter: this.optionLetters[i],
                text: opt,
                isSelected: false
              }))
            };
          }
          return q;
        });

        // 构建题号导航列表
        const questionNavList = questions.map(() => ({ isAnswered: false }));

        this.setData({
          sourceExamId: sourceExamId,
          selectedExamId: selectedExamId,
          paperCode: paperCode,
          exam: exam,
          questions: processedQuestions,
          questionNavList: questionNavList,
          canUseVoice: !!this.recorderManager
        });

        if (resumePayload && typeof resumePayload === 'object') {
          this.restoreProgress(resumePayload.answers || {}, Number(resumePayload.duration || 0));
        }

        // 加载第一题的UI状态
        this.updateQuestionUI(0);

        if (questions.length > 0) {
          const baseDuration = resumePayload ? Number(resumePayload.duration || 0) : 0;
          this.startTimer(baseDuration);
        }
      } else {
        wx.showToast({ title: detailRes.message || '加载失败', icon: 'none' });
      }
    } catch (err) {
      console.error('加载失败:', err);
      wx.showToast({ title: '加载失败', icon: 'none' });
    }
  },

  restoreProgress(answers, duration) {
    const safeAnswers = (answers && typeof answers === 'object') ? { ...answers } : {};
    delete safeAnswers.__meta;

    const nav = this.data.questions.map(q => {
      const value = safeAnswers[q.id];
      const answered = Array.isArray(value)
        ? value.length > 0
        : (typeof value === 'string' ? value.trim().length > 0 : !!value);
      return { isAnswered: answered };
    });

    this.setData({
      answers: safeAnswers,
      questionNavList: nav,
      baseDuration: Math.max(0, Number(duration || 0))
    });
  },

  startTimer(initialElapsed = 0) {
    const startTime = Date.now();
    this.setData({ startTime: startTime, baseDuration: Math.max(0, Number(initialElapsed || 0)) });

    const interval = setInterval(() => {
      const elapsed = Math.floor((Date.now() - this.data.startTime) / 1000) + (Number(this.data.baseDuration) || 0);
      const mins = Math.floor(elapsed / 60).toString().padStart(2, '0');
      const secs = (elapsed % 60).toString().padStart(2, '0');
      this.setData({ timerText: `${mins}:${secs}` });

      if (this.data.exam.duration && elapsed >= this.data.exam.duration * 60) {
        clearInterval(interval);
        this.setData({ timerInterval: null });
        wx.showToast({ title: '时间到，自动提交', icon: 'none' });
        this.submitExam();
      }
    }, 1000);

    this.setData({ timerInterval: interval });
  },

  updateQuestionUI(index) {
    const questions = this.data.questions;
    const q = questions[index] || {};
    const qType = q.question_type;

    const updateData = {
      currentIndex: index,
      currentQuestion: q,
      currentQuestionTypeName: this.questionTypeNames[qType] || '',
      judgeVSelected: false,
      judgeXSelected: false
    };

    // 加载当前题的答案状态
    const qid = q.id;
    const savedAnswer = this.data.answers[qid];

    if (qType === 1 || qType === 2) {
      // 更新选项选中状态
      const updatedQuestions = [...questions];
      if (updatedQuestions[index] && updatedQuestions[index].optionList) {
        updatedQuestions[index].optionList = updatedQuestions[index].optionList.map(opt => ({
          ...opt,
          isSelected: savedAnswer && (
            qType === 2 ? (Array.isArray(savedAnswer) && savedAnswer.includes(opt.letter)) : savedAnswer === opt.letter
          )
        }));
      }
      updateData.questions = updatedQuestions;
    } else if (qType === 3) {
      updateData.judgeVSelected = savedAnswer === 'V';
      updateData.judgeXSelected = savedAnswer === 'X';
    } else if (qType === 4) {
      updateData.textAnswer = savedAnswer || '';
    }

    this.setData(updateData);
  },

  isOptionSelected(letter) {
    const q = this.data.questions[this.data.currentIndex];
    if (!q || !q.optionList) return false;
    const opt = q.optionList.find(o => o.letter === letter);
    return opt ? opt.isSelected : false;
  },

  selectOption(e) {
    const letter = e.currentTarget.dataset.letter;
    const index = this.data.currentIndex;
    const q = this.data.questions[index];
    if (!q) return;

    const qid = q.id;
    const qType = q.question_type;

    const updatedQuestions = [...this.data.questions];
    const optionList = [...updatedQuestions[index].optionList];

    if (qType === 2) {
      // 多选
      let current = this.data.answers[qid] ? [...this.data.answers[qid]] : [];
      const idx = current.indexOf(letter);
      if (idx > -1) {
        current.splice(idx, 1);
      } else {
        current.push(letter);
        current.sort();
      }

      const newOptionList = optionList.map(opt => ({
        ...opt,
        isSelected: current.includes(opt.letter)
      }));

      updatedQuestions[index] = { ...updatedQuestions[index], optionList: newOptionList };

      const navUpdate = {};
      navUpdate[`questionNavList[${index}].isAnswered`] = current.length > 0;

      this.setData({
        questions: updatedQuestions,
        [`answers.${qid}`]: current,
        ...navUpdate
      });
      this.scheduleAutoSave();
    } else {
      // 单选
      const newOptionList = optionList.map(opt => ({
        ...opt,
        isSelected: opt.letter === letter
      }));

      updatedQuestions[index] = { ...updatedQuestions[index], optionList: newOptionList };

      const navUpdate = {};
      navUpdate[`questionNavList[${index}].isAnswered`] = true;

      this.setData({
        questions: updatedQuestions,
        [`answers.${qid}`]: letter,
        ...navUpdate
      });
      this.scheduleAutoSave();
    }
  },

  selectJudge(e) {
    const value = e.currentTarget.dataset.value;
    const index = this.data.currentIndex;
    const q = this.data.questions[index];
    if (!q) return;

    const qid = q.id;

    const navUpdate = {};
    navUpdate[`questionNavList[${index}].isAnswered`] = true;

    this.setData({
      judgeVSelected: value === 'V',
      judgeXSelected: value === 'X',
      [`answers.${qid}`]: value,
      ...navUpdate
    });
    this.scheduleAutoSave();
  },

  onTextInput(e) {
    const value = e.detail.value;
    const index = this.data.currentIndex;
    const q = this.data.questions[index];
    if (!q) return;

    const qid = q.id;

    const navUpdate = {};
    navUpdate[`questionNavList[${index}].isAnswered`] = value.trim().length > 0;

    this.setData({
      textAnswer: value,
      [`answers.${qid}`]: value,
      ...navUpdate
    });
    this.scheduleAutoSave();
  },

  startVoiceInput() {
    if (this.data.isRecording) {
      this.recorderManager.stop();
      return;
    }

    wx.getSetting({
      success: (res) => {
        if (res.authSetting['scope.record'] === false) {
          wx.showModal({
            title: '需要录音权限',
            content: '请在设置中开启麦克风权限以使用语音输入',
            confirmText: '去设置',
            success: (modalRes) => {
              if (modalRes.confirm) {
                wx.openSetting();
              }
            }
          });
          return;
        }

        this.recorderManager.start({
          duration: 60000,
          sampleRate: 16000,
          numberOfChannels: 1,
          encodeBitRate: 96000,
          format: 'mp3'
        });
        wx.showToast({ title: '请开始说话', icon: 'none' });
      }
    });
  },

  async uploadVoice(filePath) {
    wx.showLoading({ title: '识别中...' });

    try {
      const res = await app.uploadFile({
        url: '/drill/voice-to-text.php',
        filePath,
        name: 'audio',
        timeout: 60000,
      });

      if (res.code === 0 && res.data && res.data.text) {
        const index = this.data.currentIndex;
        const q = this.data.questions[index];
        if (!q) return;

        const qid = q.id;
        const currentText = this.data.textAnswer || '';
        const newText = currentText + res.data.text;

        const navUpdate = {};
        navUpdate[`questionNavList[${index}].isAnswered`] = newText.trim().length > 0;

        this.setData({
          textAnswer: newText,
          [`answers.${qid}`]: newText,
          ...navUpdate
        });
        this.scheduleAutoSave();
        wx.showToast({ title: '识别成功', icon: 'success' });
      } else {
        wx.showToast({ title: res.message || '识别失败', icon: 'none' });
      }
    } catch (err) {
      console.error('语音识别失败:', err);
      wx.showToast({ title: '识别失败', icon: 'none' });
    } finally {
      wx.hideLoading();
    }
  },

  prevQuestion() {
    if (this.data.currentIndex > 0) {
      this.saveCurrentAnswer();
      this.updateQuestionUI(this.data.currentIndex - 1);
    }
  },

  nextQuestion() {
    this.saveCurrentAnswer();

    if (this.data.currentIndex < this.data.questions.length - 1) {
      this.updateQuestionUI(this.data.currentIndex + 1);
    } else {
      this.confirmSubmit();
    }
  },

  goToQuestion(e) {
    const index = e.currentTarget.dataset.index;
    if (index === this.data.currentIndex) return;

    this.saveCurrentAnswer();
    this.updateQuestionUI(index);
  },

  saveCurrentAnswer() {
    const q = this.data.questions[this.data.currentIndex];
    if (!q) return;

    if (q.question_type === 4) {
      const pages = getCurrentPages();
      const currentPage = pages[pages.length - 1];

      const query = wx.createSelectorQuery().in(currentPage);
      query.select('#answerText').fields({ value: true }, (res) => {
        if (res && res.value) {
          const qid = q.id;
          const navUpdate = {};
          navUpdate[`questionNavList[${this.data.currentIndex}].isAnswered`] = res.value.trim().length > 0;

          this.setData({
            textAnswer: res.value,
            [`answers.${qid}`]: res.value,
            ...navUpdate
          });
          this.scheduleAutoSave();
        }
      }).exec();
    }
  },

  scheduleAutoSave() {
    if (this.data.submitted) {
      return;
    }
    if (this.autoSaveTimer) {
      clearTimeout(this.autoSaveTimer);
    }
    this.autoSaveTimer = setTimeout(() => {
      this.autoSaveTimer = null;
      this.saveProgress(false);
    }, 800);
  },

  async flushAutoSave() {
    if (this.autoSaveTimer) {
      clearTimeout(this.autoSaveTimer);
      this.autoSaveTimer = null;
    }
    await this.saveProgress(true);
  },

  async saveProgress(force = false) {
    if (this.data.submitted || !this.data.sourceExamId || !this.data.selectedExamId) {
      return;
    }
    const elapsed = this.data.startTime
      ? Math.floor((Date.now() - this.data.startTime) / 1000) + (Number(this.data.baseDuration) || 0)
      : (Number(this.data.baseDuration) || 0);

    const hasAnyAnswer = Object.keys(this.data.answers || {}).some(key => {
      if (key === '__meta') return false;
      const value = this.data.answers[key];
      if (Array.isArray(value)) return value.length > 0;
      if (typeof value === 'string') return value.trim().length > 0;
      return !!value;
    });
    if (!force && !hasAnyAnswer) {
      return;
    }

    try {
      await app.request({
        url: `${app.globalData.apiBase}/exam/save.php`,
        method: 'POST',
        data: {
          exam_type: 'course_exam',
          source_exam_id: this.data.sourceExamId,
          selected_exam_id: this.data.selectedExamId,
          paper_code: this.data.paperCode || 'A',
          answers: this.data.answers,
          duration: elapsed
        }
      });
    } catch (err) {
      console.warn('自动保存失败:', err);
    }
  },

  confirmSubmit() {
    const answeredCount = this.data.questionNavList.filter(n => n.isAnswered).length;
    const total = this.data.questions.length;
    const unanswered = total - answeredCount;

    if (unanswered > 0) {
      wx.showModal({
        title: '提示',
        content: `您还有 ${unanswered} 道题未作答，确定要提交吗？`,
        success: (res) => {
          if (res.confirm) {
            this.submitExam();
          }
        }
      });
    } else {
      wx.showModal({
        title: '确认提交',
        content: '确定提交试卷吗？提交后不可修改。',
        success: (res) => {
          if (res.confirm) {
            this.submitExam();
          }
        }
      });
    }
  },

  async submitExam() {
    if (this.data.timerInterval) {
      clearInterval(this.data.timerInterval);
    }

    if (this.autoSaveTimer) {
      clearTimeout(this.autoSaveTimer);
      this.autoSaveTimer = null;
    }

    this.saveCurrentAnswer();
    const timeSpent = this.data.startTime ? Math.floor((Date.now() - this.data.startTime) / 1000) + (Number(this.data.baseDuration) || 0) : (Number(this.data.baseDuration) || 0);

    wx.showLoading({ title: '提交中...' });

    try {
      const res = await app.request({
        url: `${app.globalData.apiBase}/exam/submit.php`,
        method: 'POST',
        data: {
          exam_id: this.data.selectedExamId,
          source_exam_id: this.data.sourceExamId,
          selected_exam_id: this.data.selectedExamId,
          paper_code: this.data.paperCode || 'A',
          answers: this.data.answers,
          time_spent: timeSpent
        }
      });

      if (res.code === 0) {
        this.setData({
          submitted: true,
          result: {
            ...res.data,
            max_score: res.data.max_score || this.data.exam.total_score || 100
          }
        });
      } else {
        wx.showToast({ title: res.message, icon: 'none' });
      }
    } catch (err) {
      console.error('提交失败:', err);
      wx.showToast({ title: '提交失败', icon: 'none' });
    } finally {
      wx.hideLoading();
    }
  },

  goBack() {
    wx.navigateBack();
  }
});
