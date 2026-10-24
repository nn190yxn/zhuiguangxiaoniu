// ---- 原第 1 个内联 script 块 ----

var STORES=[{id:1,name:'未来方舟蘑菇城'},{id:2,name:'小河'},{id:3,name:'小十字'},{id:4,name:'未来方舟青少店'},{id:5,name:'玖福城'}];
var context=null;var templateItems=[];var valueMap={};var roleLocked=false;var currentReportId=0;var evidenceMap={};var storeMetricSummary={};var templateDescription='';var uploadState={};var pendingUploads={};var fieldErrors={};var operationState='';var operationTimer=null;var dirty=false;var reportState={completion_status:'missing',submit_status:'missing',is_writable:true,is_weekly_rest_day:false,deadline_at:'',last_saved_at:''};var dailyStatus=null;var activeScope='';var scopeRequestVersion=0;var activeDraftStore=null;var remoteDraftVersion=0;var remoteDraftTimer=null;var remoteDraftSync=Promise.resolve();
function today(){return new Date(Date.now()+8*60*60*1000).toISOString().slice(0,10)}
function syncDateLimit(){var el=document.getElementById('reportDate');if(!el)return;var max=today();el.max=max;if(!el.value||el.value>max)el.value=max;return max}
function setStatus(text,type){var el=document.getElementById('statusText');if(!el)return;el.textContent=text;el.className='status '+(type||'')}
function scopeKey(){return [document.getElementById('reportDate').value,document.getElementById('storeId').value,document.getElementById('roleCode').value].join('|')}
function createDraftStore(){
  var parts=scopeKey().split('|');
  if(!window.DraftStore||!parts[0]||!parts[1]||!parts[2])return null;
  return DraftStore.create({domain:'workload',objectType:'daily_report',objectId:parts.join(':'),schemaVersion:'1',allowedFields:['report_date','metrics','notes','attachments']});
}
function draftPayload(){
  var metrics={};
  templateItems.forEach(function(item){var el=document.getElementById('m_'+item.metric_code);metrics[item.metric_code]=el?el.value:''});
  var attachments=[];
  Object.keys(evidenceMap||{}).forEach(function(metricCode){(evidenceMap[metricCode]||[]).forEach(function(item){if(item&&item.id)attachments.push({id:Number(item.id),metric_code:metricCode})})});
  return{report_date:document.getElementById('reportDate').value,metrics:metrics,notes:document.getElementById('remarks').value||'',attachments:attachments};
}
function disabledReason(){if(reportState.is_weekly_rest_day)return'今日公休，日报填写入口已关闭';if(reportState.submit_status==='submitted'||reportState.completion_status==='submitted'||reportState.completion_status==='corrected')return'日报已提交';if(reportState.completion_status==='locked_missing')return'日报已锁定，请联系管理人员处理';if(reportState.is_writable===false)return'当前日报不可编辑，请刷新页面或联系管理人员';return''}
function beginOperation(name){if(operationState)return false;operationState=name;if(operationTimer)clearTimeout(operationTimer);operationTimer=setTimeout(function(){operationState='';setStatus('上一次操作等待超时，已恢复按钮，请检查网络后重试','err');updateActionState()},75000);updateActionState();return true}
function endOperation(){operationState='';if(operationTimer){clearTimeout(operationTimer);operationTimer=null}updateActionState()}
function updateActionState(){var reason=disabledReason();var writable=!reason;var busy=!!operationState;document.getElementById('draftButton').disabled=busy||!writable;document.getElementById('submitButton').disabled=busy||!writable;document.getElementById('busyNote').textContent=busy?({saving:'正在保存...',submitting:'正在提交...',uploading:'正在上传...',deleting:'正在删除...'}[operationState]||'正在处理...'):reason;Array.prototype.forEach.call(document.querySelectorAll('.evidence-btn,.evidence-delete,.retry-btn'),function(btn){btn.disabled=busy||!writable});}
function statusPresentation(){if(reportState.is_weekly_rest_day)return{label:'今日公休',type:'rest'};if(reportState.completion_status==='locked_missing'||reportState.is_writable===false)return{label:'已锁定',type:'locked'};if(reportState.submit_status==='submitted'||reportState.completion_status==='submitted'||reportState.completion_status==='corrected')return{label:'已提交',type:'submitted'};if(reportState.submit_status==='draft'||reportState.completion_status==='draft')return{label:'草稿待提交',type:'draft'};return{label:'未开始',type:''}}
function selectedDailyState(){if(!dailyStatus)return null;var date=document.getElementById('reportDate').value;if(dailyStatus.today&&dailyStatus.today.business_date===date)return dailyStatus.today;if(dailyStatus.yesterday_makeup&&dailyStatus.yesterday_makeup.business_date===date)return dailyStatus.yesterday_makeup;return null}
function formatPoints(value){var number=Number(value||0);return Number.isInteger(number)?String(number):number.toFixed(2)}
 function dailyAction(state){if(!state)return'当前日期暂无每日结算记录，提交后将按已发布规则计算点数。';if(state.status==='completed')return'今日已达标。有效 '+formatPoints(state.effective_points)+' 点，继续保持。';if(state.status==='makeup_open')return'上一工作日还差 '+formatPoints(state.gap_points)+' 点，请在 '+state.makeup_deadline_at+' 前补齐并重新提交。';if(state.status==='overdue'){var penalty=state.penalty;return '该日已逾期，还差 '+formatPoints(state.gap_points)+' 点'+(penalty?'，处罚 '+formatPoints(penalty.amount)+' 元，状态：'+penalty.status_label:'，处罚记录正在生成或待确认。')}return'今天还差 '+formatPoints(state.gap_points)+' 点，请在当日结束前完成并提交日报。'}
function renderDailyStatus(){var state=selectedDailyState();var target=state?Number(state.target_points||4):4;var effective=state?Number(state.effective_points||0):0;var reported=state?Number(state.reported_points||0):0;var pending=state?Number(state.pending_points||0):0;var gap=state?Number(state.gap_points||Math.max(0,target-effective)):target;document.getElementById('targetPoints').textContent=formatPoints(target);document.getElementById('progressText').textContent='有效 '+formatPoints(effective)+' 点，还差 '+formatPoints(gap)+' 点';document.getElementById('pointBreakdown').textContent='已报 '+formatPoints(reported)+' 点，待审核 '+formatPoints(pending)+' 点';document.getElementById('progressBar').style.width=Math.min(100,effective/Math.max(1,target)*100)+'%';var action=document.getElementById('dailyActionText');action.textContent=dailyAction(state);action.className='daily-note '+(state&&state.status==='completed'?'complete':state&&state.status==='overdue'?'overdue':'');var yesterday=dailyStatus&&dailyStatus.yesterday_makeup;document.getElementById('yesterdayMakeupText').textContent=yesterday&&yesterday.status==='makeup_open'?'昨日可补 '+formatPoints(yesterday.gap_points)+' 点，截止 '+yesterday.makeup_deadline_at:yesterday&&yesterday.status==='overdue'?'昨日已逾期，还差 '+formatPoints(yesterday.gap_points)+' 点'+(yesterday.penalty?'，处罚 '+formatPoints(yesterday.penalty.amount)+' 元，状态：'+yesterday.penalty.status_label:''):'昨日工作量：'+(yesterday?yesterday.status_label:'暂无记录');var summary=dailyStatus&&dailyStatus.monthly_penalty_summary;document.getElementById('penaltySummaryText').textContent=summary?'本月处罚：'+formatPoints(summary.amount)+' 元，待处理 '+formatPoints(summary.pending_amount)+' 元，共 '+summary.record_count+' 条记录':'本月暂无处罚记录';var rows=dailyStatus&&dailyStatus.conversion_table||[];document.getElementById('conversionTable').innerHTML=rows.length?rows.map(function(row){var metrics=(row.metrics||[]).join('、');var rule=row.description||((formatPoints(row.threshold_value)+' 个 '+metrics+' = '+formatPoints(row.points_per_unit)+' 点工作量'));return '<div class="conversion-row"><span>'+escapeHtml(metrics)+'</span><strong>'+escapeHtml(rule)+'</strong></div>'}).join(''):'当前岗位暂无已发布的换算规则';}
 function dailyAction(state){if(!state)return'当前日期暂无每日结算记录，提交后将按已发布规则计算点数。';if(Number(state.rejected_points||0)>0)return'有 '+formatPoints(state.rejected_points)+' 点已驳回，请查看审核意见并补充后重新提交。';if(state.status==='completed')return'今日已达标。有效 '+formatPoints(state.effective_points)+' 点，继续保持。';if(state.status==='makeup_open')return'昨天还差 '+formatPoints(state.gap_points)+' 点，请在 '+state.makeup_deadline_at+' 前补齐并重新提交。';if(state.status==='overdue'){var penalty=state.penalty;return '该日已逾期，还差 '+formatPoints(state.gap_points)+' 点'+(penalty?'，处罚 '+formatPoints(penalty.amount)+' 元，状态：'+penalty.status_label:'，处罚记录正在生成或待确认。')}return'今天还差 '+formatPoints(state.gap_points)+' 点，请在当日结束前完成并提交日报。'}
 var renderDailyStatusWithoutRejected=renderDailyStatus;renderDailyStatus=function(){renderDailyStatusWithoutRejected();var state=selectedDailyState();var rejected=Number(state&&state.rejected_points||0);document.getElementById('pointBreakdown').textContent='已报 '+formatPoints(state&&state.reported_points)+' 点，待审核 '+formatPoints(state&&state.pending_points)+' 点，已驳回 '+formatPoints(rejected)+' 点'};
  var originalDailyAction=dailyAction;dailyAction=function(state){return originalDailyAction(state).replaceAll('昨天','上一工作日').replaceAll('昨日','上一工作日')};var originalRenderDailyStatus=renderDailyStatus;renderDailyStatus=function(){originalRenderDailyStatus();var el=document.getElementById('yesterdayMakeupText');if(el)el.textContent=el.textContent.replaceAll('昨日','上一工作日')};
  function renderState(){var state=statusPresentation();var pill=document.getElementById('statePill');pill.textContent=state.label;pill.className='state-pill '+state.type;document.getElementById('deadlineText').textContent=reportState.deadline_at?'日报截止 '+reportState.deadline_at:'日报当日 24:00 截止';document.getElementById('saveMeta').textContent=dirty?'尚未保存':(reportState.last_saved_at?'最后保存 '+reportState.last_saved_at:'尚未保存');renderDailyStatus();updateActionState();}
function persistRecovery(){
  if(!templateItems.length)return null;
  activeDraftStore=createDraftStore();
  if(!activeDraftStore)return null;
  var payload=draftPayload();
  activeDraftStore.saveLocal(payload,{draftVersion:remoteDraftVersion,baseStateVersion:0});
  scheduleRemoteDraft(payload);
  return payload;
}
async function clearRecovery(){
  if(remoteDraftTimer){clearTimeout(remoteDraftTimer);remoteDraftTimer=null}
  var store=activeDraftStore||createDraftStore();
  if(!store)return;
  store.clearLocal();
  if(remoteDraftVersion>0&&navigator.onLine){
    try{await store.deleteRemote(remoteDraftVersion)}catch(err){}
  }
  remoteDraftVersion=0;
}
function markDirty(){dirty=true;persistRecovery();renderState()}
function applyDraftPayload(payload,message){
  payload=payload||{};
  valueMap=Object.assign({},valueMap,payload.metrics||{});
  document.getElementById('remarks').value=payload.notes||'';
  renderMetrics();
  dirty=true;
  if(message)setStatus(message,'ok');
  renderState();
}
function sameDraftPayload(left,right){
  try{return JSON.stringify(left||{})===JSON.stringify(right||{})}catch(err){return false}
}
async function chooseDraftVersion(localDraft,remoteDraft){
  if(!remoteDraft)return localDraft;
  var localPayload=localDraft&&localDraft.payload?localDraft.payload:draftPayload();
  var useRemote=window.confirm('检测到其他设备保存了不同版本的草稿。点击“确定”使用服务端版本，点击“取消”保留当前设备版本。');
  remoteDraftVersion=Number(remoteDraft.draft_version||0);
  if(useRemote){
    applyDraftPayload(remoteDraft.payload,'已恢复所选的服务端草稿，请核对后保存');
    activeDraftStore.saveLocal(remoteDraft.payload,{draftVersion:remoteDraftVersion,baseStateVersion:Number(remoteDraft.base_state_version||0)});
    return remoteDraft;
  }
  return persistRemoteDraft(localPayload,remoteDraftVersion,true);
}
async function persistRemoteDraft(payload,expectedVersion,fromConflict){
  var store=activeDraftStore||createDraftStore();
  if(!store||!navigator.onLine)return null;
  try{
    var saved=await store.saveRemote(payload,{draftVersion:Number(expectedVersion||0),baseStateVersion:0});
    if(saved){
      remoteDraftVersion=Number(saved.draft_version||0);
      store.saveLocal(saved.payload||payload,{draftVersion:remoteDraftVersion,baseStateVersion:Number(saved.base_state_version||0)});
    }
    return saved;
  }catch(err){
    var conflict=err&&(err.code==='draft_version_conflict'||err.recoveryAction==='choose_draft_version');
    if(conflict&&err.authoritativeState&&!fromConflict){
      return chooseDraftVersion(store.getLocal(),err.authoritativeState);
    }
    throw err;
  }
}
function scheduleRemoteDraft(payload){
  if(remoteDraftTimer)clearTimeout(remoteDraftTimer);
  if(!navigator.onLine)return;
  remoteDraftTimer=setTimeout(function(){
    remoteDraftTimer=null;
    remoteDraftSync=remoteDraftSync.then(function(){return persistRemoteDraft(payload,remoteDraftVersion,false)}).catch(function(){});
  },800);
}
async function reconcileDrafts(){
  activeDraftStore=createDraftStore();
  if(!activeDraftStore)return;
  var localDraft=activeDraftStore.getLocal();
  if(localDraft)applyDraftPayload(localDraft.payload,'已恢复本机尚未提交的草稿，请核对后保存');
  if(!navigator.onLine)return;
  try{
    var remoteDraft=await activeDraftStore.getRemote();
    if(!remoteDraft){
      remoteDraftVersion=0;
      if(localDraft)await persistRemoteDraft(localDraft.payload,0,false);
      return;
    }
    remoteDraftVersion=Number(remoteDraft.draft_version||0);
    if(!localDraft){
      applyDraftPayload(remoteDraft.payload,'已恢复服务端草稿，请核对后保存');
      activeDraftStore.saveLocal(remoteDraft.payload,{draftVersion:remoteDraftVersion,baseStateVersion:Number(remoteDraft.base_state_version||0)});
      return;
    }
    var fromAnotherDevice=remoteDraft.source_device_id&&remoteDraft.source_device_id!==DraftStore.getDeviceId();
    if(fromAnotherDevice&&!sameDraftPayload(localDraft.payload,remoteDraft.payload)){
      await chooseDraftVersion(localDraft,remoteDraft);
      return;
    }
    if(remoteDraftVersion>Number(localDraft.draft_version||0)){
      applyDraftPayload(remoteDraft.payload,'已同步较新的服务端草稿，请核对后保存');
      activeDraftStore.saveLocal(remoteDraft.payload,{draftVersion:remoteDraftVersion,baseStateVersion:Number(remoteDraft.base_state_version||0)});
    }
  }catch(err){
    if(localDraft)setStatus('已保留本机草稿，网络恢复后将继续核对服务端版本','ok');
  }
}
async function onScopeChange(kind){if(operationState)return;var nextScope=scopeKey();if(dirty&&activeScope){var next=nextScope.split('|');var previous=activeScope.split('|');document.getElementById('reportDate').value=previous[0];document.getElementById('storeId').value=previous[1];document.getElementById('roleCode').value=previous[2];try{await saveReport('draft',{silent:true,skipReload:true})}catch(err){if(!window.confirm('草稿自动保存失败，仍要切换吗？'))return}document.getElementById('reportDate').value=next[0];document.getElementById('storeId').value=next[1];document.getElementById('roleCode').value=next[2]}activeScope=nextScope;var version=++scopeRequestVersion;if(kind==='store')await loadReport(version);else await loadTemplate(version)}
async function leaveWorkload(){if(operationState)return;if(dirty){try{await saveReport('draft',{silent:true,skipReload:true})}catch(err){if(!window.confirm('草稿保存失败，仍要离开吗？'))return}}location.href='/mobile/mine.html'}
function setUploadState(metricCode,state){uploadState[metricCode]=state;var btn=document.getElementById('uploadBtn_'+metricCode);if(btn)btn.disabled=!!state}
function triggerEvidenceInput(metricCode){
  var input=document.getElementById('file_'+metricCode);
  if(!input){setStatus('上传控件未加载，请刷新页面后重试','err');return}
  setStatus('请选择一张凭证图片...');
  input.click();
}
function buildStoreDropdown(){
  var sel=document.getElementById('storeId');
  var hint=document.getElementById('storeHint');
  sel.innerHTML='<option value="">请选择门店</option>';
  var canViewAll=context&&context.permissions&&context.permissions.can_view_all;
  var myStoreId=context?parseInt(context.store_id||0):0;
  if(myStoreId>0&&!canViewAll){
    var myStore=STORES.find(function(s){return s.id===myStoreId});
    if(myStore){var opt=document.createElement('option');opt.value=myStore.id;opt.textContent=myStore.name;opt.selected=true;sel.appendChild(opt)}
    sel.disabled=true;
    sel.classList.add('readonly-control');
    hint.textContent='已自动锁定本人门店，不需要手动切换';
    hint.className='status ok';
  }else{
    STORES.forEach(function(s){var opt=document.createElement('option');opt.value=s.id;opt.textContent=s.name;if(s.id===myStoreId)opt.selected=true;sel.appendChild(opt)});
    sel.disabled=false;
    hint.textContent=myStoreId>0?'可切换门店':'请先选择门店';
    hint.className='status';
  }
}
function onRoleChange(){
  if(roleLocked)return;
  loadTemplate();
}
async function init(){
  syncDateLimit();
  try{
    var res=await ApiClient.get('/api/common/context-info.php',{redirectOnUnauthorized:true});
    context=res.data.context||{};
    var tokenPayload=AppAuth.getTokenPayload()||{};
    DraftStore.setIdentity({userId:Number(tokenPayload.user_id||tokenPayload.sub||context.user_id||0),staffId:Number(context.staff_id||0),sessionVersion:Number(tokenPayload.session_version||0)});
    document.getElementById('identityName').textContent=context.name||'员工';
    document.getElementById('identityStore').textContent=context.store_name||'未绑定门店';
    document.getElementById('identityRole').textContent=context.role_name||context.role||'未绑定岗位';
    var myRole=context.role||'';
    var roleSel=document.getElementById('roleCode');
    var canViewAll=context.permissions&&context.permissions.can_view_all;
    if(myRole==='sales'||myRole==='coach'||myRole==='manager'){
      roleSel.value=myRole;
      if(!canViewAll){roleSel.disabled=true;roleSel.classList.add('readonly-control');roleLocked=true}else{roleSel.disabled=false;roleLocked=false}
    }else{
      roleSel.disabled=false;roleLocked=false;
      templateItems=[];
      renderMetrics('当前岗位('+((context.role_name||myRole))+')暂无工作量日报模板');
      setStatus('当前岗位暂无工作量日报模板','ok');
      buildStoreDropdown();
      return;
    }
    buildStoreDropdown();
    var selVal=document.getElementById('storeId').value;
    if(!selVal){setStatus('请选择门店后再填写日报','err')}
    await loadTemplate(++scopeRequestVersion);
    activeScope=scopeKey();
  }catch(err){
    setStatus(err.message||'读取身份失败，请重新登录','err');
  }
}
async function loadTemplate(version){
  version=version||++scopeRequestVersion;
  var role=document.getElementById('roleCode').value;
  var requestedScope=scopeKey();
  setStatus('正在加载模板...');
  try{
    var res=await ApiClient.get('/api/workload/template.php?role='+encodeURIComponent(role)+'&date='+encodeURIComponent(document.getElementById('reportDate').value),{redirectOnUnauthorized:true});
    if(version!==scopeRequestVersion||requestedScope!==scopeKey())return;
    templateItems=res.data.items||[];
    templateDescription=res.data.description||'';
    renderMetrics();
    await loadReport(version);
    if(version!==scopeRequestVersion||requestedScope!==scopeKey())return;
    setStatus('模板已加载，共 '+templateItems.length+' 项','ok');
  }catch(err){
    if(version!==scopeRequestVersion||requestedScope!==scopeKey())return;
    templateItems=[];
    renderMetrics();
    if(err.code===403){setStatus('当前账号无权限查看该岗位模板','err')}else{setStatus(err.message||'模板加载失败，请重新登录后重试','err')}
  }
}
async function loadReport(version){
  version=version||++scopeRequestVersion;
  if(!templateItems.length)return;
  syncDateLimit();
  var date=document.getElementById('reportDate').value;
  var storeId=document.getElementById('storeId').value;
  var role=document.getElementById('roleCode').value;
  var requestedScope=scopeKey();
  if(!date||!storeId){currentReportId=0;evidenceMap={};renderMetrics();return}
  try{
    var res=await ApiClient.get('/api/workload/my-report.php?date='+encodeURIComponent(date)+'&store_id='+encodeURIComponent(storeId)+'&role='+encodeURIComponent(role),{redirectOnUnauthorized:true});
    if(version!==scopeRequestVersion||requestedScope!==scopeKey())return;
    storeMetricSummary=res.data.store_metric_summary||{};
    valueMap=applyStoreMetricSummary(res.data.values||{});
    var report=res.data.report||null;
    reportState={completion_status:res.data.completion_status||'missing',submit_status:report&&report.submit_status?report.submit_status:'missing',is_writable:res.data.is_writable!==false,is_weekly_rest_day:!!res.data.is_weekly_rest_day,deadline_at:res.data.deadline_at||'',last_saved_at:report&&(report.updated_at||report.created_at)||''};
    currentReportId=res.data.report&&res.data.report.id?parseInt(res.data.report.id,10):0;
    document.getElementById('remarks').value=report&&report.remarks?report.remarks:'';
    if(currentReportId){
      var loadedEvidence=await loadEvidence(currentReportId);
      if(version!==scopeRequestVersion||requestedScope!==scopeKey())return;
      evidenceMap=loadedEvidence;
    }else{
      evidenceMap={};
    }
    renderMetrics();
    dirty=false;
    await restoreRecovery();
    await loadDailyStatus(version,requestedScope);
    renderState();
  }catch(err){
    if(version!==scopeRequestVersion||requestedScope!==scopeKey())return;
    if(err.code===403){setStatus('无权限查看该门店日报','err')}else{setStatus(err.message||'日报读取失败','err')}
  }
}
async function loadDailyStatus(version,requestedScope){
  try{
    var res=await ApiClient.get('/api/workload/my-status.php',{redirectOnUnauthorized:true});
    if(version&&((version!==scopeRequestVersion)||(requestedScope&&requestedScope!==scopeKey())))return;
    dailyStatus=res.data||{};
    renderDailyStatus();
  }catch(err){
    if(version&&((version!==scopeRequestVersion)||(requestedScope&&requestedScope!==scopeKey())))return;
    document.getElementById('dailyActionText').textContent='每日工作量状态读取失败，请刷新后重试。';
  }
}
async function loadEvidence(reportId){
  var loaded={};
  var res=await ApiClient.get('/api/workload/evidence-list.php?report_id='+encodeURIComponent(reportId),{redirectOnUnauthorized:true});
  (res.data.list||[]).forEach(function(item){
    var key=item.metric_code||'';
    if(!loaded[key])loaded[key]=[];
    loaded[key].push(item);
  });
  return loaded;
}
function applyStoreMetricSummary(values){var next=Object.assign({},values||{});Object.keys(storeMetricSummary||{}).forEach(function(code){var summary=storeMetricSummary[code]||{};if(Number(summary.value||0)>0&&Number(next[code]||0)<=0)next[code]=Number(summary.value||0)});return next}
function storeMetricTip(metricCode){var summary=storeMetricSummary&&storeMetricSummary[metricCode];return summary&&summary.tip?'<div class="draft-tip">'+escapeHtml(summary.tip)+'</div>':''}
function renderMetrics(emptyText){
  var card=document.getElementById('metricCard');
  if(!templateItems.length){card.innerHTML='<div class="empty">'+escapeHtml(emptyText||'暂无可填写模板')+'</div>';return}
  var metricsHtml=templateItems.map(function(item){
    var val=valueMap[item.metric_code];
    if(val===undefined||val===null)val=item.default_value||0;
    var error=fieldErrors[item.metric_code]||'';var disabled=item.editable===false||reportState.is_writable===false||reportState.is_weekly_rest_day||reportState.submit_status==='submitted';
    return '<div class="metric '+(error?'has-error':'')+'" id="metric_'+escapeAttr(item.metric_code)+'"><div class="metric-main"><div><div class="metric-name">'+escapeHtml(item.metric_name)+(item.required?' *':'')+'</div><div class="metric-meta">'+categoryLabel(item.category)+' \u00b7 '+escapeHtml(item.unit)+'</div></div><input type="number" step="0.01" id="m_'+escapeAttr(item.metric_code)+'" value="'+escapeAttr(val)+'" '+(disabled?'disabled':'')+' oninput="onMetricChanged(\''+escapeJs(item.metric_code)+'\')"></div>'+storeMetricTip(item.metric_code)+(item.input_hint?'<div class="draft-tip">'+escapeHtml(item.input_hint)+'</div>':'')+'<div class="metric-error" id="error_'+escapeAttr(item.metric_code)+'">'+escapeHtml(error)+'</div>'+renderEvidenceSection(item)+'</div>'
  }).join('');
  var draftTip=renderDraftEvidenceTip();
  var description=templateDescription?'<div class="draft-tip">'+escapeHtml(templateDescription)+'</div>':'';
  card.innerHTML=description+(draftTip?'<div class="draft-tip">'+draftTip+'</div>':'')+metricsHtml;
}
function renderEvidenceSection(item){
  if(!item.need_evidence)return '';
  var list=evidenceMap[item.metric_code]||[];
  var maxCount=Math.min(10,Math.max(1,parseInt(item.max_evidence_count||3,10)||3));
  var countText='需上传凭证 '+Math.max(1,item.min_evidence_count||1)+'-'+maxCount+' 张';
  var progressText='已上传 '+list.length+' / 最多 '+maxCount+' 张';
  var tip=currentReportId?'保存后可继续补传，审核侧会读取这里的图片':'请先保存草稿，再上传对应图片';
  var gallery=list.length?'<div class="evidence-list">'+list.map(function(file){return '<div class="evidence-item"><img src="'+escapeAttr(file.file_url)+'" alt="凭证图片"><span>'+escapeHtml(file.file_name||'已上传凭证')+'</span><div class="evidence-card-actions"><button type="button" class="evidence-link" onclick="openPreview(\''+escapeJs(file.file_url)+'\')">查看</button><button type="button" class="evidence-delete" onclick="deleteEvidence('+parseInt(file.id||0,10)+',\''+escapeJs(item.metric_code)+'\')">删除</button></div></div>'}).join('')+'</div>':'<div class="evidence-empty">暂未上传凭证图片</div>';
  var progress=uploadState[item.metric_code]&&typeof uploadState[item.metric_code]==='number'?uploadState[item.metric_code]:0;var retry=pendingUploads[item.metric_code]?'<button type="button" class="retry-btn" onclick="retryEvidence(\''+escapeJs(item.metric_code)+'\')">重试上传</button>':'';
   var evidenceTitle=item.metric_code==='sales_deal_amount'?'成交系统截图':'图片凭证';return '<div class="evidence-box"><div class="evidence-head"><div><div class="evidence-title">'+evidenceTitle+'</div><div class="metric-meta">'+escapeHtml(progressText)+'</div></div><div class="metric-meta">'+escapeHtml(countText)+'</div></div><div class="evidence-tip">'+escapeHtml(tip)+'</div><div class="evidence-actions"><button type="button" class="evidence-btn" id="uploadBtn_'+item.metric_code+'" onclick="triggerEvidenceInput(\''+escapeJs(item.metric_code)+'\')">上传图片</button>'+retry+'<input class="evidence-input" id="file_'+item.metric_code+'" type="file" accept="image/*" onchange="onEvidenceSelected(event,\''+escapeJs(item.metric_code)+'\')"></div>'+(progress?'<div class="upload-progress"><span style="width:'+progress+'%"></span></div>':'')+gallery+'</div>';
}
function onMetricChanged(metricCode){delete fieldErrors[metricCode];var metric=document.getElementById('metric_'+metricCode);if(metric)metric.classList.remove('has-error');var error=document.getElementById('error_'+metricCode);if(error)error.textContent='';markDirty();renderDailyStatus()}
async function restoreRecovery(){await reconcileDrafts()}
function validateMetrics(forSubmit){fieldErrors={};templateItems.forEach(function(item){var el=document.getElementById('m_'+item.metric_code);var raw=el?String(el.value).trim():'';var value=Number(raw);valueMap[item.metric_code]=raw;var error='';if(raw!==''&&!Number.isFinite(value))error='请输入有效数字';else if(value<0)error='数值不能小于 0';else if(item.min_value!==null&&item.min_value!==undefined&&value<Number(item.min_value))error='数值不能小于 '+item.min_value;else if(item.max_value!==null&&item.max_value!==undefined&&value>Number(item.max_value))error='数值不能大于 '+item.max_value;else if(forSubmit&&Number(item.required)&&raw==='')error='该指标为必填项';else if(forSubmit&&!Number(item.allow_zero)&&value===0&&Number(item.required))error='该指标需要填写大于 0 的数值';if(error)fieldErrors[item.metric_code]=error});var gaps=forSubmit?getEvidenceGaps():[];gaps.forEach(function(gap){if(!fieldErrors[gap.metricCode])fieldErrors[gap.metricCode]=gap.message});renderMetrics();if(Object.keys(fieldErrors).length){var firstCode=Object.keys(fieldErrors)[0];var node=document.getElementById('metric_'+firstCode);if(node){node.scrollIntoView({behavior:'smooth',block:'center'});var input=document.getElementById('m_'+firstCode);if(input)input.focus()}setStatus(fieldErrors[firstCode],'err');return false}return true}
async function saveReport(status,options){
  options=options||{};
  var date=document.getElementById('reportDate').value;
  var storeId=parseInt(document.getElementById('storeId').value||'0',10);
  var role=document.getElementById('roleCode').value;
  if(!date){setStatus('请选择日期','err');return Promise.reject(new Error('请选择日期'))}
  if(!storeId){setStatus('请选择门店','err');return Promise.reject(new Error('请选择门店'))}
  if(!validateMetrics(status==='submitted'))return Promise.reject(new Error('请修正表单错误'));
  if(!beginOperation(status==='submitted'?'submitting':'saving'))return Promise.reject(new Error('操作正在进行中'));
  var values=templateItems.map(function(item){var el=document.getElementById('m_'+item.metric_code);return{metric_code:item.metric_code,value:parseFloat(el&&el.value?el.value:'0')||0}});
  if(!options.silent)setStatus('正在保存...');
  try{
    var res=await ApiClient.post('/api/workload/save-report.php',{report_date:date,store_id:storeId,role_code:role,submit_status:status,source:'h5',remarks:document.getElementById('remarks').value||'',values:values});
    currentReportId=parseInt(res.data.report_id||0,10)||0;
    dirty=false;await clearRecovery();reportState.submit_status=status;reportState.completion_status=status;reportState.last_saved_at=new Date().toLocaleTimeString('zh-CN',{hour:'2-digit',minute:'2-digit'});
    if(!options.skipReload)await loadReport();else await loadDailyStatus();
    if(status==='submitted'){var panel=document.getElementById('successPanel');panel.textContent='日报已提交成功，后台今日统计已更新。报告 ID：'+res.data.report_id;panel.classList.add('show')}
    if(!options.silent)setStatus((status==='submitted'?'日报已提交，后台今日统计可见':'草稿已保存，需点击提交日报后才进入后台统计')+' \u00b7 报告ID '+res.data.report_id,'ok');renderState();
    return res;
  }catch(err){
    var msg=err.message||'保存失败';
    if(err.data&&err.data.message)msg=err.data.message;
    if(!options.silent)setStatus(msg,'err');
    throw err;
  }finally{
    endOperation();
  }
}
async function ensureReportForEvidence(){
  if(currentReportId>0)return currentReportId;
  setStatus('正在保存草稿，准备上传图片...');
  await saveReport('draft',{silent:false,skipReload:true});
  return currentReportId;
}
function isEvidenceImage(file){
  var type=String(file&&file.type||'').toLowerCase();
  var name=String(file&&file.name||'').toLowerCase();
  return /^image\//.test(type)||/\.(jpg|jpeg|png|gif|webp|bmp|heic|heif)$/.test(name);
}
async function onEvidenceSelected(event,metricCode){
  var input=event.target;
  var file=input&&input.files&&input.files[0];
  var acquired=false;
  if(!file){setStatus('未选择图片，请重新点击上传图片','err');return;}
  try{
    setStatus('已选择图片，正在校验...');
    var item=findMetricItem(metricCode);
    var existingList=evidenceMap[metricCode]||[];
    var maxCount=item?Math.min(10,Math.max(1,parseInt(item.max_evidence_count||3,10)||3)):10;
    if(existingList.length>=maxCount)throw new Error('该指标最多只能上传 '+maxCount+' 张凭证图片');
    if(!isEvidenceImage(file))throw new Error('只能上传图片文件');
    if(file.size&&file.size<512)throw new Error('图片文件过小，请重新拍照或选择清晰截图');
    if(file.size&&file.size>5*1024*1024)throw new Error('图片不能超过 5MB，请压缩后重新上传');
    var reportId=await ensureReportForEvidence();
    if(!reportId)throw new Error('请先保存日报后再上传图片');
    if(!beginOperation('uploading'))throw new Error('操作正在进行中');acquired=true;
    pendingUploads[metricCode]=file;setUploadState(metricCode,1);renderMetrics();updateActionState();
    setStatus('正在上传凭证图片...');
    await uploadEvidenceFile(reportId,metricCode,file);
    setStatus('图片已上传，正在刷新凭证列表...');
    evidenceMap=await loadEvidence(reportId);
    delete pendingUploads[metricCode];uploadState[metricCode]=0;
    renderMetrics();
    setStatus('凭证图片上传成功，已保存为草稿；请点提交日报后才会计入后台统计','ok');
  }catch(err){
    pendingUploads[metricCode]=file;renderMetrics();
    setStatus((err&&err.message)||'图片上传失败','err');
  }finally{
    setUploadState(metricCode,false);if(acquired)endOperation();renderMetrics();updateActionState();
    if(input)input.value='';
  }
}
function retryEvidence(metricCode){var file=pendingUploads[metricCode];if(!file)return;onEvidenceSelected({target:{files:[file],value:''}},metricCode)}
async function deleteEvidence(evidenceId,metricCode){
  if(!evidenceId)return;
  if(!window.confirm('确认删除这张凭证图片吗？'))return;
  if(!beginOperation('deleting'))return;
  try{
    setUploadState(metricCode,true);
    setStatus('正在删除凭证图片...');
    await ApiClient.post('/api/workload/evidence-delete.php',{id:evidenceId});
    if(currentReportId){
      evidenceMap=await loadEvidence(currentReportId);
    }
    renderMetrics();
    setStatus('凭证图片已删除','ok');
  }catch(err){
    setStatus((err&&err.message)||'删除凭证图片失败','err');
  }finally{
    setUploadState(metricCode,false);endOperation();
  }
}
function renderDraftEvidenceTip(){
  return '上传图片会自动保存草稿；只有点击提交日报后才纳入后台统计。';
}
function evidenceCountForMetric(metricCode){
  var ownCount=(evidenceMap[metricCode]||[]).length;
  var summary=storeMetricSummary&&storeMetricSummary[metricCode];
  var aggregateCount=summary?Number(summary.value||0):0;
  return metricCode==='manager_store_poi_checkin'?Math.max(ownCount,aggregateCount):ownCount;
}
function getEvidenceGaps(){
  return templateItems.reduce(function(gaps,item){if(!Number(item.need_evidence))return gaps;var input=document.getElementById('m_'+item.metric_code);var value=Number(input&&input.value||0);if(value<=0)return gaps;var required=Math.max(1,Number(item.min_evidence_count||1));var count=evidenceCountForMetric(item.metric_code);if(count<required)gaps.push({metricCode:item.metric_code,message:'“'+item.metric_name+'”至少需要上传 '+required+' 张凭证图片'});return gaps},[]);
}
var previewReturnFocus=null;
function openPreview(url){
  var mask=document.getElementById('previewMask');
  var img=document.getElementById('previewImage');
  if(!mask||!img||!url)return;
  previewReturnFocus=document.activeElement;
  img.src=url;
  mask.classList.add('show');
  mask.setAttribute('aria-hidden','false');
  document.getElementById('previewClose').focus();
}
function closePreview(event){
  if(event)event.stopPropagation();
  var mask=document.getElementById('previewMask');
  var img=document.getElementById('previewImage');
  if(mask){mask.classList.remove('show');mask.setAttribute('aria-hidden','true')}
  if(img)img.src='';
  if(previewReturnFocus&&previewReturnFocus.focus)previewReturnFocus.focus();
  previewReturnFocus=null;
}
document.getElementById('previewMask').addEventListener('keydown',function(event){if(event.key==='Escape'){event.preventDefault();closePreview();return}if(event.key==='Tab'){event.preventDefault();document.getElementById('previewClose').focus()}});
async function uploadEvidenceFile(reportId,metricCode,file){
  var form=new FormData();
  form.append('report_id',String(reportId));
  form.append('metric_code',metricCode);
  form.append('image_file',file,file.name||'workload-evidence.jpg');
  var headers=(window.AppAuth&&typeof window.AppAuth.authHeaders==='function')?window.AppAuth.authHeaders({}):{};
  return new Promise(function(resolve,reject){var xhr=new XMLHttpRequest();xhr.open('POST','/api/workload/evidence-upload.php');Object.keys(headers).forEach(function(key){xhr.setRequestHeader(key,headers[key])});xhr.timeout=60000;xhr.upload.onprogress=function(event){if(event.lengthComputable){uploadState[metricCode]=Math.round(event.loaded/event.total*100);var bar=document.querySelector('#metric_'+metricCode+' .upload-progress span');if(bar)bar.style.width=uploadState[metricCode]+'%'}};xhr.onload=function(){var data={};try{data=JSON.parse(xhr.responseText)}catch(err){return reject(new Error('图片上传返回异常'))}if(xhr.status===401||Number(data.code)===401){if(window.AppAuth&&window.AppAuth.redirectToLogin)window.AppAuth.redirectToLogin();return reject(new Error(data.message||'登录已过期，请重新登录'))}if(xhr.status<200||xhr.status>=300||Number(data.code)!==0)return reject(new Error(data.message||('图片上传失败：'+xhr.status)));resolve(data)};xhr.onerror=function(){reject(new Error('图片上传请求失败，请检查网络后重试'))};xhr.ontimeout=function(){reject(new Error('图片上传超时，请换网络或压缩图片后重试'))};xhr.send(form)});
}
function validateEvidenceRequirements(){
  for(var i=0;i<templateItems.length;i++){
    var item=templateItems[i];
    if(!item.need_evidence)continue;
    var maxCount=Math.min(10,Math.max(1,parseInt(item.max_evidence_count||3,10)||3));
    var minCount=Math.max(1,parseInt(item.min_evidence_count||1,10)||1);
    var list=evidenceMap[item.metric_code]||[];
    var evidenceCount=evidenceCountForMetric(item.metric_code);
    var input=document.getElementById('m_'+item.metric_code);
    var value=parseFloat(input&&input.value?input.value:'0')||0;
    if(value>0&&evidenceCount<minCount){
      return '“'+item.metric_name+'”填写数量后至少需要上传 '+minCount+' 张凭证图片';
    }
    if(list.length>maxCount){
      return '“'+item.metric_name+'”最多只能上传 '+maxCount+' 张凭证图片';
    }
  }
  return '';
}
function findMetricItem(metricCode){
  for(var i=0;i<templateItems.length;i++){
    if(templateItems[i].metric_code===metricCode)return templateItems[i];
  }
  return null;
}
function categoryLabel(v){return{behavior:'行为',process:'过程',result:'结果',derived:'计算'}[v]||v}
function escapeHtml(str){return String(str||'').replace(/[&<>"]/g,function(s){return{'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[s]})}
function escapeAttr(str){return escapeHtml(str).replace(/'/g,'&#39;')}
function escapeJs(str){return String(str||'').replace(/\\/g,'\\\\').replace(/'/g,"\\'")}
document.addEventListener('DOMContentLoaded',init);
window.addEventListener('pageshow',function(){syncDateLimit()});
window.addEventListener('focus',function(){syncDateLimit()});
window.addEventListener('pwa:network-restored',function(){if(context)reconcileDrafts()});
window.addEventListener('pwa:session-restored',function(){if(context)reconcileDrafts()});
window.addEventListener('app-auth:sensitive-clear',function(){activeDraftStore=null;remoteDraftVersion=0;if(remoteDraftTimer){clearTimeout(remoteDraftTimer);remoteDraftTimer=null}});
window.addEventListener('beforeunload',function(event){if(dirty){event.preventDefault();event.returnValue=''}});
