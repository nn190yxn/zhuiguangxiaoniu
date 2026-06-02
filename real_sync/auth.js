function getAuthToken() {
  return localStorage.getItem('jwt_token')
    || localStorage.getItem('token')
    || sessionStorage.getItem('jwt_token')
    || sessionStorage.getItem('token')
    || '';
}

function authHeaders(options) {
  var token = getAuthToken();
  var headers = Object.assign({}, (options && options.headers) || {});
  if (token) {
    headers.Authorization = 'Bearer ' + token;
  }
  return Object.assign({}, options || {}, { headers: headers });
}

function authFetch(url, options) {
  return fetch(url, authHeaders(options));
}
