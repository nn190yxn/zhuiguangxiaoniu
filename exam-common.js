(function () {
  let examAnswers = {};
  let examSubmitted = false;

  function getExamData() {
    if (!window.EXAM_DATA) {
      throw new Error('EXAM_DATA 未定义');
    }
    return window.EXAM_DATA;
  }

  function renderExam() {
    const examData = getExamData();
    const container = document.getElementById('examContainer');
    if (!container) return;

    let html = '';
    html += '<div class="exam-intro"><h2>' + examData.title + '</h2>';
    html += '<div class="exam-meta-row"><span>⏱ ' + examData.duration + '分钟</span><span>📝 满分' + examData.totalScore + '分</span><span>✅ ' + examData.passScore + '分及格</span></div></div>';

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
          const letters = ['A', 'B', 'C', 'D'];
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
  }

  function selectOption(qid, value) {
    if (examSubmitted) return;
    examAnswers[qid] = value;
    const block = document.querySelector('.question-block[data-qid="' + qid + '"]');
    if (!block) return;
    block.querySelectorAll('.option-item').forEach(el => el.classList.toggle('selected', el.dataset.opt === value));
  }

  function selectJudge(qid, value) {
    if (examSubmitted) return;
    examAnswers[qid] = value;
    const block = document.querySelector('.question-block[data-qid="' + qid + '"]');
    if (!block) return;
    block.querySelectorAll('.judge-option').forEach(el => el.classList.toggle('selected', el.dataset.val === value));
  }

  function saveTextAnswer(qid) {
    if (examSubmitted) return;
    const textarea = document.getElementById('answer-' + qid);
    if (textarea) examAnswers[qid] = textarea.value;
  }

  function submitExam() {
    if (examSubmitted) return;
    const examData = getExamData();
    const unanswered = examData.questions.filter(q => !examAnswers[q.id] || (q.type === 'text' && !examAnswers[q.id].trim()));
    if (unanswered.length > 0 && !confirm('您还有 ' + unanswered.length + ' 道题未作答，确定要提交吗？')) return;
    if (!confirm('确认提交试卷？提交后不可修改。')) return;

    examSubmitted = true;
    const submitBtn = document.getElementById('submitBtn');
    if (submitBtn) {
      submitBtn.disabled = true;
      submitBtn.textContent = '已提交';
    }

    examData.questions.filter(q => q.type === 'text').forEach(q => {
      const textarea = document.getElementById('answer-' + q.id);
      if (textarea && !examAnswers[q.id]) examAnswers[q.id] = textarea.value;
    });

    showResult(gradeExam());
  }

  function gradeExam() {
    const examData = getExamData();
    let totalScore = 0;
    let maxScore = 0;
    const details = [];

    examData.questions.forEach(q => {
      maxScore += q.score;
      const userAnswer = examAnswers[q.id] || '';
      let score = 0;
      let isCorrect = false;

      if (q.type === 'choice' || q.type === 'judge') {
        isCorrect = userAnswer === q.answer;
        score = isCorrect ? q.score : 0;
      } else if (q.type === 'text' && q.keywords && userAnswer.trim()) {
        const answerLower = userAnswer.toLowerCase();
        const matched = q.keywords.filter(kw => answerLower.includes(kw.toLowerCase()));
        const matchRatio = matched.length / q.keywords.length;
        score = Math.round(q.score * Math.min(matchRatio * 1.2, 1));
        isCorrect = matchRatio >= 0.5;
      }

      totalScore += score;
      details.push({ question: q, userAnswer, correctAnswer: q.answer, score, isCorrect });
    });

    return {
      totalScore,
      maxScore,
      passScore: examData.passScore,
      isPassed: totalScore >= examData.passScore,
      details
    };
  }

  function showResult(result) {
    const container = document.getElementById('examContainer');
    if (!container) return;

    const statusClass = result.isPassed ? 'pass' : 'fail';
    const statusText = result.isPassed ? '恭喜通关！' : '未通过，请继续学习';
    let html = '<div class="exam-result"><div class="result-score-circle ' + statusClass + '"><div class="score-num">' + result.totalScore + '</div><div class="score-label">/ ' + result.maxScore + '</div></div>';
    html += '<div class="result-status ' + statusClass + '">' + statusText + '</div>';
    html += '<div class="result-detail">及格分 ' + result.passScore + ' 分 · ' + (result.isPassed ? '已达到及格线' : '未达到及格线，请复习后重考') + '</div></div>';
    html += '<div class="review-section"><h3>📋 答题详情</h3>';

    result.details.forEach((d, idx) => {
      const qType = d.question.type === 'choice' ? '选择题' : d.question.type === 'judge' ? '判断题' : '问答/情景题';
      let answerHtml = '';

      if (d.question.type === 'choice') {
        answerHtml = '<span class="' + (d.isCorrect ? 'correct' : 'your') + '">你的答案：' + (d.userAnswer || '未作答') + '</span> · <span class="correct">正确答案：' + d.correctAnswer + '</span>';
      } else if (d.question.type === 'judge') {
        const userText = d.userAnswer === 'V' ? '正确' : d.userAnswer === 'X' ? '错误' : '未作答';
        const correctText = d.correctAnswer === 'V' ? '正确' : '错误';
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
})();
