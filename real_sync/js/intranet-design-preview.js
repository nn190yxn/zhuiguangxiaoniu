(function () {
  'use strict';

  var variants = {
    d: {
      index: '融合方案 D',
      name: '石墨信号橙 · 企业控制台',
      description: '第一种企业控制台结构与第三种信号橙配色融合，兼顾运营效率、品牌辨识度和全站扩展性。'
    },
    a: {
      index: '方案 A',
      name: '曜石电蓝 · 企业控制台',
      description: '高对比深色侧栏与电气蓝强调，严格网格承载高密度运营信息。'
    },
    b: {
      index: '方案 B',
      name: '海军蓝翡翠 · 协作矩阵',
      description: '稳定海军蓝与柔和翡翠绿，顶部导航和模块矩阵营造清晰、亲和的协作氛围。'
    },
    c: {
      index: '方案 C',
      name: '石墨信号橙 · 编辑工作台',
      description: '暖灰画布、石墨框架与信号橙，非对称版式强化品牌感和关键任务聚焦。'
    }
  };
  var keys = Object.keys(variants);
  var activeKey = 'd';
  var tabs = Array.prototype.slice.call(document.querySelectorAll('[data-preview-target]'));
  var mockups = Array.prototype.slice.call(document.querySelectorAll('[data-preview]'));
  var indexNode = document.getElementById('previewIndex');
  var nameNode = document.getElementById('previewName');
  var descriptionNode = document.getElementById('previewDescription');
  var chooseButton = document.getElementById('chooseButton');
  var selectionStatus = document.getElementById('selectionStatus');

  function selectedKey() {
    try { return window.localStorage.getItem('intranet-design-choice') || ''; } catch (error) { return ''; }
  }

  function renderSelection() {
    var selected = selectedKey();
    chooseButton.classList.toggle('is-selected', selected === activeKey);
    chooseButton.textContent = selected === activeKey ? '已标记为首选' : '标记为首选';
    selectionStatus.textContent = selected && variants[selected]
      ? '当前首选：' + variants[selected].name
      : '尚未标记首选方案';
  }

  function activate(key, updateHash) {
    if (!variants[key]) return;
    activeKey = key;
    tabs.forEach(function (tab) {
      var active = tab.dataset.previewTarget === key;
      tab.classList.toggle('is-active', active);
      tab.setAttribute('aria-pressed', String(active));
    });
    mockups.forEach(function (mockup) {
      var active = mockup.dataset.preview === key;
      mockup.hidden = !active;
      mockup.classList.toggle('is-active', active);
    });
    indexNode.textContent = variants[key].index;
    nameNode.textContent = variants[key].name;
    descriptionNode.textContent = variants[key].description;
    document.title = variants[key].name + ' | 员工内网设计预览';
    renderSelection();
    if (updateHash && window.history && window.history.replaceState) {
      window.history.replaceState(null, '', '#style-' + key);
    }
  }

  tabs.forEach(function (tab) {
    tab.addEventListener('click', function () { activate(tab.dataset.previewTarget, true); });
  });

  chooseButton.addEventListener('click', function () {
    try { window.localStorage.setItem('intranet-design-choice', activeKey); } catch (error) { /* Storage is optional. */ }
    renderSelection();
  });

  document.addEventListener('keydown', function (event) {
    if (event.key !== 'ArrowLeft' && event.key !== 'ArrowRight') return;
    var currentIndex = keys.indexOf(activeKey);
    var direction = event.key === 'ArrowRight' ? 1 : -1;
    activate(keys[(currentIndex + direction + keys.length) % keys.length], true);
  });

  var hashKey = window.location.hash.replace('#style-', '');
  activate(variants[hashKey] ? hashKey : 'd', false);
})();
