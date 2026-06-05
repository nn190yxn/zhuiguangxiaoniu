(function(window){
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
      return localStorage.getItem('jwt_token')
        || localStorage.getItem('token')
        || sessionStorage.getItem('jwt_token')
        || sessionStorage.getItem('token')
        || localStorage.getItem('auth_token')
        || localStorage.getItem('access_token')
        || '';
    }catch(err){
      return '';
    }
  }

  function setToken(token){
    try{
      if(token) localStorage.setItem('jwt_token', token);
    }catch(err){}
  }

  function getTokenPayload(){
    return parseJwtPayload(getToken());
  }

  function isTokenExpired(bufferSeconds){
    var payload=getTokenPayload();
    if(!payload || !payload.exp) return false;
    var buffer=typeof bufferSeconds==='number'?bufferSeconds:60;
    return payload.exp <= Math.floor(Date.now()/1000)+buffer;
  }

  function clearAuth(){
    try{
      ['jwt_token','token','auth_token','access_token','user_info'].forEach(function(key){ localStorage.removeItem(key); });
      ['jwt_token','token'].forEach(function(key){ sessionStorage.removeItem(key); });
    }catch(err){}
  }

  function loginUrl(){
    var redirect=window.location.pathname+window.location.search;
    return '/mobile/login.html?redirect='+encodeURIComponent(redirect);
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

  function getUserInfo(){
    try{
      return JSON.parse(localStorage.getItem('user_info')||'null');
    }catch(err){
      return null;
    }
  }

  function setUserInfo(user){
    try{
      localStorage.setItem('user_info', JSON.stringify(user||{}));
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
    getUserInfo:getUserInfo,
    setUserInfo:setUserInfo
  };

  // 兼容旧的 auth.js 全局函数
  window.getAuthToken = getToken;
  window.authHeaders = function(options){
    var token = getToken();
    var headers = Object.assign({}, (options && options.headers) || {});
    if (token) {
      headers.Authorization = 'Bearer ' + token;
    }
    return Object.assign({}, options || {}, { headers: headers });
  };
})(window);
