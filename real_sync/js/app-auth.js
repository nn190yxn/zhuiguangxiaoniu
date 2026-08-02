(function(window){
  var accessToken='';
  var sessionVersion=0;
  var refreshPromise=null;
  var sessionChannel=typeof BroadcastChannel==='function'?new BroadcastChannel('platform-session'):null;
  function publishSensitiveClear(reason,previousVersion,nextVersion){
    if(window.DraftStore&&typeof window.DraftStore.clearSensitive==='function')window.DraftStore.clearSensitive();
    if(typeof window.dispatchEvent==='function'&&typeof window.CustomEvent==='function'){
      window.dispatchEvent(new CustomEvent('app-auth:sensitive-clear',{detail:{reason:reason,previous_session_version:previousVersion||0,next_session_version:nextVersion||0}}));
    }
  }
  function readCookie(name){
    var prefix=name+'=';
    var parts=document.cookie?document.cookie.split('; '):[];
    for(var i=0;i<parts.length;i++){
      if(parts[i].indexOf(prefix)===0){
        return decodeURIComponent(parts[i].slice(prefix.length));
      }
    }
    return '';
  }

  function writeCookie(name,value,maxAgeSeconds){
    var secure=window.location.protocol==='https:'?'; Secure':'';
    document.cookie=name+'='+encodeURIComponent(value)+'; Path=/; Max-Age='+(maxAgeSeconds||604800)+'; SameSite=Lax'+secure;
  }

  function clearCookie(name){
    var secure=window.location.protocol==='https:'?'; Secure':'';
    document.cookie=name+'=; Path=/; Max-Age=0; SameSite=Lax'+secure;
  }

  function readStoredValue(keys){
    var keyList=Array.isArray(keys)?keys:[keys];
    for(var i=0;i<keyList.length;i++){
      var key=keyList[i];
      try{
        var localValue=localStorage.getItem(key);
        if(localValue) return localValue;
      }catch(err){}
      try{
        var sessionValue=sessionStorage.getItem(key);
        if(sessionValue) return sessionValue;
      }catch(err){}
      var cookieValue=readCookie(key);
      if(cookieValue) return cookieValue;
    }
    return '';
  }

  function writeStoredValue(key,value,options){
    options=options||{};
    var stored=false;
    try{ localStorage.setItem(key, value); stored=true; }catch(err){}
    try{ sessionStorage.setItem(key, value); stored=true; }catch(err){}
    if(options.cookie){
      try{ writeCookie(key, value, options.maxAgeSeconds||604800); stored=true; }catch(err){}
    }
    return stored;
  }

  function removeStoredValue(keys){
    var keyList=Array.isArray(keys)?keys:[keys];
    keyList.forEach(function(key){
      try{ localStorage.removeItem(key); }catch(err){}
      try{ sessionStorage.removeItem(key); }catch(err){}
      clearCookie(key);
    });
  }

  function parseJwtPayload(token){
    if(!token || token.split('.').length<2) return null;
    try{
      var payload=token.split('.')[1].replace(/-/g,'+').replace(/_/g,'/');
      while(payload.length%4) payload+='=';
      return JSON.parse(decodeURIComponent(escape(window.atob(payload))));
    }catch(err){
      try{
        return JSON.parse(window.atob(token.split('.')[1]));
      }catch(innerErr){
        return null;
      }
    }
  }

  function getToken(){
    return accessToken;
  }

  function setToken(token){
    accessToken=token||'';
    if(accessToken&&!readCookie('platform_csrf')){
      writeStoredValue('jwt_token',accessToken,{cookie:true,maxAgeSeconds:604800});
    }
  }

  function getTokenPayload(){
    return parseJwtPayload(getToken());
  }

  function isTokenExpired(bufferSeconds){
    var payload=getTokenPayload();
    if(!payload || !payload.exp) return false;
    var buffer=typeof bufferSeconds==='number'?bufferSeconds:300;
    return payload.exp <= Math.floor(Date.now()/1000)+buffer;
  }

  function clearAuth(reason){
    var previousVersion=sessionVersion;
    accessToken='';
    sessionVersion=0;
    try{
      removeStoredValue(['jwt_token','token','auth_token','access_token','user_info']);
    }catch(err){}
    var csrf=readCookie('platform_csrf');
    if(csrf){
      fetch('/api/auth/refresh.php?action=logout',{method:'POST',credentials:'same-origin',keepalive:true,headers:{'X-CSRF-Token':csrf}}).catch(function(){});
    }
    publishSensitiveClear(reason||'logout',previousVersion,0);
  }

  function loginUrl(){
    var redirect=window.location.pathname+window.location.search;
    return 'https://supercalf.com/mobile/login.html?v=20260620h6&redirect='+encodeURIComponent(redirect);
  }

  function redirectToLogin(){
    clearAuth();
    window.location.href=loginUrl();
  }

  function authHeaders(extra){
    var headers=Object.assign({}, extra||{});
    var token=getToken();
    if(token) headers.Authorization='Bearer '+token;
    return headers;
  }

  function authFetch(url, options){
    options=options||{};
    return ensureAccessToken(false).then(function(){
      var requestToken=getToken();
      var headers=authHeaders(options.headers||{});
      return fetch(url, Object.assign({}, options, {headers:headers})).then(function(response){
        return{response:response,requestToken:requestToken};
      });
    }).then(function(result){
      if(result.response.status!==401) return result.response;
      if(getToken()&&getToken()!==result.requestToken){
        var currentHeaders=authHeaders(options.headers||{});
        return fetch(url,Object.assign({},options,{headers:currentHeaders}));
      }
      return ensureAccessToken(true).then(function(){
        var retryHeaders=authHeaders(options.headers||{});
        return fetch(url,Object.assign({},options,{headers:retryHeaders}));
      });
    });
  }

  function performRefresh(){
    var csrf=readCookie('platform_csrf');
    if(!csrf) return Promise.reject(new Error('refresh_unavailable'));
    return fetch('/api/auth/refresh.php',{method:'POST',credentials:'same-origin',cache:'no-store',headers:{'X-CSRF-Token':csrf}})
      .then(function(response){return response.json().then(function(body){return{response:response,body:body}})})
      .then(function(result){
        if(!result.response.ok||Number(result.body.code)!==0) throw new Error(result.body.message||'session_refresh_failed');
        var data=result.body.data||{};
        accessToken=data.access_token||'';
        sessionVersion=Number(data.session_version||0);
        if(sessionChannel) sessionChannel.postMessage({type:'session-updated',session_version:sessionVersion});
        return accessToken;
      });
  }

  function ensureAccessToken(force){
    if(accessToken&&!force&&!isTokenExpired(60)) return Promise.resolve(accessToken);
    if(refreshPromise) return refreshPromise;
    var execute=function(){return performRefresh()};
    refreshPromise=(navigator.locks&&navigator.locks.request
      ?navigator.locks.request('platform-session-refresh',execute)
      :execute()).finally(function(){refreshPromise=null});
    return refreshPromise;
  }

  function getUserInfo(){
    try{
      return JSON.parse(readStoredValue('user_info')||'null');
    }catch(err){
      return null;
    }
  }

  function setUserInfo(user){
    try{
      writeStoredValue('user_info', JSON.stringify(user||{}));
    }catch(err){}
  }

  window.AppAuth={
    getToken:getToken,
    setToken:setToken,
    getTokenPayload:getTokenPayload,
    isTokenExpired:isTokenExpired,
    clearAuth:clearAuth,
    loginUrl:loginUrl,
    redirectToLogin:redirectToLogin,
    redirectToLoginPage:redirectToLogin,
    authHeaders:authHeaders,
    authFetch:authFetch,
    ensureAccessToken:ensureAccessToken,
    getUserInfo:getUserInfo,
    setUserInfo:setUserInfo
  };

  // 兼容旧的 auth.js 全局函数
  window.getAuthToken = getToken;
  window.authHeaders = authHeaders;
  window.authFetch = authFetch;
  try{
    if(readCookie('platform_csrf')){
      removeStoredValue(['jwt_token','token','auth_token','access_token']);
    }else{
      accessToken=readStoredValue(['jwt_token','token','auth_token','access_token'])||'';
    }
  }catch(err){}
  if(sessionChannel){
    sessionChannel.onmessage=function(event){
      var message=event&&event.data||{};
      if(message.type==='session-updated'&&Number(message.session_version||0)!==sessionVersion){
        var previousVersion=sessionVersion;
        accessToken='';
        sessionVersion=Number(message.session_version||0);
        publishSensitiveClear('session-version-changed',previousVersion,sessionVersion);
      }
      if(message.type==='session-revoked') clearAuth('session-revoked');
    };
  }
  if(readCookie('platform_csrf')) ensureAccessToken(false).catch(function(){});
})(window);
