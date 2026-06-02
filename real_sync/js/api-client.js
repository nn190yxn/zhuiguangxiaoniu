(function(window){
  var DEFAULT_TIMEOUT=15000;

  function normalizeError(resp, data){
    var message=(data&&data.message)||('请求失败：'+(resp?resp.status:'NETWORK'));
    var error=new Error(message);
    error.response=resp||null;
    error.data=data||null;
    error.code=(data&&typeof data.code!=='undefined')?data.code:(resp?resp.status:0);
    error.status=resp?resp.status:0;
    return error;
  }

  function handleUnauthorized(resp, data, options){
    var shouldRedirect=!(options&&options.redirectOnUnauthorized===false);
    if(shouldRedirect && window.AppAuth && typeof window.AppAuth.redirectToLogin==='function'){
      window.AppAuth.redirectToLogin();
    }
    throw normalizeError(resp, data||{code:401,message:'登录已过期，请重新登录',data:null});
  }

  async function request(url, options){
    options=options||{};
    if(window.AppAuth && typeof window.AppAuth.isTokenExpired==='function' && window.AppAuth.getToken && window.AppAuth.getToken() && window.AppAuth.isTokenExpired()){
      handleUnauthorized(null, {code:401,message:'登录已过期，请重新登录',data:null}, options);
    }
    var headers=Object.assign({'Content-Type':'application/json'}, options.headers||{});
    if(window.AppAuth && typeof window.AppAuth.authHeaders==='function'){
      headers=window.AppAuth.authHeaders(headers);
    }
    var controller=null;
    var timer=null;
    var timeout=typeof options.timeout==='number'?options.timeout:DEFAULT_TIMEOUT;
    if(window.AbortController){
      controller=new AbortController();
      timer=setTimeout(function(){ controller.abort(); }, timeout);
    }
    var fetchOptions=Object.assign({}, options, {headers:headers});
    if(controller) fetchOptions.signal=controller.signal;
    delete fetchOptions.timeout;
    delete fetchOptions.redirectOnUnauthorized;
    var resp=null;
    try{
      resp=await fetch(url, fetchOptions);
    }catch(err){
      if(err && err.name==='AbortError') throw normalizeError(null, {code:408,message:'请求超时，请稍后重试',data:null});
      throw normalizeError(null, {code:0,message:'网络请求失败，请检查网络后重试',data:null});
    }finally{
      if(timer) clearTimeout(timer);
    }
    var data=null;
    try{ data=await resp.json(); }catch(err){ data={code:resp.status,message:'接口返回异常',data:null}; }
    if(resp.status===401 || (data && Number(data.code)===401)){
      handleUnauthorized(resp, data, options);
    }
    if(!resp.ok || (data && typeof data.code!=='undefined' && Number(data.code)!==0)){
      throw normalizeError(resp, data);
    }
    return data;
  }

  function get(url, options){
    return request(url, Object.assign({}, options||{}, {method:'GET'}));
  }

  function post(url, body, options){
    return request(url, Object.assign({}, options||{}, {method:'POST', body:JSON.stringify(body||{})}));
  }

  window.ApiClient={request:request,get:get,post:post,normalizeError:normalizeError};
})(window);
