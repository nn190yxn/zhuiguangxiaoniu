(function(window){
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
    try{
      var token=readStoredValue(['jwt_token','token'])||'';
      if(!token){
        token=readStoredValue(['auth_token','access_token'])
          || '';
        if(token){
          writeStoredValue('jwt_token', token, {cookie:true,maxAgeSeconds:604800});
        }
      }
      return token;
    }catch(err){
      return '';
    }
  }

  function setToken(token){
    try{
      if(token) writeStoredValue('jwt_token', token, {cookie:true,maxAgeSeconds:604800});
    }catch(err){}
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

  function clearAuth(){
    try{
      removeStoredValue(['jwt_token','token','auth_token','access_token','user_info']);
    }catch(err){}
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
    var headers=authHeaders(options.headers||{});
    return fetch(url, Object.assign({}, options, {headers:headers}));
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
    getUserInfo:getUserInfo,
    setUserInfo:setUserInfo
  };

  // 兼容旧的 auth.js 全局函数
  window.getAuthToken = getToken;
  window.authHeaders = authHeaders;
  window.authFetch = authFetch;
})(window);
