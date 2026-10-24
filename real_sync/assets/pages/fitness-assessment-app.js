// ---- 原第 1 个内联 script 块 ----

const HTML2CANVAS_CDN = 'https://cdn.jsdelivr.net/npm/html2canvas@1.4.1/dist/html2canvas.min.js';
const JSPDF_CDN = 'https://cdn.jsdelivr.net/npm/jspdf@2.5.1/dist/jspdf.umd.min.js';

// ==================== Global State ====================
const state = {
  ageGroup: '',
  gender: '',
  name: '',
  ageYears: 0,
  ageMonths: 0,
  testDate: '',
  testData: {},
  imageRatings: {},
  currentStep: 1,
  _deepSeekContent: null,
  _reportMode: 'pending',
  _reportError: '',
  _ocrData: null
};

function getStateText(value, fallback) {
  return value ? String(value) : fallback;
}

function getDisplayName() {
  return getStateText(state.name, '该学员');
}

function getDisplayGender() {
  return getStateText(state.gender, '未填写');
}

function getAgeReferenceInput() {
  var years = Number(state.ageYears);
  var months = Number(state.ageMonths);
  if (!isNaN(years) && years > 0) {
    return { years: years, months: isNaN(months) ? 0 : months, derived: false };
  }

  var fallbackYears = state.ageGroup === 'schoolage' ? 8 : 5;
  return { years: fallbackYears, months: 0, derived: true };
}

function getAgeText() {
  var years = Number(state.ageYears);
  var months = Number(state.ageMonths);
  if (!isNaN(years) && years > 0) {
    return years + '岁' + (isNaN(months) ? 0 : months) + '个月';
  }
  return '未填写';
}

function getMetricText(value, unit) {
  return value !== undefined && value !== null && !isNaN(value) ? (value + ' ' + unit) : '未填写';
}

function getBmiText() {
  if (state.testData.height && state.testData.weight) {
    return (state.testData.weight / Math.pow(state.testData.height / 100, 2)).toFixed(1);
  }
  return '未计算';
}

// ==================== Auth Helpers ====================

function buildAuthHeaders(extraHeaders) {
  var base = extraHeaders || {};
  if (typeof window.authHeaders === 'function') {
    return window.authHeaders(base);
  }
  return base;
}

// ==================== Test Items Definition ====================
const testItems = {
  preschool: [
    { id: 'height', name: '身高', tag: '身体形态', unit: 'cm', type: 'mid_better',
      ref: {
        boy: { 3:[97.3,107.7], 3.5:[99.9,110.5], 4:[103.7,114.5], 4.5:[106.5,117.4], 5:[110.2,121.5], 5.5:[113.2,125.0], 6:[115.7,128.4] },
        girl: { 3:[96.1,106.8], 3.5:[99.0,110.0], 4:[102.8,113.7], 4.5:[105.5,116.3], 5:[109.2,120.5], 5.5:[112.3,123.8], 6:[114.7,127.6] }
      }
    },
    { id: 'weight', name: '体重', tag: '身体形态', unit: 'kg', type: 'mid_better',
      ref: {
        boy: { 3:[13.0,19.0], 3.5:[13.0,19.5], 4:[13.0,19.5], 4.5:[13.0,20.0], 5:[13.0,20.0], 5.5:[13.0,20.0], 6:[13.0,20.0] },
        girl: { 3:[12.5,19.0], 3.5:[12.5,19.5], 4:[12.5,19.5], 4.5:[12.5,20.0], 5:[12.5,20.5], 5.5:[12.5,20.5], 6:[12.5,21.0] }
      }
    },
    { id: 'standing_jump', name: '立定跳远', tag: '爆发力', unit: 'cm', type: 'higher_better',
      ref: {
        boy: { 3:[43,84], 3.5:[52,91], 4:[65,102], 4.5:[75,108], 5:[83,116], 5.5:[89,123], 6:[93,129] },
        girl: { 3:[42,81], 3.5:[51,87], 4:[63,98], 4.5:[71,103], 5:[79,110], 5.5:[85,116], 6:[88,121] }
      }
    },
    { id: 'continuous_jump', name: '双脚连续跳', tag: '协调性', unit: '秒', type: 'lower_better',
      ref: {
        boy: { 3:[11.3,5.4], 3.5:[9.8,5.1], 4:[8.5,4.8], 4.5:[7.6,4.6], 5:[6.9,4.4], 5.5:[6.5,4.3], 6:[6.2,4.1] },
        girl: { 3:[11.5,5.5], 3.5:[10.1,5.3], 4:[8.5,5.0], 4.5:[7.7,4.8], 5:[7.0,4.5], 5.5:[6.6,4.4], 6:[6.4,4.3] }
      }
    },
    { id: 'tennis_throw', name: '网球掷远', tag: '上肢力量', unit: 'm', type: 'higher_better',
      ref: {
        boy: { 3:[3.0,5.5], 3.5:[3.0,5.5], 4:[4.0,6.0], 4.5:[4.5,8.0], 5:[5.5,9.0], 5.5:[6.0,10.0], 6:[7.0,12.0] },
        girl: { 3:[2.5,5.0], 3.5:[2.5,5.0], 4:[3.5,5.0], 4.5:[3.5,5.5], 5:[4.5,8.5], 5.5:[5.0,8.5], 6:[5.0,8.0] }
      }
    },
    { id: 'sit_reach', name: '坐位体前屈', tag: '柔韧性', unit: 'cm', type: 'higher_better',
      ref: {
        boy: { 3:[8.2,17.7], 3.5:[8.3,18.0], 4:[8.0,18.0], 4.5:[7.5,17.8], 5:[6.9,17.5], 5.5:[6.5,17.4], 6:[6.0,17.2] },
        girl: { 3:[9.1,18.6], 3.5:[9.5,19.0], 4:[9.7,19.4], 4.5:[9.8,19.6], 5:[9.8,19.9], 5.5:[9.8,20.3], 6:[9.6,20.5] }
      }
    },
    { id: 'balance_beam', name: '走平衡木', tag: '平衡性', unit: '秒', type: 'lower_better',
      ref: {
        boy: { 3:[16.3,5.4], 3.5:[14.6,5.1], 4:[13.0,4.8], 4.5:[11.6,4.5], 5:[10.4,4.2], 5.5:[9.3,4.0], 6:[8.3,3.7] },
        girl: { 3:[16.0,5.4], 3.5:[14.3,5.3], 4:[12.6,4.9], 4.5:[11.2,4.6], 5:[10.2,4.3], 5.5:[9.5,4.0], 6:[8.4,3.8] }
      }
    },
    { id: 'shuttle_run', name: '10米折返跑', tag: '灵敏性', unit: '秒', type: 'lower_better',
      ref: {
        boy: { 3:[9.0,8.0], 3.5:[8.3,7.5], 4:[7.6,6.9], 4.5:[7.2,6.7], 5:[6.9,6.4], 5.5:[6.7,6.2], 6:[6.8,5.8] },
        girl: { 3:[9.3,8.2], 3.5:[8.6,7.7], 4:[8.0,7.2], 4.5:[7.6,7.0], 5:[7.2,6.7], 5.5:[6.9,6.4], 6:[7.2,6.1] }
      }
    }
  ],
  schoolage: [
    { id: 'height', name: '身高', tag: '身体形态', unit: 'cm', type: 'mid_better',
      ref: {
        boy: { 7:[117.6,130.5], 8:[123.1,137.1], 9:[128.0,142.9], 10:[132.3,148.2], 11:[136.8,154.0], 12:[142.5,161.5] },
        girl: { 7:[116.2,129.0], 8:[121.6,135.4], 9:[126.7,141.6], 10:[132.1,148.2], 11:[138.2,155.2], 12:[144.1,160.7] }
      }
    },
    { id: 'weight', name: '体重', tag: '身体形态', unit: 'kg', type: 'mid_better',
      ref: {
        boy: { 7:[20.04,29.35], 8:[22.24,34.31], 9:[24.31,39.08], 10:[26.55,43.85], 11:[29.33,49.2], 12:[32.77,55.5] },
        girl: { 7:[19.01,27.28], 8:[20.89,30.95], 9:[22.93,35.26], 10:[25.36,40.63], 11:[28.53,46.78], 12:[32.42,52.49] }
      }
    },
    { id: 'step_test', name: '台阶测试', tag: '心肺功能', unit: '次', type: 'higher_better',
      ref: {
        boy: { 7:[29,32,35,49], 8:[32,35,38,53], 9:[35,38,42,55], 10:[38,41,46,57], 11:[41,45,53,62], 12:[44,52,60,67] },
        girl: { 7:[25,29,33,48], 8:[29,33,36,50], 9:[32,36,39,52], 10:[35,39,44,55], 11:[37,41,49,59], 12:[40,48,58,64] }
      }
    },
    { id: 'sit_reach', name: '坐位体前屈', tag: '柔韧性', unit: 'cm', type: 'higher_better',
      ref: {
        boy: { 7:[0,3.3,9.9,13], 8:[-0.4,2.9,9.5,13.2], 9:[-0.8,2.5,9.1,13.4], 10:[-2.2,1.4,8.6,13.6], 11:[-2.6,1,8.2,13.8], 12:[-4,-0.1,7.7,14] },
        girl: { 7:[2.4,5.7,12.3,16], 8:[2.3,5.6,12.2,16.3], 9:[2.2,5.5,12.1,16.6], 10:[2.1,5.4,12,16.9], 11:[2,5.3,11.9,17.2], 12:[1.9,5.2,11.8,17.5] }
      }
    },
    { id: 'rope_skip', name: '一分钟跳绳', tag: '协调性', unit: '次', type: 'higher_better',
      ref: {
        boy: { 7:[72,93,113,124], 8:[80,101,121,132], 9:[83,114,134,150], 10:[115,136,158,172], 11:[125,146,168,182], 12:[140,161,183,197] },
        girl: { 7:[72,93,115,130], 8:[80,101,123,138], 9:[98,119,141,156], 10:[120,141,163,178], 11:[130,151,173,188], 12:[148,169,191,206] }
      }
    },
    { id: 'sit_ups', name: '仰卧起坐', tag: '核心力量', unit: '次', type: 'higher_better',
      ref: {
        boy: { 7:[13,19,30,38], 8:[14,20,31,40], 9:[15,22,33,42], 10:[16,24,36,45], 11:[17,25,37,46], 12:[18,26,38,47] },
        girl: { 7:[13,21,31,38], 8:[14,22,32,40], 9:[15,23,34,42], 10:[16,24,36,44], 11:[17,25,37,45], 12:[18,26,38,46] }
      }
    },
    { id: 'standing_jump', name: '立定跳远', tag: '爆发力', unit: 'cm', type: 'higher_better',
      ref: {
        boy: { 7:[107,119,135,149], 8:[123,127,147,156], 9:[129,141,157,170], 10:[135,150,168,178], 11:[146,158,174,189], 12:[152,167,187,198] },
        girl: { 7:[99,110,125,138], 8:[105,118,132,143], 9:[119,130,146,160], 10:[125,134,151,164], 11:[134,146,162,177], 12:[137,147,164,181] }
      }
    },
    { id: 'shuttle_run_4x10', name: '4×10米往返跑', tag: '灵敏性', unit: '秒', type: 'lower_better',
      ref: {
        boy: { 7:[13.6,13.3,12.7,12.4], 8:[13,12.7,12.1,11.8], 9:[12.5,12.2,11.6,11.3], 10:[12.1,11.8,11.2,10.9], 11:[11.8,11.5,10.9,10.6], 12:[11.6,11.3,10.7,10.4] },
        girl: { 7:[14.4,14.1,13.5,13.2], 8:[13.4,13.1,12.5,12.2], 9:[12.6,12.3,11.7,11.4], 10:[12.1,11.8,11.2,10.9], 11:[11.7,11.4,10.8,10.5], 12:[11.6,11.3,10.7,10.4] }
      }
    }
  ]
};

// ==================== Utility Functions ====================

function escapeHtml(str) {
  if (!str) return '';
  return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;');
}

function goToStep(step) {
  document.querySelectorAll('.step').forEach(function(s) { s.classList.remove('active'); });
  document.getElementById('step' + step).classList.add('active');
  state.currentStep = step;
  updateProgress(step);
  window.scrollTo({ top: 0, behavior: 'smooth' });
}

function updateProgress(step) {
  for (var i = 1; i <= 5; i++) {
    var dot = document.querySelector('.progress-dot[data-step="' + i + '"]');
    dot.classList.remove('active', 'done');
    if (i < step) dot.classList.add('done');
    else if (i === step) dot.classList.add('active');
  }
  for (var j = 1; j <= 4; j++) {
    var line = document.querySelector('.progress-line[data-line="' + j + '"]');
    line.classList.toggle('done', j < step);
  }
}

function toggleSection(sectionId) {
  var body = document.getElementById('body' + sectionId);
  var icon = document.getElementById('toggleIcon' + sectionId);
  if (body.classList.contains('open')) {
    body.classList.remove('open');
    icon.classList.remove('open');
  } else {
    body.classList.add('open');
    icon.classList.add('open');
  }
}

function toggleTag(el) {
  el.classList.toggle('selected');
}

function getSelectedTags(containerId) {
  var tags = [];
  var container = document.getElementById(containerId);
  if (!container) return tags;
  container.querySelectorAll('.tag-capsule.selected').forEach(function(t) {
    tags.push(t.getAttribute('data-value'));
  });
  return tags;
}

function selectGender(g, el) {
  state.gender = g;
  document.querySelectorAll('.gender-option').forEach(function(o) { o.classList.remove('selected'); });
  el.classList.add('selected');
}

// ==================== Step 2: Age Selection ====================

function selectAge(group, el) {
  state.ageGroup = group;
  document.querySelectorAll('.age-card').forEach(function(c) { c.classList.remove('selected'); });
  el.classList.add('selected');
  document.getElementById('btnStep2Next').disabled = false;
}

function validateStep2() {
  if (!state.ageGroup) { alert('请选择年龄段'); return; }
  goToStep(3);
}

// ==================== Step 3: Validate ====================

function validateStep3() {
  var name = document.getElementById('childName').value.trim();
  var yearsValue = document.getElementById('ageYears').value.trim();
  var monthsValue = document.getElementById('ageMonths').value.trim();
  var years = yearsValue ? parseInt(yearsValue, 10) : 0;
  var months = monthsValue ? parseInt(monthsValue, 10) : 0;
  var date = document.getElementById('testDate').value;

  if (yearsValue && (isNaN(years) || years < 3 || years > 15)) { alert('年龄如填写，请输入 3-15 岁'); return; }
  if (monthsValue && (isNaN(months) || months < 0 || months > 11)) { alert('月龄如填写，请输入 0-11'); return; }

  state.name = name;
  state.ageYears = years;
  state.ageMonths = months;
  state.testDate = date;

  renderTestItems();
  goToStep(4);
}

// ==================== Step 4: Test Data ====================

function renderTestItems() {
  var container = document.getElementById('testItemsContainer');
  var items = testItems[state.ageGroup];
  var genderKey = state.gender === '男' ? 'boy' : (state.gender === '女' ? 'girl' : '');
  var ageRefInput = getAgeReferenceInput();

  var html = '';
  items.forEach(function(item) {
    var genderRef = genderKey ? item.ref[genderKey] : null;
    var effectiveAge = ageRefInput.years;
    if (state.ageGroup === 'schoolage' && effectiveAge === 6) {
      effectiveAge = 7;
    }
    var ageKey = genderRef ? findClosestAgeKey(genderRef, effectiveAge, ageRefInput.months) : null;
    var ref = genderRef && ageKey !== null ? genderRef[ageKey] : null;
    var refText = '';
    var direction = '';
    if (!genderRef) {
      refText = '未填写性别，暂不显示评级标准';
    } else if (ref && ref[0] !== 999) {
      if (ref.length === 4 && item.type !== 'mid_better') {
        // 4-boundary format: [欠佳线, 中等线, 良好线, 优秀线]
        if (item.type === 'higher_better') {
          refText = '评级标准：较差<' + ref[0] + ' 欠佳' + ref[0] + '~' + ref[1] + ' 中等' + ref[1] + '~' + ref[2] + ' 良好' + ref[2] + '~' + ref[3] + ' 优秀>=' + ref[3] + ' ' + item.unit;
          direction = '数值越大越好';
        } else if (item.type === 'lower_better') {
          refText = '评级标准：较差>' + ref[0] + ' 欠佳' + ref[1] + '~' + ref[0] + ' 中等' + ref[2] + '~' + ref[1] + ' 良好' + ref[3] + '~' + ref[2] + ' 优秀<=' + ref[3] + ' ' + item.unit;
          direction = '数值越小越好';
        }
      } else if (item.type === 'higher_better') {
        refText = '参考范围：' + ref[0] + ' ~ ' + ref[1] + ' ' + item.unit;
        direction = '数值越大越好';
      } else if (item.type === 'lower_better') {
        refText = '参考范围：' + ref[1] + ' ~ ' + ref[0] + ' ' + item.unit;
        direction = '数值越小越好';
      } else {
        refText = '正常范围：' + ref[0] + ' ~ ' + ref[1] + ' ' + item.unit;
        direction = '处于中间范围较好';
      }
    } else {
      refText = '暂无参考数据';
      direction = '';
    }

    html += '<div class="test-item">' +
      '<div class="test-item-header">' +
        '<span class="test-item-name">' + item.name + '</span>' +
        '<span class="test-item-tag">' + item.tag + '</span>' +
      '</div>' +
      '<div class="test-item-input">' +
        '<input type="number" step="0.1" id="input_' + item.id + '" placeholder="请输入" oninput="handleTestInput(\'' + item.id + '\')">' +
        '<span class="unit">' + item.unit + '</span>' +
      '</div>' +
      '<div class="test-item-ref">' + refText + (direction ? '（' + direction + '）' : '') + '</div>' +
      '<div class="test-item-rating" id="rating_' + item.id + '"></div>' +
    '</div>';
  });
  container.innerHTML = html;

  // If OCR data was recognized earlier (in Step 3), fill it now
  if (state._ocrData) {
    setTimeout(function() { fillFormFromOCR(state._ocrData); }, 100);
  }
}

function findClosestAgeKey(refObj, ageYears, ageMonths) {
  var exactAge = ageYears + ageMonths / 12;
  var keys = Object.keys(refObj).map(Number);
  var closest = keys[0];
  var minDiff = Math.abs(exactAge - closest);
  for (var i = 1; i < keys.length; i++) {
    var diff = Math.abs(exactAge - keys[i]);
    if (diff < minDiff || (diff === minDiff && keys[i] > closest)) {
      minDiff = diff;
      closest = keys[i];
    }
  }
  return closest;
}

function getRating(item, value) {
  var genderKey = state.gender === '男' ? 'boy' : (state.gender === '女' ? 'girl' : '');
  var genderRef = item.ref[genderKey];
  if (!genderRef || value === null || value === undefined || isNaN(value)) return { level: '', label: '', score: 0, color: '' };

  // For schoolage, if age is 6, use 7-year-old standard
  var ageRefInput = getAgeReferenceInput();
  var effectiveAge = ageRefInput.years;
  if (state.ageGroup === 'schoolage' && effectiveAge === 6) {
    effectiveAge = 7;
  }

  var ageKey = findClosestAgeKey(genderRef, effectiveAge, ageRefInput.months);
  var ref = genderRef[ageKey];
  if (!ref) return { level: '', label: '', score: 0, color: '' };

  // Skip rating for items with no real reference (999 sentinel)
  if (ref[0] === 999 && ref[1] === 999) return { level: '', label: '无参考标准', score: 0, color: 'var(--text-light)' };

  var score, level, label, color;

  // Check if ref has 4 boundary values [b1, b2, b3, b4]
  if (ref.length === 4 && item.type !== 'mid_better') {
    var b1 = ref[0], b2 = ref[1], b3 = ref[2], b4 = ref[3];

    if (item.type === 'higher_better') {
      // 4 boundaries for higher_better: [b1=欠佳线, b2=中等线, b3=良好线, b4=优秀线]
      // 规则: value<b1→较差, b1<=value<b2→欠佳, b2<=value<b3→中等, b3<=value<b4→良好, value>=b4→优秀
      if (value < b1) {
        score = b1 !== 0 ? 40 * value / b1 : 20;
        if (score < 0) score = 0;
        level = 'below_fair'; label = '较差'; color = '#e67e22';
      } else if (value < b2) {
        score = 40 + 20 * (value - b1) / (b2 - b1);
        level = 'poor'; label = '欠佳'; color = 'var(--red)';
      } else if (value < b3) {
        score = 60 + 15 * (value - b2) / (b3 - b2);
        level = 'fair'; label = '中等'; color = 'var(--orange)';
      } else if (value < b4) {
        score = 75 + 15 * (value - b3) / (b4 - b3);
        level = 'good'; label = '良好'; color = 'var(--blue)';
      } else {
        score = 90 + 10 * Math.min((value - b4) / b4, 1);
        level = 'excellent'; label = '优秀'; color = 'var(--green)';
      }
    } else if (item.type === 'lower_better') {
      // 4 boundaries for lower_better: [b1=较差线, b2=欠佳线, b3=中等线, b4=良好线] (decreasing order)
      // 规则: value>b1→较差, b2<value<=b1→欠佳, b3<value<=b2→中等, b4<value<=b3→良好, value<=b4→优秀
      if (value > b1) {
        score = b1 !== 0 ? 40 * (1 - (value - b1) / b1) : 20;
        if (score < 0) score = 0;
        level = 'below_fair'; label = '较差'; color = '#e67e22';
      } else if (value > b2) {
        score = 40 + 20 * (b1 - value) / (b1 - b2);
        level = 'poor'; label = '欠佳'; color = 'var(--red)';
      } else if (value > b3) {
        score = 60 + 15 * (b2 - value) / (b2 - b3);
        level = 'fair'; label = '中等'; color = 'var(--orange)';
      } else if (value > b4) {
        score = 75 + 15 * (b3 - value) / (b3 - b4);
        level = 'good'; label = '良好'; color = 'var(--blue)';
      } else {
        score = 90 + 10 * Math.min((b4 - value) / b4, 1);
        level = 'excellent'; label = '优秀'; color = 'var(--green)';
      }
    }
  } else if (item.type === 'higher_better') {
    // Fallback: 2-value ref = [60分对应值, 100分对应值]
    var val60 = ref[0], val100 = ref[1];
    var range = val100 - val60;
    if (range === 0) range = 1;
    if (value >= val100) { score = 100; level = 'excellent'; label = '优秀'; color = 'var(--green)'; }
    else if (value >= val60) { score = 60 + (value - val60) / range * 40; level = 'good'; label = '良好'; color = 'var(--blue)'; }
    else {
      var gap = (val60 - value) / range;
      if (gap <= 0.1) { score = 50; level = 'fair'; label = '合格'; color = 'var(--orange)'; }
      else if (gap <= 0.3) { score = 35; level = 'fair'; label = '合格'; color = 'var(--orange)'; }
      else { score = 20; level = 'poor'; label = '待提升'; color = 'var(--red)'; }
    }
  } else if (item.type === 'lower_better') {
    // Fallback: 2-value ref = [60分对应值(较慢), 100分对应值(较快)]
    var val60 = ref[0], val100 = ref[1];
    var range = val60 - val100;
    if (range === 0) range = 1;
    if (value <= val100) { score = 100; level = 'excellent'; label = '优秀'; color = 'var(--green)'; }
    else if (value <= val60) { score = 100 - (value - val100) / range * 40; level = 'good'; label = '良好'; color = 'var(--blue)'; }
    else {
      var gap = (value - val60) / range;
      if (gap <= 0.1) { score = 50; level = 'fair'; label = '合格'; color = 'var(--orange)'; }
      else if (gap <= 0.3) { score = 35; level = 'fair'; label = '合格'; color = 'var(--orange)'; }
      else { score = 20; level = 'poor'; label = '待提升'; color = 'var(--red)'; }
    }
  } else {
    // mid_better: ref = [正常范围下限, 正常范围上限]
    var low = ref[0], high = ref[1];
    var range = high - low;
    if (range === 0) range = 1;
    if (state.ageGroup === 'schoolage') {
      if (value >= low && value <= high) {
        score = 85; level = 'standard'; label = '标准'; color = 'var(--green)';
      } else if (value > high) {
        score = 60; level = 'below_fair'; label = item.id === 'weight' ? '偏胖' : '偏高'; color = '#e67e22';
      } else {
        score = 60; level = 'below_fair'; label = item.id === 'weight' ? '偏瘦' : '偏低'; color = '#e67e22';
      }
      return { level: level, label: label, score: score, color: color };
    }
    if (value >= low && value <= high) {
      score = 85; level = 'standard'; label = '标准'; color = 'var(--green)';
    } else if (value > high) {
      if (item.id === 'weight') {
        score = 60; level = 'below_fair'; label = '偏胖'; color = '#e67e22';
      } else {
        var deviation = (value - high) / range;
        if (deviation <= 0.15) { score = 75; level = 'good'; label = '良好'; color = 'var(--blue)'; }
        else { score = 65; level = 'good'; label = '良好'; color = 'var(--blue)'; }
      }
    } else {
      if (item.id === 'weight') {
        score = 60; level = 'below_fair'; label = '偏瘦'; color = '#e67e22';
      } else {
        var deviation = (low - value) / range;
        if (deviation <= 0.1) { score = 70; level = 'good'; label = '良好'; color = 'var(--blue)'; }
        else if (deviation <= 0.3) { score = 45; level = 'fair'; label = '合格'; color = 'var(--orange)'; }
        else { score = 20; level = 'poor'; label = '待提升'; color = 'var(--red)'; }
      }
    }
  }

  return { level: level, label: label, score: score, color: color };
}

// 清除图片评级（用户手动修改值时调用）
function clearImageRating(itemId) {
  if (state.imageRatings && state.imageRatings[itemId]) {
    delete state.imageRatings[itemId];
  }
}

function handleTestInput(itemId) {
  // 身体形态项目优先保留图片原始评级，避免数值微调后被系统口径覆盖。
  if (itemId !== 'height' && itemId !== 'weight') {
    clearImageRating(itemId);
  }
  autoRate(itemId);
}

function autoRate(itemId) {
  var items = testItems[state.ageGroup];
  var item = items.find(function(i) { return i.id === itemId; });
  var value = parseFloat(document.getElementById('input_' + itemId).value);
  var el = document.getElementById('rating_' + itemId);

  // 优先使用图片中的评级（以体测图片结果为准）
  var imageRating = (state.imageRatings && state.imageRatings[itemId]) ? state.imageRatings[itemId].trim() : null;
  if (imageRating) {
    var colorMap = {
      '优秀': 'var(--green)', '良好': 'var(--blue)', '中等': 'var(--orange)', '合格': 'var(--orange)',
      '一般': 'var(--orange)', '正常': 'var(--green)', '标准': 'var(--green)',
      '较差': '#e67e22', '欠佳': 'var(--red)', '待提升': 'var(--red)',
      '偏低': '#e67e22', '偏高': '#e67e22', '偏胖': '#e67e22', '偏瘦': '#e67e22'
    };
    var color = colorMap[imageRating] || 'var(--text-light)';
    el.innerHTML = '<span style="color:' + color + '">\u25CF ' + imageRating + '</span>';
    return;
  }

  // 无图片评级时，使用系统计算的评级
  var rating = getRating(item, value);
  if (rating.level) {
    el.innerHTML = '<span style="color:' + rating.color + '">\u25CF ' + rating.label + '</span>';
  } else {
    el.innerHTML = '';
  }
}

function validateStep4() {
  var items = testItems[state.ageGroup];
  state.testData = {};

  items.forEach(function(item) {
    var input = document.getElementById('input_' + item.id);
    var value = parseFloat(input.value);
    if (!isNaN(value)) {
      input.style.borderColor = '#e8e8e8';
      state.testData[item.id] = value;
    } else {
      input.style.borderColor = '#e8e8e8';
    }
  });

  goToStep(5);
  showLoadingAndGenerate();
}

// ==================== Step 5: Generate Report ====================

async function showLoadingAndGenerate() {
  // Prevent duplicate calls
  if (state._isGenerating) return;
  state._isGenerating = true;

  document.getElementById('loadingOverlay').classList.add('active');
  document.getElementById('reportContainer').classList.remove('active');

  var usedDeepSeek = false;

  try {
    var prompt = buildAIPrompt();
    var aiContent = await callDeepSeekAPI(prompt);
    if (aiContent && aiContent.trim()) {
      usedDeepSeek = true;
      state._deepSeekContent = aiContent;
      state._reportMode = 'ai';
      state._reportError = '';
    } else {
      state._reportMode = 'fallback';
      state._reportError = 'AI 返回了空内容';
    }
  } catch (err) {
    console.warn('Backend AI report unavailable, falling back to local analysis:', err);
    state._deepSeekContent = null;
    state._reportMode = 'fallback';
    state._reportError = err && err.message ? err.message : 'AI 服务暂时不可用';
  }

  await new Promise(function(resolve) { setTimeout(resolve, 500); });

  generateReport(usedDeepSeek);

  document.getElementById('loadingOverlay').classList.remove('active');
  document.getElementById('reportContainer').classList.add('active');
  state._isGenerating = false;
}

function buildAIPrompt() {
  var items = testItems[state.ageGroup];
  var results = [];
  items.forEach(function(item) {
    var value = state.testData[item.id];
    var rating = getRating(item, value);
    // 优先使用图片中的评级（以体测图片结果为准）
    var imageRating = (state.imageRatings && state.imageRatings[item.id]) ? state.imageRatings[item.id].trim() : null;
    if (imageRating) {
      rating.label = imageRating;
      var levelMap = { '优秀': 'excellent', '良好': 'good', '中等': 'fair', '合格': 'fair', '一般': 'fair', '正常': 'standard', '标准': 'standard', '较差': 'below_fair', '欠佳': 'poor', '待提升': 'poor', '偏低': 'below_fair', '偏高': 'below_fair', '偏胖': 'below_fair', '偏瘦': 'below_fair' };
      rating.level = levelMap[imageRating] || rating.level;
    }
    results.push(Object.assign({}, item, { value: value }, rating));
  });

  // Gather coach info from Section B
  var weeklyFreq = document.getElementById('weeklyFrequency').value;
  var participation = document.getElementById('participation').value;
  var fitnessLevel = document.getElementById('fitnessLevel').value;
  var skillLevel = document.getElementById('skillLevel').value;
  var personality = document.getElementById('personality').value.trim();
  var coachNotes = document.getElementById('coachNotes').value.trim();
  var trainingFocus = getSelectedTags('trainingFocusTags');

  // Gather goal info from Section C
  var fitnessGoals = getSelectedTags('fitnessGoalTags');
  var skillGoals = getSelectedTags('skillGoalTags');
  var characterGoals = getSelectedTags('characterGoalTags');
  var socialGoals = getSelectedTags('socialGoalTags');
  var trainingCycle = document.getElementById('trainingCycle').value;
  var currentCycleFocus = document.getElementById('currentCycleFocus').value.trim();
  var nextCycleFocus = document.getElementById('nextCycleFocus').value.trim();
  var fitnessGoalCustom = document.getElementById('fitnessGoalCustom').value.trim();
  var skillGoalCustom = document.getElementById('skillGoalCustom').value.trim();
  var characterGoalCustom = document.getElementById('characterGoalCustom').value.trim();
  var socialGoalCustom = document.getElementById('socialGoalCustom').value.trim();

  var bmi = getBmiText();
  var ageText = getAgeText();

  // Build test results table (only include items with data)
  var testResultsText = '| 项目 | 测试值 | 评级 |\n|------|--------|------|\n';
  results.forEach(function(r) {
    if (r.value !== undefined && r.value !== null && !isNaN(r.value)) {
      testResultsText += '| ' + r.name + ' | ' + r.value + ' ' + r.unit + ' | ' + (r.label || '未评级') + ' |\n';
    }
  });
  if (testResultsText === '| 项目 | 测试值 | 评级 |\n|------|--------|------|\n') {
    testResultsText += '| 暂无手填体测数据 | 未填写 | 未评级 |\n';
  }

  var prompt = '你是追光小牛运动成长中心的专业运动规划师，精通ACE成长体系。请根据以下信息，为家长生成一份专业、温暖、有说服力的个性化运动成长规划。\n\n' +
    '## ACE成长体系简介\n' +
    'ACE寓意"王牌"，三大核心支柱：\n' +
    '- A (Athleticism) 运动能力：强度体能训练 + 技能性训练\n' +
    '- C (Cognition) 认知能力：功能性训练（感统、专注力、学习效率提升）\n' +
    '- E (Engagement) 参与动能：峰值游戏体验（激发内在运动热爱）\n\n' +
    '四大实践维度在日常教学中全面落地：\n' +
    '1. 强度体能训练：提升心肺、力量、耐力，通过体测量化成长\n' +
    '2. 技能性训练：金字塔进阶路径，升班考核+展演+赛事\n' +
    '3. 功能性训练：发展感统能力，直接关联学习效率与生活能力\n' +
    '4. 峰值游戏体验：游戏化情境，创造高光时刻，激发内在驱动力\n\n' +
    '## 儿童基本信息\n' +
    '- 姓名：' + getDisplayName() + '\n' +
    '- 性别：' + getDisplayGender() + '\n' +
    '- 年龄：' + ageText + '\n' +
    '- 测试日期：' + getStateText(state.testDate, '未填写') + '\n' +
    '- 身高：' + getMetricText(state.testData.height, 'cm') + '\n' +
    '- 体重：' + getMetricText(state.testData.weight, 'kg') + '\n' +
    '- BMI：' + bmi + '\n\n' +
    '## 体测结果\n' +
    testResultsText + '\n' +
    '## 小朋友当前情况（教练填写）\n' +
    '- 上课频率：' + weeklyFreq + '\n' +
    '- 训练科目：' + (trainingFocus.length > 0 ? trainingFocus.join('、') : '未指定') + '\n' +
    '- 课堂参与度：' + participation + '\n' +
    '- 体能情况：' + fitnessLevel + '\n' +
    '- 技能掌握情况：' + skillLevel + '\n' +
    (personality ? '- 性格特点：' + personality + '\n' : '') +
    (coachNotes ? '- 教练备注：' + coachNotes + '\n' : '') +
    '\n## 下一步目标规划（教练设定）\n' +
    '- 体能提升重点：' + (fitnessGoals.length > 0 ? fitnessGoals.join('、') : '未指定') + '\n' +
    (fitnessGoalCustom ? '- 体能提升补充：' + fitnessGoalCustom + '\n' : '') +
    '- 技能提升重点：' + (skillGoals.length > 0 ? skillGoals.join('、') : '未指定') + '\n' +
    (skillGoalCustom ? '- 技能提升补充：' + skillGoalCustom + '\n' : '') +
    '- 性格塑造目标：' + (characterGoals.length > 0 ? characterGoals.join('、') : '未指定') + '\n' +
    (characterGoalCustom ? '- 性格塑造补充：' + characterGoalCustom + '\n' : '') +
    '- 社交能力目标：' + (socialGoals.length > 0 ? socialGoals.join('、') : '未指定') + '\n' +
    (socialGoalCustom ? '- 社交能力补充：' + socialGoalCustom + '\n' : '') +
    '- 本次运动规划周期：' + trainingCycle + '\n' +
    (currentCycleFocus ? '- 本周期训练重点（教练指定，必须重点参考）：' + currentCycleFocus + '\n' : '') +
    (nextCycleFocus ? '- 下周期训练重点（教练规划，需要提及）：' + nextCycleFocus + '\n' : '') +
    '\n## 指导教练信息\n' +
    '- 教练姓名：' + (document.getElementById('coachName').value.trim() || '未填写') + '\n' +
    '- 联系电话：' + (document.getElementById('coachPhone').value.trim() || '未填写') + '\n' +
    '- 所属门店：' + (document.getElementById('coachStore').value.trim() || '未填写') + '\n\n' +
    '## 追光小牛课程体系\n' +
    '- 技能课：跳绳(入门/达标/进阶 4-8岁)、篮球(入门/提升/进阶 4-9岁)、武术格斗防身(入门/进阶 4-8岁)\n' +
    '- 特色课：快乐体操(萌牛班2-3岁/小牛班3-4岁/牛牛班4-5岁/腾牛班5-6岁/睿牛班6-8岁)、专业体操(基础/进阶 5-8岁)、跑酷(入门/进阶/高级 4-12岁+)、中考达标训练(9-12岁+)、增高体能课(4-9岁)、定制私教(9-12岁+)\n' +
    '- 基础课：素质达标体适能(2-7岁)、专项运动能力提升(6-12岁+)\n' +
    '- 五大训练周期：运动启蒙(1-3月)→激发兴趣(3-6月)→体能跃迁(6-12月)→体能进阶(12-18月)→运动强者(18-24月)\n\n' +
    '## 要求\n' +
    '请生成以下内容（使用HTML格式），这是一份给家长看的运动成长规划：\n\n' +
    '体测数据是本报告的核心依据。必须逐项分析所有已录入项目，引用项目名称、测试值、单位和评级，说明该结果代表的能力表现、对训练的影响以及下一步训练方向。体测结果摘要可以简洁，但不能替代逐项分析，也不能只写总体评级。\n\n' +
    '### 一、综合评估与目标（200字左右）\n' +
    '结合体测数据、教练填写的当前情况和目标，给出整体评估。\n' +
    '明确下一阶段的提升重点（体能+技能+性格+社交）。\n\n' +
    '### 二、体测逐项分析（每个有效项目至少1句话）\n' +
    '- 逐项说明测试值、评级、优势或短板、训练含义和建议动作方向\n' +
    '- 对薄弱项目给出优先级和可观察的阶段目标\n\n' +
    '### 三、ACE个性化运动规划（700字左右，核心内容）\n' +
    '按ACE三大支柱组织：\n\n' +
    '**A. 运动能力规划**\n' +
    '- 强度体能：针对薄弱项的具体训练方向\n' +
    '- 技能训练：根据技能掌握情况建议的进阶路径\n\n' +
    '**C. 认知能力提升**\n' +
    '- 功能性训练如何帮助感统发展和学习效率\n' +
    '- 具体说明训练与专注力、学习生活的关联\n\n' +
    '**E. 参与动能激发**\n' +
    '- 根据孩子性格特点，如何通过峰值体验激发兴趣\n' +
    '- 推荐的具体课程组合及理由\n\n' +
    '### 四、课程安排建议（250字左右）\n' +
    '- 推荐课程组合（2-3个课程）\n' +
    '- 每周训练频率和时长\n' +
    '- 训练周期和阶段性目标\n' +
    '- 自然融入续费引导（如"建议持续X个月系统训练以达到XX目标"）\n\n' +
    '### 五、预期训练效果（150字左右）\n' +
    '- A维度：体能指标预期提升\n' +
    '- C维度：专注力、学习效率改善\n' +
    '- E维度：运动兴趣和自信心变化\n' +
    '- 引用品牌数据："参与训练课程的同学，两年内平均增高约5-9厘米"\n\n' +
    '### 六、家庭训练建议（150字左右）\n' +
    '- 2-3个在家可以做的练习\n' +
    '- ACE家校共育建议\n' +
    '- 日常作息建议\n\n' +
    '注意：\n' +
    '- 语气专业但温暖，像一位关心孩子的运动教练在和家长沟通\n' +
    '- 自然融入ACE体系概念，但不要生硬堆砌术语\n' +
    '- 多用鼓励性语言，让家长对孩子充满信心\n' +
    '- 课程推荐要自然，通过专业分析让家长产生信任\n' +
    '- 使用HTML标签格式化（<p>, <strong>, <h4>, <ul>, <li>等），但注意：\n' +
    '  - 只使用 <h4> 作为小标题，不要使用 <h1> <h2> <h3>\n' +
    '  - 每个段落用 <p> 包裹，段落之间不要有空行\n' +
    '  - 不要重复相同的标题或内容\n' +
    '- 不要重复罗列体测数据，不要写体测结果摘要（系统已自动生成）\n' +
    '- 绝对不要在回复中出现任何关于AI、人工智能、大模型、系统生成、智能系统等相关字眼\n' +
    '- 要以一位真实的、关心孩子的专业运动教练的口吻撰写，让家长觉得这是教练亲自写的规划\n' +
    '- 如果教练填写了"本周期训练重点"和"下周期训练重点"，必须在报告中重点体现和展开，这是教练亲自制定的方向，必须严格参考\n' +
    '- 如果教练填写了自定义补充内容，也要在报告中体现\n' +
    '- 【最重要】教练备注中可能包含明确的限制性要求（如"不需要上其他课程""只需上XX课""不建议报XX"等），这类要求必须绝对遵守！课程推荐必须严格限制在教练备注允许的范围内，绝不能推荐教练明确排除的课程。如果教练备注与默认推荐冲突，以教练备注为准';

  return prompt;
}

async function callDeepSeekAPI(prompt) {
  var response = await fetch('/api/ai-services.php', {
    method: 'POST',
    headers: buildAuthHeaders({
      'Content-Type': 'application/json'
    }),
    body: JSON.stringify({
      action: 'plan',
      prompt: prompt
    })
  });

  if (!response.ok) {
    var errData = {};
    try { errData = await response.json(); } catch(e) {}
    var errMsg = (errData && typeof errData.error === 'string' && errData.error) ? errData.error :
      (errData && errData.error && errData.error.message ? errData.error.message : ('HTTP ' + response.status));
    throw new Error(errMsg);
  }

  var data = await response.json();
  return data.content || null;
}

function generateReport(usedDeepSeek) {
  var items = testItems[state.ageGroup];
  var results = [];
  items.forEach(function(item) {
    var value = state.testData[item.id];
    var rating = getRating(item, value);
    // 优先使用图片中的评级（以体测图片结果为准）
    var imageRating = (state.imageRatings && state.imageRatings[item.id]) ? state.imageRatings[item.id].trim() : null;
    if (imageRating) {
      rating.label = imageRating;
      // 同步更新level以匹配图片评级
      var levelMap = { '优秀': 'excellent', '良好': 'good', '中等': 'fair', '合格': 'fair', '一般': 'fair', '正常': 'standard', '标准': 'standard', '较差': 'below_fair', '欠佳': 'poor', '待提升': 'poor', '偏低': 'below_fair', '偏高': 'below_fair', '偏胖': 'below_fair', '偏瘦': 'below_fair' };
      rating.level = levelMap[imageRating] || rating.level;
    }
    results.push(Object.assign({}, item, { value: value }, rating));
  });

  // Render basic info
  renderInfoGrid();

  // Render current situation
  renderSituationGrid();

  // Render coach signature
  var coachName = document.getElementById('coachName').value.trim();
  var coachStore = document.getElementById('coachStore').value.trim();
  var coachPhone = document.getElementById('coachPhone').value.trim();
  var sigHtml = '';
  if (coachName) {
    sigHtml = '指导教练：' + escapeHtml(coachName);
    if (coachStore) sigHtml += ' | ' + escapeHtml(coachStore);
    if (coachPhone) sigHtml += ' | ' + escapeHtml(coachPhone);
    sigHtml += '<br>日期：' + escapeHtml(getStateText(state.testDate, new Date().toISOString().slice(0, 10)));
  }
  document.getElementById('coachSignature').innerHTML = sigHtml;

  // Render test summary (brief)
  renderTestSummary(results);

  // Render training plan
  var planContainer = document.getElementById('trainingPlanContent');
  var status = document.getElementById('reportGenerationStatus');

  if (status) {
    if (usedDeepSeek) {
      status.hidden = false;
      status.textContent = '已使用 AI 个性化分析，报告已结合体测数据和教练填写内容生成。';
      status.style.cssText = 'margin:0 0 14px;padding:10px 12px;border-radius:10px;background:#eaf7ee;color:#087443;font-size:13px;line-height:1.6;';
    } else {
      status.hidden = false;
      status.textContent = 'AI 报告生成暂时不可用，当前展示本地数据分析版。请确认内容后再发送给家长。';
      status.style.cssText = 'margin:0 0 14px;padding:10px 12px;border-radius:10px;background:#fff7e6;color:#9a6700;font-size:13px;line-height:1.6;';
    }
  }

  if (usedDeepSeek && state._deepSeekContent) {
    // Clean up HTML: remove h1/h2/h3 tags (use h4 only), remove duplicate summaries
    var cleaned = state._deepSeekContent
      .replace(/<h[1-3][^>]*>/gi, '<h4>')
      .replace(/<\/h[1-3]>/gi, '</h4>');
    planContainer.innerHTML = cleaned;
  } else {
    var analysis = generateLocalAnalysis(results);
    planContainer.innerHTML = analysis;
  }

  // Persist only after every report section has been rendered.
  saveReportRecord(results);
}

function renderInfoGrid() {
  var bmi = getBmiText();
  var ageText = getAgeText();

  var coachName = document.getElementById('coachName').value.trim();
  var coachPhone = document.getElementById('coachPhone').value.trim();
  var coachStore = document.getElementById('coachStore').value.trim();

  var coachHtml = '';
  if (coachName) {
    coachHtml = '<div class="info-item"><div class="label">指导教练</div><div class="value">' + escapeHtml(coachName) + '</div></div>';
    if (coachPhone) {
      coachHtml += '<div class="info-item"><div class="label">教练电话</div><div class="value">' + escapeHtml(coachPhone) + '</div></div>';
    }
    if (coachStore) {
      coachHtml += '<div class="info-item"><div class="label">所属门店</div><div class="value">' + escapeHtml(coachStore) + '</div></div>';
    }
  }

  document.getElementById('infoGrid').innerHTML =
    '<div class="info-item"><div class="label">姓名</div><div class="value">' + escapeHtml(getStateText(state.name, '未填写')) + '</div></div>' +
    '<div class="info-item"><div class="label">性别</div><div class="value">' + escapeHtml(getDisplayGender()) + '</div></div>' +
    '<div class="info-item"><div class="label">年龄</div><div class="value">' + escapeHtml(ageText) + '</div></div>' +
    '<div class="info-item"><div class="label">身高</div><div class="value">' + escapeHtml(getMetricText(state.testData.height, 'cm')) + '</div></div>' +
    '<div class="info-item"><div class="label">体重</div><div class="value">' + escapeHtml(getMetricText(state.testData.weight, 'kg')) + '</div></div>' +
    '<div class="info-item"><div class="label">BMI</div><div class="value">' + bmi + '</div></div>' +
    coachHtml;
}

function renderSituationGrid() {
  var weeklyFreq = document.getElementById('weeklyFrequency').value;
  var participation = document.getElementById('participation').value;
  var fitnessLevel = document.getElementById('fitnessLevel').value;
  var skillLevel = document.getElementById('skillLevel').value;
  var trainingFocus = getSelectedTags('trainingFocusTags');

  var html =
    '<div class="situation-item"><div class="sit-label">上课频率</div><div class="sit-value">' + weeklyFreq + '</div></div>' +
    '<div class="situation-item"><div class="sit-label">课堂参与度</div><div class="sit-value">' + participation + '</div></div>' +
    '<div class="situation-item"><div class="sit-label">体能评价</div><div class="sit-value">' + fitnessLevel + '</div></div>' +
    '<div class="situation-item"><div class="sit-label">技能水平</div><div class="sit-value">' + skillLevel + '</div></div>';

  if (trainingFocus.length > 0) {
    html += '<div class="situation-item" style="grid-column:1/-1;"><div class="sit-label">训练科目</div><div class="sit-value">' + trainingFocus.join('、') + '</div></div>';
  }

  document.getElementById('situationGrid').innerHTML = html;
}

function renderTestSummary(results) {
  // Filter out items with no data
  var validResults = results.filter(function(r) { return r.value !== undefined && r.value !== null && !isNaN(r.value) && r.level; });

  var childName = getDisplayName();
  var summary = '<p>' + escapeHtml(childName) + '本次体测中，';

  if (validResults.length === 0) {
    summary += '本次体测数据尚未完整录入。以下是针对' + escapeHtml(childName) + '的个性化运动成长规划。</p>';
    document.getElementById('testSummary').innerHTML = summary;
    return;
  }

  var excellent = validResults.filter(function(r) { return r.level === 'excellent'; });
  var good = validResults.filter(function(r) { return r.level === 'good'; });
  var standard = validResults.filter(function(r) { return r.level === 'standard'; });
  var fair = validResults.filter(function(r) { return r.level === 'fair'; });
  var belowFair = validResults.filter(function(r) { return r.level === 'below_fair'; });
  var poor = validResults.filter(function(r) { return r.level === 'poor'; });
  var weakItems = belowFair.concat(fair).concat(poor);
  var strongItems = excellent.concat(good);

  if (excellent.length > 0) {
    summary += '<strong style="color:var(--green);">' + excellent.map(function(s) { return s.name; }).join('、') + '</strong>表现优秀，';
  }
  if (good.length > 0) {
    summary += '<strong style="color:var(--blue);">' + good.map(function(s) { return s.name; }).join('、') + '</strong>表现良好，';
  }
  if (standard.length > 0) {
    summary += '<strong style="color:var(--green);">' + standard.map(function(s) { return s.name; }).join('、') + '</strong>发育标准，';
  }

  if (poor.length > 0) {
    summary += '<strong style="color:var(--red);">' + poor.map(function(w) { return w.name; }).join('、') + '</strong>欠佳，';
  }
  if (belowFair.length > 0) {
    summary += '<strong style="color:#e67e22;">' + belowFair.map(function(w) { return w.name; }).join('、') + '</strong>较差，';
  }
  if (fair.length > 0) {
      summary += '<strong style="color:var(--orange);">' + fair.map(function(w) { return w.name; }).join('、') + '</strong>中等/合格';
  } else if (poor.length === 0 && belowFair.length === 0) {
    summary += '各项指标均表现优秀';
  }

  summary += '。以下是针对' + escapeHtml(childName) + '的个性化运动成长规划。</p>';

  document.getElementById('testSummary').innerHTML = summary;
}

function generateLocalAnalysis(results) {
  var childName = getDisplayName();
  var excellent = results.filter(function(r) { return r.level === 'excellent'; });
  var good = results.filter(function(r) { return r.level === 'good'; });
  var standard = results.filter(function(r) { return r.level === 'standard'; });
  var fair = results.filter(function(r) { return r.level === 'fair'; });
  var belowFair = results.filter(function(r) { return r.level === 'below_fair'; });
  var poor = results.filter(function(r) { return r.level === 'poor'; });
  var weakItems = belowFair.concat(fair).concat(poor);
  var strongItems = excellent.concat(good);

  var html = '';

  // Section 1: Comprehensive Assessment
  html += '<h4>综合评估与目标</h4>';
  var overallText = '';
  if (weakItems.length === 0) overallText = '优秀';
  else if (weakItems.length <= 2) overallText = '良好';
  else if (weakItems.length <= 4) overallText = '需要提升';
  else overallText = '亟待加强';

  html += '<p>' + escapeHtml(childName) + '本次体测综合评级为<strong>' + overallText + '</strong>。';
  if (standard.length > 0) {
    html += '身高、体重等身体形态指标发育标准，';
  }
  if (weakItems.length > 0) {
    html += '在' + weakItems.map(function(w) { return w.name; }).join('、') + '方面有待提升，';
  }
  if (strongItems.length > 0) {
    html += '在' + strongItems.map(function(s) { return s.name; }).join('、') + '方面表现良好。';
  }
  html += '</p>';

  // Keep the fallback report data-driven when the provider is unavailable.
  html += '<h4>体测逐项分析</h4><ul>';
  results.forEach(function(r) {
    if (r.value === undefined || r.value === null || isNaN(r.value)) return;
    var direction = r.level === 'excellent' || r.level === 'good'
      ? '建议保持当前优势，并逐步增加动作难度或组合复杂度。'
      : r.level === 'standard'
        ? '建议保持规律训练，巩固动作质量并观察阶段变化。'
        : '建议列为近期重点，通过分解练习和渐进负荷进行针对性提升。';
    html += '<li><strong>' + escapeHtml(r.name) + '：</strong>' + escapeHtml(String(r.value) + ' ' + r.unit) + '，评级为' + escapeHtml(r.label || '未评级') + '。该项目对应' + escapeHtml(r.tag || '综合能力') + '，' + direction + '</li>';
  });
  html += '</ul>';

  // Section 2: ACE Plan
  html += '<h4>ACE 个性化运动规划</h4>';

  // A - Athleticism
  html += '<p><strong>A. 运动能力规划</strong></p>';
  html += '<p><strong>强度体能：</strong>';
  if (weakItems.length > 0) {
    html += '针对' + weakItems.map(function(w) { return w.name + '(' + w.tag + ')' + '评级"' + w.label + '"'; }).join('、') + '，建议通过系统化的体能训练进行针对性提升。';
  } else {
    html += '各项体能指标表现优秀，建议继续保持，并可尝试更高强度的训练挑战。';
  }
  html += '</p>';

  html += '<p><strong>技能训练：</strong>';
  var skillLevel = document.getElementById('skillLevel').value;
  if (skillLevel === '刚入门' || skillLevel === '正在学习') {
    html += '孩子目前处于技能学习阶段，建议从基础动作模式入手，通过游戏化训练培养运动兴趣，逐步过渡到专项技能训练。';
  } else {
    html += '孩子已具备较好的运动技能基础，建议进入进阶训练阶段，尝试更复杂的动作组合和专项技能提升。';
  }
  html += '</p>';

  // C - Cognition
  html += '<p><strong>C. 认知能力提升</strong></p>';
  html += '<p>功能性训练是追光小牛ACE体系的重要组成部分。通过感统训练、协调性练习和平衡力训练，不仅能够提升运动表现，更能直接促进孩子的专注力和学习效率。研究表明，规律的运动训练能够改善大脑前额叶功能，提升注意力和记忆力。</p>';

  // E - Engagement
  html += '<p><strong>E. 参与动能激发</strong></p>';
  var personality = document.getElementById('personality').value.trim();
  if (personality) {
    html += '<p>根据教练观察，' + escapeHtml(childName) + '的性格特点为"' + escapeHtml(personality) + '"。我们将通过峰值游戏体验，创造属于' + escapeHtml(childName) + '的运动高光时刻，激发内在运动驱动力。</p>';
  } else {
    html += '<p>通过追光小牛独特的峰值游戏体验，我们将为孩子创造运动中的高光时刻，让运动不再是任务，而是孩子发自内心热爱的活动。</p>';
  }

  // Section 3: Course Recommendations
  html += '<h4>课程安排建议</h4>';
  html += '<p>基于以上分析，建议为' + escapeHtml(childName) + '制定以下训练方案：</p>';
  html += '<ul>';
  html += '<li><strong>基础课程：</strong>素质达标体适能（全年龄段综合素质训练，全面提升七大运动维度）</li>';
  if (weakItems.length > 0) {
    var weakTags = [];
    weakItems.forEach(function(w) {
      if (weakTags.indexOf(w.tag) === -1) weakTags.push(w.tag);
    });
    if (weakTags.indexOf('协调性') !== -1) {
      html += '<li><strong>技能课程：</strong>跳绳班（系统提升手眼协调和节奏感）</li>';
    }
    if (weakTags.indexOf('柔韧性') !== -1 || weakTags.indexOf('平衡性') !== -1) {
      html += '<li><strong>特色课程：</strong>快乐体操（系统提升柔韧性和平衡能力）</li>';
    }
    if (weakTags.indexOf('爆发力') !== -1) {
      html += '<li><strong>技能课程：</strong>篮球（跳跃和冲刺动作有效提升下肢爆发力）</li>';
    }
    if (weakTags.indexOf('灵敏性') !== -1) {
      html += '<li><strong>特色课程：</strong>跑酷（快速变向和急停急起提升灵敏性）</li>';
    }
  }
  var weeklyFreq = document.getElementById('weeklyFrequency').value;
  var trainingCycle = document.getElementById('trainingCycle').value;
  html += '<li><strong>训练频率：</strong>' + weeklyFreq + '，建议坚持规律训练</li>';
  html += '<li><strong>运动规划周期：</strong>' + trainingCycle + '</li>';
  html += '</ul>';

  // Section 4: Expected Results
  html += '<h4>预期训练效果</h4>';
  html += '<ul>';
  html += '<li><strong>A维度（运动能力）：</strong>经过系统训练，各项体能指标预期显著提升</li>';
  html += '<li><strong>C维度（认知能力）：</strong>专注力和学习效率将得到明显改善</li>';
  html += '<li><strong>E维度（参与动能）：</strong>运动兴趣和自信心将大幅提升</li>';
  html += '<li><strong>品牌数据：</strong>参与训练课程的同学，两年内平均增高约5-9厘米</li>';
  html += '</ul>';

  // Section 5: Home Training
  html += '<h4>家庭训练建议</h4>';
  html += '<ul>';
  html += '<li>保证每天至少1小时的户外活动时间，阳光照射有助于维生素D合成</li>';
  html += '<li>保证每天' + (state.ageYears <= 6 ? '10-12' : '9-11') + '小时的充足睡眠，生长激素主要在深度睡眠时分泌</li>';
  html += '<li>家长陪伴练习，如跳绳、拍球、亲子拉伸等，每天15-20分钟</li>';
  html += '<li>保证充足的蛋白质、钙质和维生素摄入，多吃牛奶、鸡蛋、鱼类</li>';
  html += '</ul>';

  return html;
}

// ==================== Photo Upload & OCR ====================

function handleReportImageUpload(event) {
  var file = event.target.files[0];
  if (!file) return;

  var preview = document.getElementById('imagePreview');
  var status = document.getElementById('ocrStatus');
  var fileType = (file.type || '').toLowerCase();

  if (fileType.indexOf('heic') > -1 || fileType.indexOf('heif') > -1) {
    status.innerHTML = '<span style="color:var(--orange);">当前不支持直接识别 HEIC/HEIF 图片，请先截图，或转成 JPG/PNG 后再上传。</span>';
    return;
  }

  var reader = new FileReader();
  reader.onload = function(e) {
    preview.innerHTML = '<img src="' + e.target.result + '" style="width:100%;height:100%;object-fit:cover;">';
  };
  reader.readAsDataURL(file);

  status.innerHTML = '<span style="color:var(--purple);">⏳ 正在智能识别体测数据...</span>';

  (async function() {
    try {
      var base64Image = await fileToBase64(file);
      var ocrResult = await callDeepSeekVisionAPI(base64Image);

      if (ocrResult) {
        var normalizedOCR = normalizeOCRData(ocrResult);
        // Store OCR data in state, will be filled when Step 4 renders
        state._ocrData = ocrResult;
        // Debug: log OCR ratings extraction
        var ratingKeys = Object.keys(ocrResult).filter(function(k) { return k.indexOf('_rating') > -1; });
        var extractedRatings = {};
        ratingKeys.forEach(function(k) { extractedRatings[k] = ocrResult[k]; });
        console.log('[OCR Debug] 提取到的评级数据:', JSON.stringify(extractedRatings, null, 2));
        fillFormFromOCR(ocrResult);
        status.innerHTML = '<span style="color:var(--green);">✅ 识别成功！数据已自动填入</span>';
        // 如果有项目有值但没有评级，显示提示
        var items = testItems[state.ageGroup] || [];
        var missingRatings = [];
        items.forEach(function(item) {
          var val = normalizedOCR[item.id];
          var rating = normalizedOCR[item.id + '_rating'];
          if (val !== null && val !== undefined && !isNaN(val) && !rating) {
            missingRatings.push(item.name);
          }
        });
        if (missingRatings.length > 0) {
          status.innerHTML += '<br><span style="color:var(--orange);">⚠️ 以下项目未识别到评级，将使用系统计算：' + missingRatings.join('、') + '</span>';
        }
      } else {
        status.innerHTML = '<span style="color:var(--orange);">识别失败，已切换为手动录入，请继续填写下方体测数据，也可重新上传重试</span>';
        focusFirstTestInput();
      }
    } catch (err) {
      console.error('OCR error:', err);
      var message = '识别暂时不可用，请改用手动录入';
      if (err && (err.status === 401 || err.status === 403)) {
        message = '登录状态已失效，请重新登录后再识别';
        if (typeof window.requirePageAuth === 'function') {
          window.requirePageAuth({ maxRetries: 1 });
        }
      } else if (err && err.message && err.message.indexOf('请先登录') > -1) {
        message = '请先登录员工账号，再使用拍照识别';
        if (typeof window.requirePageAuth === 'function') {
          window.requirePageAuth({ maxRetries: 1 });
        }
      } else if (err && err.message && err.message.indexOf('图片输入格式') > -1) {
        message = '当前图片格式不兼容，请换一张清晰截图或拍照后重试，也可先手动录入';
      } else if (err && err.status === 400) {
        message = err.message || '图片内容无法识别，请换一张清晰截图或拍照后重试';
      } else if (err && err.status === 503) {
        message = err.message || '识别服务暂时繁忙，请稍后重试，也可先手动录入';
      }
      status.innerHTML = '<span style="color:var(--red);">' + message + '</span><br><span style="color:var(--text-light);">请直接填写下方体测项目，系统仍可正常生成运动规划报告。</span>';
      focusFirstTestInput();
    }
  })();
}

function focusFirstTestInput() {
  setTimeout(function() {
    var firstInput = document.querySelector('#testItemsContainer input.form-control');
    if (firstInput && typeof firstInput.focus === 'function') {
      firstInput.focus();
      if (typeof firstInput.scrollIntoView === 'function') {
        firstInput.scrollIntoView({ behavior: 'smooth', block: 'center' });
      }
    }
  }, 80);
}

function fileToBase64(file) {
  return new Promise(function(resolve, reject) {
    var reader = new FileReader();
    reader.onload = function() { resolve(reader.result); };
    reader.onerror = reject;
    reader.readAsDataURL(file);
  });
}

function compressImage(base64DataUrl, maxSize) {
  return new Promise(function(resolve, reject) {
    var img = new Image();
    img.onload = function() {
      var canvas = document.createElement('canvas');
      var w = img.width, h = img.height;
      // Limit to maxSize
      if (w > maxSize || h > maxSize) {
        if (w > h) { h = Math.round(h * maxSize / w); w = maxSize; }
        else { w = Math.round(w * maxSize / h); h = maxSize; }
      }
      canvas.width = w;
      canvas.height = h;
      var ctx = canvas.getContext('2d');
      ctx.fillStyle = '#ffffff';
      ctx.fillRect(0, 0, w, h);
      ctx.drawImage(img, 0, 0, w, h);
      resolve(canvas.toDataURL('image/jpeg', 0.8));
    };
    img.onerror = function() { reject(new Error('当前图片格式不兼容')); };
    img.src = base64DataUrl;
  });
}

function normalizeOCRFieldKey(key) {
  var fieldMap = {
    'height': 'height',
    'weight': 'weight',
    'standing_jump': 'standing_jump',
    'continuous_jump': 'continuous_jump',
    'jump_rope': 'rope_skip',
    'rope_skip': 'rope_skip',
    'tennis_throw': 'tennis_throw',
    'grip_strength': 'tennis_throw',
    'situp': 'sit_ups',
    'sit_ups': 'sit_ups',
    'sit_reach': 'sit_reach',
    'balance_beam': 'balance_beam',
    'step_test': 'step_test',
    'vital_capacity': 'step_test',
    'shuttle_run': 'shuttle_run',
    'obstacle_run_15m': 'shuttle_run',
    'shuttle_run_4x10': 'shuttle_run_4x10'
  };
  return fieldMap[key] || null;
}

function normalizeOCRData(data) {
  var normalized = {};
  Object.keys(data || {}).forEach(function(key) {
    var isRating = key.slice(-7) === '_rating';
    var baseKey = isRating ? key.slice(0, -7) : key;
    var normalizedKey = normalizeOCRFieldKey(baseKey);
    if (!normalizedKey) return;
    normalized[normalizedKey + (isRating ? '_rating' : '')] = data[key];
  });
  return normalized;
}

function buildOCRPrompt() {
  var isPreschool = state.ageGroup === 'preschool';
  var groupLabel = isPreschool ? '3-6岁幼儿组' : '7-12岁学龄组';
  var groupItems = isPreschool
    ? [
        '- 立定跳远(cm) -> standing_jump / standing_jump_rating',
        '- 双脚连续跳(秒) -> continuous_jump / continuous_jump_rating',
        '- 网球掷远(m) -> tennis_throw / tennis_throw_rating',
        '- 坐位体前屈(cm) -> sit_reach / sit_reach_rating',
        '- 走平衡木(秒) -> balance_beam / balance_beam_rating',
        '- 10米折返跑(秒) -> shuttle_run / shuttle_run_rating'
      ].join('\n')
    : [
        '- 立定跳远(cm) -> standing_jump / standing_jump_rating',
        '- 一分钟跳绳(次) -> jump_rope / jump_rope_rating',
        '- 仰卧起坐(次) -> situp / situp_rating',
        '- 坐位体前屈(cm) -> sit_reach / sit_reach_rating',
        '- 台阶测试(次) -> step_test / step_test_rating',
        '- 4x10米往返跑(秒) -> shuttle_run_4x10 / shuttle_run_4x10_rating'
      ].join('\n');

  return '请从这张儿童体测报告中提取以下数据，并以JSON格式返回，只返回JSON不要其他文字：\n\n' +
    '当前只识别“' + groupLabel + '”项目，不属于该年龄段的项目一律返回 null，不要猜测，不要混填。\n' +
    '重要：请逐项识别测试值和图片上原始评级标签，评级文字优先以图片原文为准，不要自行改写。学龄组常见评级可能是"优秀""良好""中等""欠佳""较差"，身体形态项目常见评级可能是"标准""正常""偏高""偏低""偏胖""偏瘦"。\n\n' +
    '1. 儿童基本信息：姓名、性别、年龄（岁和月）、测试日期\n' +
    '2. 身体形态：身高(cm)、体重(kg)\n' +
    '3. 当前年龄段项目及评级：\n' + groupItems + '\n\n' +
    '评级从图片中直接读取，必须是以下之一：优秀、良好、中等、合格、一般、较差、欠佳、标准、偏胖、偏瘦、正常、偏高、偏低、待提升。如果图片中某项没有评级，设为null。\n\n' +
    '注意：评级非常重要，请务必仔细识别每一项的评级文字，不要遗漏，不要把"中等"改写成"合格"，也不要把"标准/正常"改写成"优秀"。\n\n' +
    'JSON格式：\n{\n' +
    '  "name": "姓名",\n' +
    '  "gender": "男/女",\n' +
    '  "ageYears": 数字,\n' +
    '  "ageMonths": 数字,\n' +
    '  "testDate": "YYYY-MM-DD",\n' +
    '  "height": 数字,\n' +
    '  "height_rating": "评级或null",\n' +
    '  "weight": 数字,\n' +
    '  "weight_rating": "评级或null",\n' +
    '  "standing_jump": 数字,\n' +
    '  "standing_jump_rating": "评级或null",\n' +
    '  "continuous_jump": 数字,\n' +
    '  "continuous_jump_rating": "评级或null",\n' +
    '  "jump_rope": 数字,\n' +
    '  "jump_rope_rating": "评级或null",\n' +
    '  "tennis_throw": 数字,\n' +
    '  "tennis_throw_rating": "评级或null",\n' +
    '  "situp": 数字,\n' +
    '  "situp_rating": "评级或null",\n' +
    '  "sit_reach": 数字,\n' +
    '  "sit_reach_rating": "评级或null",\n' +
    '  "balance_beam": 数字,\n' +
    '  "balance_beam_rating": "评级或null",\n' +
    '  "step_test": 数字,\n' +
    '  "step_test_rating": "评级或null",\n' +
    '  "shuttle_run": 数字,\n' +
    '  "shuttle_run_rating": "评级或null",\n' +
    '  "shuttle_run_4x10": 数字,\n' +
    '  "shuttle_run_4x10_rating": "评级或null"\n' +
    '}\n\n' +
    '如果某项数据无法识别，请设为null。只返回JSON。';
}

async function callDeepSeekVisionAPI(base64Image) {
  var compressed = await compressImage(base64Image, 1600);

  var response = await fetch('/api/ai-services.php', {
    method: 'POST',
    headers: buildAuthHeaders({
      'Content-Type': 'application/json'
    }),
    body: JSON.stringify({
      action: 'ocr',
      imageDataUrl: compressed,
      prompt: buildOCRPrompt()
    })
  });

  if (!response.ok) {
    var errData = {};
    try { errData = await response.json(); } catch(e) {}
    var errMsg = errData.error ? errData.error : ('HTTP ' + response.status);
    var error = new Error(errMsg);
    error.status = response.status;
    throw error;
  }

  var data = await response.json();
  return data.result || null;
}

function fillFormFromOCR(data) {
  if (data.name) document.getElementById('childName').value = data.name;
  if (data.gender) {
    // Clear previous selection first
    document.querySelectorAll('.gender-option').forEach(function(opt) { opt.classList.remove('selected'); });
    document.querySelectorAll('.gender-option').forEach(function(opt) {
      if (opt.textContent.indexOf(data.gender) !== -1) {
        opt.classList.add('selected');
        state.gender = data.gender;
      }
    });
  }
  if (data.ageYears) document.getElementById('ageYears').value = data.ageYears;
  if (data.ageMonths) document.getElementById('ageMonths').value = data.ageMonths;
  if (data.testDate) document.getElementById('testDate').value = data.testDate;

  var normalizedData = normalizeOCRData(data);

  // Save image ratings to state (图片评级，以图片结果为准)
  state.imageRatings = {};
  Object.keys(normalizedData).forEach(function(key) {
    if (key.slice(-7) === '_rating' && normalizedData[key] !== undefined && normalizedData[key] !== null) {
      state.imageRatings[key.slice(0, -7)] = normalizedData[key];
    }
  });

  Object.keys(normalizedData).forEach(function(key) {
    if (key.slice(-7) !== '_rating' && normalizedData[key] !== undefined && normalizedData[key] !== null) {
      var input = document.getElementById('input_' + key);
      if (input) {
        input.value = normalizedData[key];
        autoRate(key);
      }
    }
  });
}

// ==================== Backend AI Status ====================

function buildStatusCard(title, ready, readyText, fallbackText) {
  var color = ready ? '#27AE60' : '#F39C12';
  var text = ready ? readyText : fallbackText;
  return '<div style="background:#fff;border:1px solid rgba(0,0,0,0.06);border-radius:12px;padding:14px;">' +
    '<div style="font-size:13px;color:var(--text-light);margin-bottom:8px;">' + title + '</div>' +
    '<div style="font-size:14px;font-weight:700;color:' + color + ';">' + text + '</div>' +
    '</div>';
}

function loadExternalScript(src) {
  return new Promise(function(resolve, reject) {
    var existing = document.querySelector('script[data-src="' + src + '"]');
    if (existing) {
      if (existing.dataset.loaded === 'true') {
        resolve();
        return;
      }
      existing.addEventListener('load', function onLoad() {
        existing.removeEventListener('load', onLoad);
        resolve();
      });
      existing.addEventListener('error', function onError() {
        existing.removeEventListener('error', onError);
        reject(new Error('Failed to load ' + src));
      });
      return;
    }

    var script = document.createElement('script');
    script.src = src;
    script.async = true;
    script.dataset.src = src;
    script.onload = function() {
      script.dataset.loaded = 'true';
      resolve();
    };
    script.onerror = function() {
      reject(new Error('Failed to load ' + src));
    };
    document.head.appendChild(script);
  });
}

function ensureExportLibraries() {
  var tasks = [];
  if (typeof html2canvas === 'undefined') tasks.push(loadExternalScript(HTML2CANVAS_CDN));
  if (!window.jspdf || !window.jspdf.jsPDF) tasks.push(loadExternalScript(JSPDF_CDN));
  return Promise.all(tasks);
}

function buildTrainingPlanExportCards(sourceCard) {
  if (!sourceCard) return [];

  var cards = [];
  var title = sourceCard.querySelector('h3');
  var planContainer = sourceCard.querySelector('#trainingPlanContent');
  var children = planContainer ? Array.prototype.slice.call(planContainer.children).filter(function(node) {
    return node.nodeType === 1;
  }) : [];

  if (!planContainer || children.length === 0) {
    cards.push(sourceCard.cloneNode(true));
    return cards;
  }

  var sections = [];
  var currentSection = [];
  children.forEach(function(node) {
    if (node.tagName === 'H4' && currentSection.length > 0) {
      sections.push(currentSection);
      currentSection = [];
    }
    currentSection.push(node.cloneNode(true));
  });
  if (currentSection.length > 0) {
    sections.push(currentSection);
  }

  sections.forEach(function(sectionNodes, index) {
    var card = document.createElement('div');
    card.className = 'report-card pdf-training-card';
    if (title) {
      card.appendChild(title.cloneNode(true));
    }

    var analysis = document.createElement('div');
    analysis.className = 'ai-analysis';
    if (sections.length > 1 && index > 0) {
      analysis.style.marginTop = '6px';
    }

    sectionNodes.forEach(function(node) {
      analysis.appendChild(node);
    });

    card.appendChild(analysis);
    cards.push(card);
  });

  return cards;
}

function createPdfExportStage(reportEl) {
  var stage = document.createElement('div');
  stage.className = 'pdf-export-stage';

  var header = reportEl.querySelector('.report-header');
  var reportInfo = reportEl.querySelector('#reportInfo');
  var reportSummary = reportEl.querySelector('#reportSummary');
  var reportTrainingPlan = reportEl.querySelector('#reportTrainingPlan');
  var reportFooter = reportEl.querySelector('.report-footer');

  if (header) stage.appendChild(header.cloneNode(true));
  if (reportInfo) stage.appendChild(reportInfo.cloneNode(true));
  if (reportSummary) stage.appendChild(reportSummary.cloneNode(true));

  buildTrainingPlanExportCards(reportTrainingPlan).forEach(function(card) {
    stage.appendChild(card);
  });

  if (reportFooter) stage.appendChild(reportFooter.cloneNode(true));
  document.body.appendChild(stage);
  return stage;
}

function cleanupPdfExportStage(stage) {
  if (stage && stage.parentNode) {
    stage.parentNode.removeChild(stage);
  }
}

function getExportFileName() {
  return '追光小牛运动成长规划_' + getDisplayName() + '_' + getStateText(state.testDate, new Date().toISOString().slice(0, 10)) + '.pdf';
}

function shouldPreferClientPdfExport() {
  var ua = navigator.userAgent || '';
  return /Android|iPhone|iPad|iPod|HarmonyOS|Mobile/i.test(ua);
}

async function exportPdfWithCanvas() {
  var reportEl = document.getElementById('reportContainer');
  if (!reportEl) {
    throw new Error('报告内容不存在');
  }

  await ensureExportLibraries();

  var stage = createPdfExportStage(reportEl);
  document.documentElement.classList.add('pdf-export-mode');

  try {
    var canvas = await html2canvas(stage, {
      backgroundColor: '#ffffff',
      scale: Math.min(window.devicePixelRatio || 1, 2),
      useCORS: true,
      logging: false
    });

    var jsPDFCtor = window.jspdf && window.jspdf.jsPDF;
    if (!jsPDFCtor) {
      throw new Error('PDF 库未加载');
    }

    var pdf = new jsPDFCtor('p', 'mm', 'a4');
    var pageWidth = pdf.internal.pageSize.getWidth();
    var pageHeight = pdf.internal.pageSize.getHeight();
    var canvasWidth = canvas.width;
    var pageCanvasHeight = Math.max(1, Math.floor(canvasWidth * (pageHeight / pageWidth)));
    var pageCanvas = document.createElement('canvas');
    var pageCtx = pageCanvas.getContext('2d');
    var renderedHeight = 0;
    var pageIndex = 0;

    pageCanvas.width = canvasWidth;

    while (renderedHeight < canvas.height) {
      var sliceHeight = Math.min(pageCanvasHeight, canvas.height - renderedHeight);
      pageCanvas.height = sliceHeight;
      pageCtx.fillStyle = '#ffffff';
      pageCtx.fillRect(0, 0, pageCanvas.width, pageCanvas.height);
      pageCtx.drawImage(
        canvas,
        0,
        renderedHeight,
        canvas.width,
        sliceHeight,
        0,
        0,
        pageCanvas.width,
        sliceHeight
      );

      if (pageIndex > 0) {
        pdf.addPage();
      }

      var imageData = pageCanvas.toDataURL('image/jpeg', 0.95);
      var imageHeight = sliceHeight * pageWidth / canvasWidth;
      pdf.addImage(imageData, 'JPEG', 0, 0, pageWidth, imageHeight, undefined, 'FAST');

      renderedHeight += sliceHeight;
      pageIndex++;
    }

    pdf.save(getExportFileName());
  } finally {
    document.documentElement.classList.remove('pdf-export-mode');
    cleanupPdfExportStage(stage);
  }
}

async function updateCloudStatusBadge() {
  var badge = document.getElementById('cloudStatusBadge');
  if (!badge) return;

  badge.innerHTML = '正在检查记录接口';

  try {
    var ctrl = new AbortController();
    var tid = setTimeout(function() { ctrl.abort(); }, 2000);
    var res = await fetch('/api/records/', {
      signal: ctrl.signal,
      headers: buildAuthHeaders()
    });
    clearTimeout(tid);
    if (res.ok) {
      badge.innerHTML = '☁️ 记录接口已连接';
      badge.style.color = '#27AE60';
      badge.style.background = 'rgba(39,174,96,0.15)';
    } else if (res.status === 401 || res.status === 403) {
      badge.innerHTML = '☁️ 记录接口可用，请先登录';
      badge.style.color = '#F39C12';
      badge.style.background = 'rgba(243,156,18,0.15)';
    } else {
      badge.innerHTML = '服务器记录接口未连接';
      badge.style.color = '#F39C12';
    }
  } catch (e) {
    badge.innerHTML = '服务器记录接口未连接';
    badge.style.color = '#F39C12';
  }
}

async function loadAiServiceStatus() {
  var statusWrap = document.getElementById('fitnessAiStatus');
  var guide = document.getElementById('fitnessAiGuide');
  if (!statusWrap || !guide) return;

  try {
    var ctrl = new AbortController();
    var tid = setTimeout(function() { ctrl.abort(); }, 2000);
    var res = await fetch('/api/ai-services.php', {
      signal: ctrl.signal,
      headers: buildAuthHeaders()
    });
    clearTimeout(tid);
    if (!res.ok) throw new Error('HTTP ' + res.status);
    var data = await res.json();

    statusWrap.innerHTML =
      buildStatusCard('图片识别', !!data.ocrReady, '已开通，可直接拍照识别', '未开通，请先手动录入') +
      buildStatusCard('AI 报告', !!data.reportAiReady, '已开通，生成专业报告', '未开通，将使用基础分析') +
      buildStatusCard('记录保存', !!data.recordsReady, '已开通，自动汇总统计', '未开通，请先检查服务器');

    var guideText = [];
    guideText.push(data.ocrReady ? '可直接拍照识别。' : '图片识别未开通，先手动录入。');
    guideText.push(data.reportAiReady ? '报告可直接生成。' : '报告将使用基础分析。');
    guide.innerHTML = guideText.join('<br>');
  } catch (error) {
    statusWrap.innerHTML =
      buildStatusCard('图片识别', false, '', '状态未知') +
      buildStatusCard('AI 报告', false, '', '状态未知') +
      buildStatusCard('记录保存', false, '', '状态未知');
    guide.innerHTML = '暂时无法读取后台状态，可先继续录入。';
  }
}

// ==================== PDF Export ====================

async function exportPDF() {
  var btn = event.target;
  btn.disabled = true;
  btn.innerHTML = '⏳ 正在准备导出...';

  try {
    if (shouldPreferClientPdfExport()) {
      await exportPdfWithCanvas();
    } else {
      var originalTitle = document.title;
      document.title = getExportFileName().replace(/\.pdf$/i, '');
      try {
        if (typeof window.print !== 'function') {
          throw new Error('print_not_supported');
        }
        window.print();
      } catch (printError) {
        await exportPdfWithCanvas();
      } finally {
        document.title = originalTitle;
      }
    }
  } catch (err) {
    console.error('PDF export error:', err);
    alert('导出失败，请重试；如仍失败，请使用系统浏览器打开后再导出');
  } finally {
    btn.disabled = false;
    btn.innerHTML = '📄 导出 PDF（打印保存）';
  }
}

// ==================== Reset ====================

function resetAll() {
  state.ageGroup = '';
  state.gender = '';
  state.name = '';
  state.ageYears = 0;
  state.ageMonths = 0;
  state.testDate = '';
  state.testData = {};
  state.imageRatings = {};
  state.currentStep = 1;
  state._deepSeekContent = null;
  state._ocrData = null;

  // Reset form fields
  document.getElementById('childName').value = '';
  document.getElementById('ageYears').value = '';
  document.getElementById('ageMonths').value = '';
  document.querySelectorAll('.gender-option').forEach(function(o) { o.classList.remove('selected'); });
  document.querySelectorAll('.age-card').forEach(function(c) { c.classList.remove('selected'); });
  document.getElementById('btnStep2Next').disabled = true;

  // Reset tags
  document.querySelectorAll('.tag-capsule.selected').forEach(function(t) { t.classList.remove('selected'); });

  // Reset selects
  document.getElementById('weeklyFrequency').value = '2次/周';
  document.getElementById('participation').value = '表现良好';
  document.getElementById('fitnessLevel').value = '良好';
  document.getElementById('skillLevel').value = '基本掌握';
  document.getElementById('trainingCycle').value = '3个月';
  document.getElementById('personality').value = '';
  document.getElementById('coachNotes').value = '';
  document.getElementById('coachStore').value = '';

  // Reset custom goal inputs
  document.getElementById('fitnessGoalCustom').value = '';
  document.getElementById('skillGoalCustom').value = '';
  document.getElementById('characterGoalCustom').value = '';
  document.getElementById('socialGoalCustom').value = '';
  document.getElementById('currentCycleFocus').value = '';
  document.getElementById('nextCycleFocus').value = '';

  // Reset image preview and OCR status
  var imagePreview = document.getElementById('imagePreview');
  if (imagePreview) imagePreview.innerHTML = '';
  var ocrStatus = document.getElementById('ocrStatus');
  if (ocrStatus) ocrStatus.innerHTML = '';
  var imageInput = document.getElementById('reportImageInput');
  if (imageInput) imageInput.value = '';

  // Reset report
  document.getElementById('reportContainer').classList.remove('active');
  document.getElementById('loadingOverlay').classList.remove('active');

  goToStep(1);
}

// ==================== Statistics ====================

function getSelectedTagValues(containerId) {
  return getSelectedTags(containerId);
}

function saveReportRecord(results) {
  try {
    var coachContext = {
      weekly_frequency: document.getElementById('weeklyFrequency').value,
      training_focus: getSelectedTagValues('trainingFocusTags'),
      participation: document.getElementById('participation').value,
      fitness_level: document.getElementById('fitnessLevel').value,
      skill_level: document.getElementById('skillLevel').value,
      personality: document.getElementById('personality').value.trim(),
      coach_notes: document.getElementById('coachNotes').value.trim()
    };
    var goals = {
      fitness: getSelectedTagValues('fitnessGoalTags'),
      fitness_custom: document.getElementById('fitnessGoalCustom').value.trim(),
      skill: getSelectedTagValues('skillGoalTags'),
      skill_custom: document.getElementById('skillGoalCustom').value.trim(),
      character: getSelectedTagValues('characterGoalTags'),
      character_custom: document.getElementById('characterGoalCustom').value.trim(),
      social: getSelectedTagValues('socialGoalTags'),
      social_custom: document.getElementById('socialGoalCustom').value.trim(),
      training_cycle: document.getElementById('trainingCycle').value,
      current_cycle_focus: document.getElementById('currentCycleFocus').value.trim(),
      next_cycle_focus: document.getElementById('nextCycleFocus').value.trim()
    };
    var record = {
      coach_name: document.getElementById('coachName').value.trim() || '未填写',
      coach_store: document.getElementById('coachStore').value || '未选择',
      child_name: state.name || '未填写',
      child_age: (state.ageYears || '?') + '岁' + (state.ageMonths || 0) + '个月',
      test_date: state.testDate || new Date().toISOString().slice(0, 10),
      age_group: state.ageGroup,
      gender: state.gender,
      test_data: state.testData,
      image_ratings: state.imageRatings || {},
      assessment_items: (results || []).map(function(item) {
        return { id: item.id, name: item.name, unit: item.unit, value: item.value, label: item.label || '', level: item.level || '' };
      }),
      coach_context: coachContext,
      goals: goals,
      report_content: (document.getElementById('trainingPlanContent').innerHTML || '').slice(0, 200000),
      generation_mode: state._reportMode === 'ai' ? 'ai' : 'fallback',
      report_status: 'completed'
    };

    // Save to server API only. The intranet login is now the single entry point,
    // so we no longer keep report statistics in localStorage on the browser.
    fetch('/api/records/', {
      method: 'POST',
      headers: buildAuthHeaders({ 'Content-Type': 'application/json' }),
      body: JSON.stringify(record)
    }).then(function(res) {
      if (res.ok) console.log('[Stats] Complete report record saved.');
      else if (res.status === 401 || res.status === 403) console.warn('[Stats] Login required before saving record.');
      else console.warn('[Stats] Server save failed:', res.status);
    }).catch(function() {
      console.log('[Stats] Server unavailable, skip local persistence.');
    });
  } catch (e) {
    console.error('[Stats] Failed to save report record:', e);
  }
}

var reportHistory = { page: 1, pageSize: 20, total: 0, request: 0, filters: '' };

async function showStats() {
  reportHistory.page = 1;
  var html = '<div style="max-width:760px;margin:0 auto;padding:20px;">' +
    '<h2 style="text-align:center;margin-bottom:8px;">历史体测报告</h2>' +
    '<div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:16px;">' +
    '<input id="recordDateFrom" type="date" class="form-control" aria-label="开始日期">' +
    '<input id="recordDateTo" type="date" class="form-control" aria-label="结束日期">' +
    '<input id="recordStoreFilter" class="form-control" placeholder="门店" aria-label="门店筛选">' +
    '<input id="recordCoachFilter" class="form-control" placeholder="教练" aria-label="教练筛选">' +
    '<input id="recordStudentFilter" class="form-control" placeholder="学员" aria-label="学员筛选">' +
    '<select id="recordStatusFilter" class="form-control" aria-label="报告状态"><option value="">全部状态</option><option value="completed">已完成</option><option value="fallback">本地兜底</option></select>' +
    '</div>' +
    '<div style="text-align:center;margin-bottom:16px;"><button class="btn btn-primary btn-sm" onclick="loadReportHistory()">查询记录</button></div>' +
    '<div id="recordHistoryList" aria-live="polite" style="display:grid;gap:10px;"></div>' +
    '<div id="recordHistoryPagination" aria-live="polite" style="text-align:center;margin-top:12px;"></div>' +
    '<div style="text-align:center;margin-top:20px;"><button class="btn btn-outline" onclick="closeStats()">返回</button></div></div>';
  document.getElementById('statsContainer').innerHTML = html;
  document.getElementById('statsPage').style.display = 'block';
  document.getElementById('appContainer').style.display = 'none';
  loadReportHistory();
  ['recordDateFrom', 'recordDateTo', 'recordStoreFilter', 'recordCoachFilter', 'recordStudentFilter', 'recordStatusFilter'].forEach(function(id) {
    document.getElementById(id).addEventListener('change', function() { reportHistory.page = 1; loadReportHistory(); });
  });
}

function changeReportPage(page) {
  if (page < 1 || page > Math.ceil(reportHistory.total / reportHistory.pageSize)) return;
  reportHistory.page = page;
  loadReportHistory();
}

async function loadReportHistory() {
  var params = new URLSearchParams();
  [['date_from', 'recordDateFrom'], ['date_to', 'recordDateTo'], ['store', 'recordStoreFilter'], ['coach', 'recordCoachFilter'], ['student', 'recordStudentFilter'], ['status', 'recordStatusFilter']].forEach(function(pair) {
    var input = document.getElementById(pair[1]);
    if (input && input.value.trim()) params.set(pair[0], input.value.trim());
  });
  if (reportHistory.filters !== params.toString()) reportHistory.page = 1;
  reportHistory.filters = params.toString();
  var page = reportHistory.page, request = ++reportHistory.request;
  params.set('page', page);
  params.set('page_size', reportHistory.pageSize);
  var list = document.getElementById('recordHistoryList');
  if (!list) return;
  var pagination = document.getElementById('recordHistoryPagination');
  pagination.innerHTML = '';
  list.innerHTML = '<div style="text-align:center;color:#777;padding:20px;">正在加载记录...</div>';
  try {
    var res = await fetch('/api/records/?' + params.toString(), { headers: buildAuthHeaders() });
    if (!res.ok) throw new Error(res.status === 401 ? '请先登录后查看记录' : '记录加载失败');
    var records = await res.json();
    if (request !== reportHistory.request) return;
    var totalHeader = res.headers.get('X-Records-Total');
    if (!Array.isArray(records) || totalHeader === null || !/^\d+$/.test(totalHeader)) throw new Error('记录分页数据不完整，请重试');
    reportHistory.total = Number(totalHeader);
    var pages = Math.max(1, Math.ceil(reportHistory.total / reportHistory.pageSize));
    if (page > pages) { reportHistory.page = pages; return loadReportHistory(); }
    pagination.innerHTML = '<button class="btn btn-outline btn-sm" onclick="changeReportPage(' + (page - 1) + ')"' + (page <= 1 ? ' disabled' : '') + '>上一页</button> ' + page + ' / ' + pages + ' 页，共 ' + reportHistory.total + ' 条 <button class="btn btn-outline btn-sm" onclick="changeReportPage(' + (page + 1) + ')"' + (page >= pages ? ' disabled' : '') + '>下一页</button>';
    if (records.length === 0) {
      list.innerHTML = '<div style="text-align:center;color:#777;padding:20px;">暂无符合条件的记录</div>';
      return;
    }
    list.innerHTML = records.map(function(record) {
      var modeText = record.generation_mode === 'ai' ? 'AI 报告' : '本地分析版';
      var completeness = record.data_completeness === 'complete' ? '完整报告' : '摘要记录';
      return '<button type="button" class="btn btn-outline" style="text-align:left;background:#fff;padding:14px;" onclick="showReportDetail(\'' + encodeURIComponent(record.id || '') + '\')">' +
        '<strong>' + escapeHtml(record.child_name || '未填写') + '</strong> · ' + escapeHtml(record.test_date || '') + '<br>' +
        '<span style="font-size:12px;color:#777;">' + escapeHtml(record.coach_store || '未选择') + ' · ' + escapeHtml(record.coach_name || '未填写') + ' · ' + modeText + ' · ' + completeness + '</span></button>';
    }).join('');
  } catch (error) {
    if (request !== reportHistory.request) return;
    list.innerHTML = '<div style="text-align:center;color:#b42318;padding:20px;">' + escapeHtml(error.message || '记录加载失败') + '</div>';
  }
}

async function showReportDetail(encodedId) {
  var id = decodeURIComponent(encodedId || '');
  if (!id) return;
  var request = ++reportHistory.request;
  document.getElementById('recordHistoryPagination').innerHTML = '';
  var list = document.getElementById('recordHistoryList');
  try {
    var res = await fetch('/api/records/?id=' + encodeURIComponent(id), { headers: buildAuthHeaders() });
    if (!res.ok) throw new Error(res.status === 403 ? '无权查看该报告' : '报告详情加载失败');
    var record = await res.json();
    if (request !== reportHistory.request) return;
    var items = Array.isArray(record.assessment_items) ? record.assessment_items : [];
    var metrics = items.length ? '<h4>体测数据</h4><div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;">' + items.map(function(item) {
      return '<div style="padding:8px;background:#f8f8f8;border-radius:8px;"><strong>' + escapeHtml(item.name || item.id || '') + '</strong><br>' + escapeHtml(item.value === undefined || item.value === null ? '未填写' : String(item.value)) + ' ' + escapeHtml(item.unit || '') + ' · ' + escapeHtml(item.label || '未评级') + '</div>';
    }).join('') + '</div>' : '<p>该历史记录只有摘要信息。</p>';
    var reportHtml = record.report_content ? '<h4>运动规划</h4><div class="ai-analysis">' + sanitizeReportHtml(record.report_content) + '</div>' : '<p>暂无完整报告正文。</p>';
    list.innerHTML = '<div style="background:#fff;padding:16px;border-radius:12px;line-height:1.7;">' +
      '<button class="btn btn-outline btn-sm" onclick="loadReportHistory()">← 返回列表</button>' +
      '<h3>' + escapeHtml(record.child_name || '未填写') + ' 的体测报告</h3>' +
      '<p style="color:#666;">' + escapeHtml(record.test_date || '') + ' · ' + escapeHtml(record.coach_store || '未选择') + ' · ' + escapeHtml(record.coach_name || '未填写') + '</p>' +
      metrics + reportHtml + '</div>';
  } catch (error) {
    if (request !== reportHistory.request) return;
    list.innerHTML = '<div style="text-align:center;color:#b42318;padding:20px;">' + escapeHtml(error.message || '报告详情加载失败') + '</div>';
  }
}

function sanitizeReportHtml(content) {
  var template = document.createElement('template');
  template.innerHTML = String(content || '');
  template.content.querySelectorAll('script,style,iframe,object,embed,form').forEach(function(node) { node.remove(); });
  template.content.querySelectorAll('*').forEach(function(node) {
    Array.prototype.slice.call(node.attributes).forEach(function(attribute) {
      var name = attribute.name.toLowerCase();
      var value = attribute.value.trim().toLowerCase();
      if (name.indexOf('on') === 0 || (name === 'href' && value.indexOf('javascript:') === 0) || (name === 'src' && value.indexOf('javascript:') === 0)) {
        node.removeAttribute(attribute.name);
      }
    });
  });
  return template.innerHTML;
}

async function showLegacyStats() {
  // Load records from server API only.
  var records = [];
  var cloudStatus = '';

  try {
    var controller = new AbortController();
    var timeoutId = setTimeout(function() { controller.abort(); }, 3000);
    var res = await fetch('/api/records/', {
      signal: controller.signal,
      headers: buildAuthHeaders()
    });
    clearTimeout(timeoutId);
    if (res.ok) {
      var serverData = await res.json();
      records = serverData.map(function(r) {
        return {
          coachName: r.coach_name || '未填写',
          coachStore: r.coach_store || '未选择',
          childName: r.child_name || '未填写',
          childAge: r.child_age || '',
          date: r.test_date || '',
          timestamp: new Date(r.created_at).getTime()
        };
      });
      cloudStatus = '<span style="color:#27AE60;font-size:12px;">☁️ 服务器已连接（' + records.length + ' 条记录）</span>';
    } else if (res.status === 401 || res.status === 403) {
      cloudStatus = '<span style="color:#F39C12;font-size:12px;">记录接口可用，请先登录后查看统计</span>';
    }
  } catch (e) {
    console.log('[Stats] Server unavailable.');
  }

  if (records.length === 0 && !cloudStatus) {
    cloudStatus = '<span style="color:var(--text-light);font-size:12px;">记录接口未连接或暂无数据</span>';
  }

  var total = records.length;
  var today = new Date().toISOString().slice(0, 10);
  var todayCount = records.filter(function(r) { return r.date === today; }).length;
  var thisMonth = records.filter(function(r) {
    var d = new Date(r.date);
    var now = new Date();
    return d.getMonth() === now.getMonth() && d.getFullYear() === now.getFullYear();
  }).length;

  // ---- Coach stats: per coach -> per day + total ----
  var coachMap = {};
  records.forEach(function(r) {
    var name = r.coachName || '未填写';
    if (!coachMap[name]) coachMap[name] = { total: 0, days: {} };
    coachMap[name].total++;
    coachMap[name].days[r.date] = (coachMap[name].days[r.date] || 0) + 1;
  });

  // ---- Store stats: per store -> per day + total ----
  var storeMap = {};
  records.forEach(function(r) {
    var store = r.coachStore || '未选择';
    if (!storeMap[store]) storeMap[store] = { total: 0, days: {} };
    storeMap[store].total++;
    storeMap[store].days[r.date] = (storeMap[store].days[r.date] || 0) + 1;
  });

  // Helper: build a table for daily breakdown
  function buildDailyTable(daysObj) {
    var dates = Object.keys(daysObj).sort().reverse();
    if (dates.length === 0) return '';
    var h = '<table style="width:100%;border-collapse:collapse;font-size:13px;margin-top:8px;">';
    h += '<tr style="background:#f0f0f0;"><th style="padding:6px 8px;text-align:left;border-bottom:1px solid #ddd;">日期</th><th style="padding:6px 8px;text-align:center;border-bottom:1px solid #ddd;">份数</th></tr>';
    dates.forEach(function(d) {
      var isToday = d === today;
      h += '<tr><td style="padding:5px 8px;border-bottom:1px solid #eee;">' + d + (isToday ? ' <span style="color:#6C5CE7;font-size:11px;">今天</span>' : '') + '</td><td style="padding:5px 8px;text-align:center;border-bottom:1px solid #eee;font-weight:bold;">' + daysObj[d] + '</td></tr>';
    });
    h += '</table>';
    return h;
  }

  // ---- Build HTML ----
  var html = '<div style="max-width:640px;margin:0 auto;padding:20px;">';
  html += '<h2 style="text-align:center;margin-bottom:8px;">\uD83D\uDCCA 数据统计</h2>';
  html += '<div style="text-align:center;margin-bottom:16px;">' + (cloudStatus || '') + '</div>';

  // Overview cards
  html += '<div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px;margin-bottom:24px;">';
  html += '<div style="background:#f0f0ff;padding:14px;border-radius:12px;text-align:center;"><div style="font-size:26px;font-weight:bold;color:#6C5CE7;">' + total + '</div><div style="font-size:12px;color:#666;">累计总报告</div></div>';
  html += '<div style="background:#fff8f0;padding:14px;border-radius:12px;text-align:center;"><div style="font-size:26px;font-weight:bold;color:#F39C12;">' + thisMonth + '</div><div style="font-size:12px;color:#666;">本月报告</div></div>';
  html += '<div style="background:#f0fff4;padding:14px;border-radius:12px;text-align:center;"><div style="font-size:26px;font-weight:bold;color:#27AE60;">' + todayCount + '</div><div style="font-size:12px;color:#666;">今日报告</div></div>';
  html += '</div>';

  // ---- Coach section ----
  var coachNames = Object.keys(coachMap).sort(function(a, b) { return coachMap[b].total - coachMap[a].total; });
  html += '<div style="background:#f9f9f9;border-radius:12px;padding:16px;margin-bottom:16px;">';
  html += '<h3 style="margin:0 0 12px;font-size:16px;">\uD83D\uDC68\u200D\uD83C\uDFEB 按教练统计</h3>';
  if (coachNames.length > 0) {
    coachNames.forEach(function(name) {
      var info = coachMap[name];
      var pct = total > 0 ? Math.round(info.total / total * 100) : 0;
      html += '<div style="background:#fff;border-radius:10px;padding:12px;margin-bottom:10px;">';
      html += '<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:6px;">';
      html += '<span style="font-size:15px;font-weight:600;">' + name + '</span>';
      html += '<span style="font-size:13px;color:#6C5CE7;font-weight:bold;">累计 ' + info.total + ' 份</span>';
      html += '</div>';
      html += '<div style="background:#e8e8e8;border-radius:4px;height:6px;margin-bottom:8px;"><div style="background:#6C5CE7;border-radius:4px;height:6px;width:' + pct + '%;"></div></div>';
      html += buildDailyTable(info.days);
      html += '</div>';
    });
  } else {
    html += '<div style="text-align:center;padding:20px;color:#999;font-size:14px;">暂无教练报告数据</div>';
  }
  html += '</div>';

  // ---- Store section ----
  var storeNames = Object.keys(storeMap).sort(function(a, b) { return storeMap[b].total - storeMap[a].total; });
  html += '<div style="background:#f9f9f9;border-radius:12px;padding:16px;margin-bottom:16px;">';
  html += '<h3 style="margin:0 0 12px;font-size:16px;">\uD83C\uDFEA 按门店统计</h3>';
  if (storeNames.length > 0) {
    storeNames.forEach(function(store) {
      var info = storeMap[store];
      var pct = total > 0 ? Math.round(info.total / total * 100) : 0;
      html += '<div style="background:#fff;border-radius:10px;padding:12px;margin-bottom:10px;">';
      html += '<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:6px;">';
      html += '<span style="font-size:15px;font-weight:600;">' + store + '</span>';
      html += '<span style="font-size:13px;color:#F39C12;font-weight:bold;">累计 ' + info.total + ' 份</span>';
      html += '</div>';
      html += '<div style="background:#e8e8e8;border-radius:4px;height:6px;margin-bottom:8px;"><div style="background:#F39C12;border-radius:4px;height:6px;width:' + pct + '%;"></div></div>';
      html += buildDailyTable(info.days);
      html += '</div>';
    });
  } else {
    html += '<div style="text-align:center;padding:20px;color:#999;font-size:14px;">暂无门店报告数据</div>';
  }
  html += '</div>';

  if (total === 0) {
    html += '<div style="text-align:center;padding:10px 0 0;color:#999;font-size:13px;">生成报告后，教练和门店的详细数据将自动出现在上方</div>';
  }

  // Buttons
  html += '<div style="text-align:center;margin-top:20px;">';
  html += '<button class="btn btn-outline" onclick="closeStats()">返回</button>';
  html += '</div>';
  html += '</div>';

  document.getElementById('statsContainer').innerHTML = html;
  document.getElementById('statsPage').style.display = 'block';
  document.getElementById('appContainer').style.display = 'none';
}

function closeStats() {
  document.getElementById('statsPage').style.display = 'none';
  document.getElementById('appContainer').style.display = 'block';
}

// ==================== Init ====================
document.addEventListener('DOMContentLoaded', function() {

  updateCloudStatusBadge();

  // Set default date to today
  var today = new Date().toISOString().split('T')[0];
  document.getElementById('testDate').value = today;

  loadAiServiceStatus();

  // Ensure step1 is active
  document.querySelectorAll('.step').forEach(function(s) { s.classList.remove('active'); });
  document.getElementById('step1').classList.add('active');
});
