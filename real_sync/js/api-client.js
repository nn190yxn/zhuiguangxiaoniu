(function(window){
  'use strict';

  var DEFAULT_TIMEOUT=15000;
  var etagCache=new Map();

  function createRequestId(){
    if(window.crypto&&typeof window.crypto.randomUUID==='function') return window.crypto.randomUUID();
    return 'pwa-'+Date.now().toString(36)+'-'+Math.random().toString(36).slice(2,12);
  }

  function createIdempotencyKey(prefix){
    var safePrefix=String(prefix||'pwa').replace(/[^a-zA-Z0-9._:-]/g,'-').slice(0,32)||'pwa';
    return (safePrefix+'-'+createRequestId()).slice(0,128);
  }

  function headerValue(headers,name){
    var target=name.toLowerCase();
    var keys=Object.keys(headers||{});
    for(var i=0;i<keys.length;i++){
      if(keys[i].toLowerCase()===target) return headers[keys[i]];
    }
    return '';
  }

  function setHeader(headers,name,value){
    if(!headerValue(headers,name)) headers[name]=value;
  }

  function classifyError(status,code){
    if(status===0) return code===408?'timeout':'network';
    if(status===401) return 'unauthorized';
    if(status===403) return 'forbidden';
    if(status===409) return 'conflict';
    if(status===408) return 'timeout';
    if(status===422||status===400) return 'validation';
    if(status>=500) return 'server';
    return 'http';
  }

  function normalizeError(resp,data,cause){
    var status=resp?Number(resp.status||0):0;
    var code=(data&&typeof data.code!=='undefined')?data.code:status;
    var details=data&&data.data&&typeof data.data==='object'?data.data:{};
    var message=(data&&data.message)||('请求失败：'+(status||'NETWORK'));
    var error=new Error(message);
    error.name='ApiClientError';
    error.response=resp||null;
    error.data=data||null;
    error.code=code;
    error.status=status;
    error.category=classifyError(status,Number(code));
    error.requestId=(data&&data.request_id)||details.request_id||(resp&&resp.headers&&resp.headers.get&&resp.headers.get('X-Request-ID'))||'';
    error.conflictType=details.conflict_type||'';
    error.baseVersion=details.base_version;
    error.currentVersion=details.current_version;
    error.authoritativeState=details.authoritative_state;
    error.recoveryAction=details.recovery_action||'';
    error.retryable=details.retryable===true;
    error.cause=cause||null;
    return error;
  }

  function appendQuery(url,name,value){
    if(value===undefined||value===null||value==='') return url;
    var parsed=new URL(url,window.location.origin);
    parsed.searchParams.set(name,String(value));
    return parsed.origin===window.location.origin?parsed.pathname+parsed.search+parsed.hash:parsed.toString();
  }

  function injectStateVersion(body,version,field){
    if(version===undefined||version===null||body===undefined||body===null) return body;
    if(window.FormData&&body instanceof window.FormData){
      if(!body.has(field)) body.append(field,String(version));
      return body;
    }
    var value=body;
    if(typeof body==='string'){
      try{ value=JSON.parse(body); }catch(err){ return body; }
    }
    if(value&&typeof value==='object'&&!Array.isArray(value)){
      value=Object.assign({},value);
      if(typeof value[field]==='undefined') value[field]=version;
      return JSON.stringify(value);
    }
    return body;
  }

  function successfulCode(data){
    return !data||typeof data.code==='undefined'||String(data.code)==='0';
  }

  async function parseResponse(resp){
    if(resp.status===204) return {code:0,message:'success',data:null};
    try{ return await resp.json(); }
    catch(err){ return {code:resp.status,message:'接口返回异常',data:null}; }
  }

  async function request(url,options){
    options=options||{};
    var method=String(options.method||'GET').toUpperCase();
    var requestUrl=appendQuery(url,options.cursorParam||'cursor',options.cursor);
    var headers=Object.assign({},options.headers||{});
    var requestId=options.requestId||headerValue(headers,'X-Request-ID')||createRequestId();
    setHeader(headers,'X-Request-ID',requestId);
    if(options.idempotencyKey) setHeader(headers,'Idempotency-Key',String(options.idempotencyKey).slice(0,128));
    if(options.etag){
      var cachedEtag=etagCache.get(options.etagKey||requestUrl);
      if(cachedEtag) setHeader(headers,'If-None-Match',cachedEtag);
    }

    var body=injectStateVersion(options.body,options.stateVersion,options.stateVersionField||'state_version');
    var isFormData=window.FormData&&body instanceof window.FormData;
    if(body!==undefined&&body!==null&&!isFormData) setHeader(headers,'Content-Type','application/json');
    if(!window.AppAuth||typeof window.AppAuth.authFetch!=='function'){
      if(window.AppAuth&&typeof window.AppAuth.authHeaders==='function') headers=window.AppAuth.authHeaders(headers);
    }

    var controller=null;
    var timer=null;
    var timeout=typeof options.timeout==='number'?Math.max(1,options.timeout):DEFAULT_TIMEOUT;
    if(window.AbortController){
      controller=new window.AbortController();
      if(options.signal&&typeof options.signal.addEventListener==='function'){
        options.signal.addEventListener('abort',function(){controller.abort();},{once:true});
      }
      timer=setTimeout(function(){controller.abort();},timeout);
    }

    var fetchOptions=Object.assign({},options,{method:method,headers:headers,body:body,credentials:options.credentials||'same-origin'});
    if(controller) fetchOptions.signal=controller.signal;
    ['timeout','redirectOnUnauthorized','requestId','idempotencyKey','stateVersion','stateVersionField','etag','etagKey','cursor','cursorParam','onConflict','__conflictRetried'].forEach(function(key){delete fetchOptions[key]});
    if(method==='GET'||method==='HEAD') delete fetchOptions.body;

    var resp=null;
    try{
      var transport=window.AppAuth&&typeof window.AppAuth.authFetch==='function'?window.AppAuth.authFetch:window.fetch;
      resp=await transport.call(window.AppAuth||window,requestUrl,fetchOptions);
    }catch(err){
      if(err&&err.name==='AbortError') throw normalizeError(null,{code:408,message:'请求超时，请稍后重试',request_id:requestId,data:null},err);
      throw normalizeError(null,{code:0,message:'网络请求失败，请检查网络后重试',request_id:requestId,data:null},err);
    }finally{
      if(timer) clearTimeout(timer);
    }

    if(resp.status===304){
      return {code:0,message:'not_modified',data:null,not_modified:true,etag:etagCache.get(options.etagKey||requestUrl)||'',request_id:(resp.headers&&resp.headers.get&&resp.headers.get('X-Request-ID'))||requestId};
    }

    var data=await parseResponse(resp);
    if(data&&typeof data==='object'&&!data.request_id) data.request_id=(resp.headers&&resp.headers.get&&resp.headers.get('X-Request-ID'))||requestId;
    var responseEtag=(resp.headers&&resp.headers.get&&resp.headers.get('ETag'))||(data&&data.data&&data.data.etag)||'';
    if(options.etag&&responseEtag) etagCache.set(options.etagKey||requestUrl,responseEtag);

    if(resp.status===401||String(data&&data.code)==='401'){
      var unauthorized=normalizeError(resp,data||{code:401,message:'登录已过期，请重新登录',data:null});
      if(options.redirectOnUnauthorized!==false&&window.AppAuth&&typeof window.AppAuth.redirectToLogin==='function') window.AppAuth.redirectToLogin();
      throw unauthorized;
    }

    if(resp.status===409){
      var conflict=normalizeError(resp,data);
      if(typeof options.onConflict==='function'){
        var decision=await options.onConflict(conflict);
        if(decision&&decision.retry&&!options.__conflictRetried){
          var retryOptions=Object.assign({},options,decision.options||{},{__conflictRetried:true});
          if(typeof decision.stateVersion!=='undefined') retryOptions.stateVersion=decision.stateVersion;
          if(typeof decision.body!=='undefined') retryOptions.body=typeof decision.body==='string'?decision.body:JSON.stringify(decision.body);
          return request(url,retryOptions);
        }
      }
      throw conflict;
    }

    if(!resp.ok||!successfulCode(data)) throw normalizeError(resp,data);
    if(data&&typeof data==='object'){
      data.etag=responseEtag||data.etag||'';
      if(data.data&&typeof data.data==='object'&&typeof data.next_cursor!=='string'&&typeof data.data.next_cursor==='string') data.next_cursor=data.data.next_cursor;
    }
    return data;
  }

  function get(url,options){
    return request(url,Object.assign({},options||{},{method:'GET'}));
  }

  function post(url,body,options){
    return request(url,Object.assign({},options||{},{method:'POST',body:JSON.stringify(body||{})}));
  }

  function put(url,body,options){
    return request(url,Object.assign({},options||{},{method:'PUT',body:JSON.stringify(body||{})}));
  }

  function remove(url,body,options){
    return request(url,Object.assign({},options||{},{method:'DELETE',body:JSON.stringify(body||{})}));
  }

  function clearEtag(url){
    if(url) etagCache.delete(url);
    else etagCache.clear();
  }

  window.ApiClient={
    request:request,
    get:get,
    post:post,
    put:put,
    delete:remove,
    normalizeError:normalizeError,
    createRequestId:createRequestId,
    createIdempotencyKey:createIdempotencyKey,
    clearEtag:clearEtag
  };
})(window);
