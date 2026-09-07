(function () {
  let examAnswers = {};
  let examSubmitted = false;
  let submitting = false;
  let pendingSubmission = null;
  let savedResult = null;
  let startedAt = Date.now();
  let paper = null;
  let restored = false;
  let loading = false;

  function draftKey() {
    const user = window.AppAuth && AppAuth.getUserInfo();
    const userId = user && (user.id || user.ID || user.user_id);
    if (!userId) throw new Error('登录身份尚未就绪，请重新登录后重试');
    return 'training-exam:' + userId + ':' + getExamData().examId;
  }

  function persistDraft() {
    sessionStorage.setItem(draftKey(), JSON.stringify({ answers: examAnswers, pending: pendingSubmission, result: savedResult, startedAt, paper }));
  }

  function getExamData() {
    if (!window.EXAM_DATA) {
      throw new Error('EXAM_DATA 未定义');
    }
    return window.EXAM_DATA;
  }

  async function fetchExam(action) {
    if (!window.AppAuth) throw new Error('登录组件尚未就绪，请稍后重试');
    const response = await AppAuth.authFetch('/api/exam/index.php?action=' + action + '&id=' + encodeURIComponent(getExamData().examId), { cache: 'no-store' });
    const body = await response.json();
    if (!response.ok || body.code !== 0 || !body.data) throw new Error(body.message || '试卷加载失败');
    return body.data;
  }

  function normalizeQuestions(questions) {
    if (!Array.isArray(questions) || !questions.length) throw new Error('试卷暂无题目，请联系管理员');
    const ids = new Set();
    return questions.map(q => {
      const type = { 1: 'choice', 3: 'judge', 4: 'text' }[Number(q.question_type)];
      const id = Number(q.id);
      if (!type || !Number.isSafeInteger(id) || id <= 0 || ids.has(id)
        || typeof q.content !== 'string' || !q.content.trim() || q.score == null || !Number.isFinite(Number(q.score)) || Number(q.score) < 0
        || (type === 'choice' && (!Array.isArray(q.options) || !q.options.length || q.options.length > 26 || q.options.some(opt => typeof opt !== 'string' || !opt.trim())))) {
        throw new Error('试卷题型或内容不支持，请联系管理员');
      }
      ids.add(id);
      return { id, type, content: q.content, score: Number(q.score), options: type === 'choice' ? q.options : [] };
    });
  }

  async function renderExam() {
    const container = document.getElementById('examContainer');
    if (!container || loading || submitting) return;
    if (!restored) {
      try {
        const draft = JSON.parse(sessionStorage.getItem(draftKey()) || 'null');
        if (draft) {
          pendingSubmission = draft.pending || null; savedResult = draft.result || null;
          paper = draft.paper || null;
          examAnswers = paper || pendingSubmission ? draft.answers || {} : {};
        }
        if (draft && Number.isFinite(draft.startedAt)) startedAt = draft.startedAt;
      } catch (error) { /* In-memory answers remain available when storage is unavailable. */ }
      restored = true;
    }
    if (savedResult) { examSubmitted = true; showSavedResult(savedResult); return; }
    if (!paper && pendingSubmission) {
      container.innerHTML = '<p>上次提交结果尚未确认，请重试原提交。</p><button id="submitBtn" class="btn-submit-exam" onclick="submitExam()">重试提交</button>';
      return;
    }
    if (!paper) {
      loading = true;
      container.innerHTML = '<p>正在加载试卷...</p>';
      try {
        const [details, data] = await Promise.all([fetchExam('detail'), fetchExam('questions')]);
        const exam = details.exam;
        if (!exam || Number(exam.id) !== Number(getExamData().examId) || typeof exam.title !== 'string'
          || [exam.duration, exam.total_score, exam.pass_score].some(value => value == null || !Number.isFinite(Number(value)) || Number(value) < 0)) {
          throw new Error('试卷信息无效，请联系管理员');
        }
        paper = { examId: Number(exam.id), title: exam.title, duration: Number(exam.duration), totalScore: Number(exam.total_score),
          passScore: Number(exam.pass_score), questions: normalizeQuestions(data.questions) };
        examAnswers = {};
        persistDraft();
      } catch (error) {
        paper = null;
        container.innerHTML = '<p>' + escapeHtmlExam(error.message) + '</p><button class="btn-submit-exam" onclick="renderExam()">重试加载</button>';
        return;
      } finally { loading = false; }
    }
    const examData = paper;

    let html = '';
    html += '<div class="exam-intro"><h2>' + escapeHtmlExam(examData.title) + '</h2>';
    html += '<div class="exam-meta-row"><span>' + examData.duration + '分钟</span><span>满分' + examData.totalScore + '分</span><span>' + examData.passScore + '分及格</span></div></div>';

    const sections = [
      { type: 'choice', filter: q => q.type === 'choice' },
      { type: 'judge', filter: q => q.type === 'judge' },
      { type: 'text', filter: q => q.type === 'text' }
    ];

    sections.forEach(section => {
      const questions = examData.questions.filter(section.filter);
      if (questions.length === 0) return;

      const totalQ = questions.length;
      const totalScore = questions.reduce((sum, q) => sum + q.score, 0);
      const typeName = section.type === 'choice' ? '选择题' : section.type === 'judge' ? '判断题' : '问答/情景题';

      html += '<div class="exam-section"><div class="exam-section-title">' + typeName + '（共' + totalQ + '题，共' + totalScore + '分）</div>';
      questions.forEach((q, idx) => {
        html += '<div class="question-block" data-qid="' + q.id + '">';
        html += '<div class="q-header"><span class="q-num">' + (idx + 1) + '</span>';
        html += '<span class="q-type">' + typeName + '</span>';
        html += '<span class="q-score">' + q.score + '分</span></div>';
        html += '<div class="q-content">' + escapeHtmlExam(q.content).replace(/\n/g, '<br>') + '</div>';

        if (q.type === 'choice') {
          const letters = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
          html += '<div class="options-list">';
          q.options.forEach((opt, i) => {
            html += '<div class="option-item" onclick="selectOption(' + q.id + ', \'" + letters[i] + "\')" data-opt="' + letters[i] + '">';
            html += '<span class="opt-letter">' + letters[i] + '</span><span class="opt-text">' + escapeHtmlExam(opt) + '</span></div>';
          });
          html += '</div>';
        } else if (q.type === 'judge') {
          html += '<div class="judge-options">';
          html += '<div class="judge-option" onclick="selectJudge(' + q.id + ', \'V\')" data-val="V">✓ 正确</div>';
          html += '<div class="judge-option" onclick="selectJudge(' + q.id + ', \'X\')" data-val="X">✗ 错误</div>';
          html += '</div>';
        } else {
          html += '<textarea class="answer-textarea" id="answer-' + q.id + '" placeholder="请输入您的答案..." oninput="saveTextAnswer(' + q.id + ')"></textarea>';
        }

        html += '</div>';
      });
      html += '</div>';
    });

    html += '<div class="exam-submit-bar"><button class="btn-submit-exam" id="submitBtn" onclick="submitExam()">提交试卷</button></div>';
    container.innerHTML = html;
    examData.questions.forEach(q => {
      const value = examAnswers[q.id];
      if (q.type === 'text') { const input = document.getElementById('answer-' + q.id); if (input) input.value = value || ''; }
      else if (value) {
        const block = document.querySelector('.question-block[data-qid="' + q.id + '"]');
        if (block) block.querySelectorAll('.option-item, .judge-option').forEach(el => el.classList.toggle('selected', (el.dataset.opt || el.dataset.val) === value));
      }
    });
    container.querySelectorAll('textarea').forEach(input => { input.disabled = !!pendingSubmission; });
  }

  function selectOption(qid, value) {
    if (!paper || loading || examSubmitted || submitting || pendingSubmission) return;
    examAnswers[qid] = value;
    try { persistDraft(); } catch (error) {}
    const block = document.querySelector('.question-block[data-qid="' + qid + '"]');
    if (!block) return;
    block.querySelectorAll('.option-item').forEach(el => el.classList.toggle('selected', el.dataset.opt === value));
  }

  function selectJudge(qid, value) {
    if (!paper || loading || examSubmitted || submitting || pendingSubmission) return;
    examAnswers[qid] = value;
    try { persistDraft(); } catch (error) {}
    const block = document.querySelector('.question-block[data-qid="' + qid + '"]');
    if (!block) return;
    block.querySelectorAll('.judge-option').forEach(el => el.classList.toggle('selected', el.dataset.val === value));
  }

  function saveTextAnswer(qid) {
    if (!paper || loading || examSubmitted || submitting || pendingSubmission) return;
    const textarea = document.getElementById('answer-' + qid);
    if (textarea) examAnswers[qid] = textarea.value;
    try { persistDraft(); } catch (error) {}
  }

  async function submitExam() {
    if (examSubmitted || submitting || loading || (!paper && !pendingSubmission)) return;
    const examData = paper || { questions: [] };
    const unanswered = examData.questions.filter(q => !examAnswers[q.id] || (q.type === 'text' && !examAnswers[q.id].trim()));
    if (unanswered.length > 0 && !confirm('您还有 ' + unanswered.length + ' 道题未作答，确定要提交吗？')) return;
    if (!confirm('确认提交试卷？提交后不可修改。')) return;

    submitting = true;
    const submitBtn = document.getElementById('submitBtn');
    if (submitBtn) {
      submitBtn.disabled = true;
      submitBtn.textContent = '提交中...';
    }

    examData.questions.filter(q => q.type === 'text').forEach(q => {
      const textarea = document.getElementById('answer-' + q.id);
      if (textarea && !examAnswers[q.id]) examAnswers[q.id] = textarea.value;
    });

    try {
      if (!window.AppAuth) throw new Error('登录组件尚未就绪，请稍后重试');
      if (!pendingSubmission) {
        const data = await fetchExam('questions');
        const questions = normalizeQuestions(data.questions);
        const matches = questions.length === examData.questions.length && questions.every(remote => {
          const local = examData.questions.find(q => Number(q.id) === Number(remote.id));
          return local && local.content === remote.content && local.score === remote.score
            && remote.type === local.type
            && (local.type !== 'choice' || JSON.stringify(local.options) === JSON.stringify(remote.options));
        });
        if (!matches) throw new Error('作答期间试卷已变更，请联系管理员核对；本次答案已保留');
        pendingSubmission = {
          key: crypto.randomUUID(),
          createdAt: Date.now(),
          body: { exam_id: examData.examId, source_exam_id: examData.examId, selected_exam_id: examData.examId,
            answers: { ...examAnswers }, time_spent: Math.floor((Date.now() - startedAt) / 1000) }
        };
      }
      // Persist the exact request before sending; uncertain outcomes must replay the same fingerprint.
      persistDraft();
      document.getElementById('examContainer').querySelectorAll('textarea').forEach(input => { input.disabled = true; });
      if (Date.now() - pendingSubmission.createdAt >= 86400000) throw new Error('提交确认已超过24小时，请联系管理员核对成绩后处理');
      const response = await AppAuth.authFetch('/api/exam/submit.php', {
        method: 'POST', headers: { 'Content-Type': 'application/json', 'Idempotency-Key': pendingSubmission.key },
        body: JSON.stringify(pendingSubmission.body)
      });
      const body = await response.json();
      if (response.headers.get('X-Exam-Submission-State') === 'rolled_back') {
        pendingSubmission = null;
        persistDraft();
      }
      if (!response.ok || body.code !== 0 || !body.data || !body.data.exam_record_id) throw new Error(body.message || '提交未确认，请重试');
      examSubmitted = true;
      savedResult = body.data;
      try { persistDraft(); } catch (error) {}
      showSavedResult(savedResult);
    } catch (error) {
      alert(error.message + (pendingSubmission ? '。答案已保留，请重试原提交。' : '。答案已保留。'));
    } finally {
      submitting = false;
      if (submitBtn) { submitBtn.disabled = examSubmitted; submitBtn.textContent = examSubmitted ? '已提交' : '重试提交'; }
      if (!examSubmitted) document.getElementById('examContainer').querySelectorAll('textarea').forEach(input => { input.disabled = !!pendingSubmission; });
      if (!paper && !pendingSubmission && !examSubmitted) await renderExam();
    }
  }

  function showSavedResult(result) {
      const rows = result.question_results || [];
      const details = rows.map(scored => {
        const question = paper && paper.questions.find(q => Number(q.id) === Number(scored.question_id));
        return { question: { content: question ? question.content : '题目 ' + scored.question_id,
          type: { 1: 'choice', 3: 'judge', 4: 'text' }[scored.question_type], score: scored.max_score },
          userAnswer: (pendingSubmission ? pendingSubmission.body.answers : examAnswers)[scored.question_id] || '',
          correctAnswer: scored.correct_answer, score: scored.earned_score, isCorrect: !!scored.is_correct };
      });
      showResult({ totalScore: result.score, maxScore: rows.reduce((sum, row) => sum + Number(row.max_score), 0), passScore: result.pass_score,
        isPassed: result.is_passed, details });
      const record = document.createElement('p');
      record.textContent = '成绩已保存，记录编号：' + result.exam_record_id;
      document.getElementById('examContainer').prepend(record);
      const restart = document.createElement('button');
      restart.type = 'button';
      restart.className = 'btn-submit-exam';
      restart.textContent = '重新考试';
      restart.onclick = restartExam;
      document.getElementById('examContainer').appendChild(restart);
  }

  async function restartExam() {
    // Only an acknowledged completed result permits discarding the old request.
    if (submitting || !examSubmitted || !savedResult || !savedResult.exam_record_id) return;
    if (!confirm('确认重新考试？将清空本次答案并重新计时，已保存的成绩保留。')) return;
    const nextStartedAt = Date.now();
    try {
      sessionStorage.setItem(draftKey(), JSON.stringify({ answers: {}, pending: null, result: null, startedAt: nextStartedAt }));
    } catch (error) {
      alert('无法保存新考试状态，请检查浏览器存储后重试');
      return;
    }
    examAnswers = {};
    pendingSubmission = null;
    savedResult = null;
    examSubmitted = false;
    paper = null;
    startedAt = nextStartedAt;
    await renderExam();
  }

  function showResult(result) {
    const container = document.getElementById('examContainer');
    if (!container) return;

    const statusClass = result.isPassed ? 'pass' : 'fail';
    const statusText = result.isPassed ? '恭喜通关！' : '未通过，请继续学习';
    let html = '<div class="exam-result"><div class="result-score-circle ' + statusClass + '"><div class="score-num">' + result.totalScore + '</div><div class="score-label">/ ' + result.maxScore + '</div></div>';
    html += '<div class="result-status ' + statusClass + '">' + statusText + '</div>';
    html += '<div class="result-detail">及格分 ' + result.passScore + ' 分 · ' + (result.isPassed ? '已达到及格线' : '未达到及格线，请复习后重考') + '</div></div>';
    html += '<div class="review-section"><h3>答题详情</h3>';

    result.details.forEach((d, idx) => {
      const qType = d.question.type === 'choice' ? '选择题' : d.question.type === 'judge' ? '判断题' : '问答/情景题';
      let answerHtml = '';

      if (d.question.type === 'choice') {
        answerHtml = '<span class="' + (d.isCorrect ? 'correct' : 'your') + '">你的答案：' + escapeHtmlExam(d.userAnswer || '未作答') + '</span> · <span class="correct">正确答案：' + escapeHtmlExam(d.correctAnswer == null ? '服务端未返回' : d.correctAnswer) + '</span>';
      } else if (d.question.type === 'judge') {
        const userText = d.userAnswer === 'V' ? '正确' : d.userAnswer === 'X' ? '错误' : '未作答';
        const correctText = d.correctAnswer == null ? '服务端未返回' : d.correctAnswer === 'V' ? '正确' : d.correctAnswer === 'X' ? '错误' : escapeHtmlExam(d.correctAnswer);
        answerHtml = '<span class="' + (d.isCorrect ? 'correct' : 'your') + '">你的答案：' + userText + '</span> · <span class="correct">正确答案：' + correctText + '</span>';
      } else {
        answerHtml = '<div style="margin-top:8px;padding:10px;background:#f9f7f4;border-radius:8px;font-size:13px;">你的回答：' + escapeHtmlExam(d.userAnswer) + '</div>';
      }

      const shortContent = d.question.content.length > 50 ? d.question.content.substring(0, 50) + '...' : d.question.content;
      html += '<div class="review-item ' + (d.isCorrect ? 'correct' : 'wrong') + '"><div class="review-q">[' + qType + '] ' + (idx + 1) + '. ' + escapeHtmlExam(shortContent) + '（' + d.score + '/' + d.question.score + '分）</div>';
      html += '<div class="review-answer">' + answerHtml + '</div></div>';
    });

    html += '</div>';
    container.innerHTML = html;
    window.scrollTo({ top: 0, behavior: 'smooth' });
  }

  window.renderExam = renderExam;
  window.selectOption = selectOption;
  window.selectJudge = selectJudge;
  window.saveTextAnswer = saveTextAnswer;
  window.submitExam = submitExam;
  window.restartExam = restartExam;
})();
