(function(window){
  'use strict';

  var DRAFT_PREFIX='zgxn_sensitive_draft:';
  var DEVICE_KEY='zgxn_pwa_device_id';
  var MAX_TTL_MS=24*60*60*1000;
  var identity=null;

  function positiveInteger(value){
    var number=Number(value||0);
    return Number.isInteger(number)&&number>0?number:0;
  }

  function setIdentity(next){
    next=next||{};
    var userId=positiveInteger(next.userId);
    var staffId=positiveInteger(next.staffId);
    if(!userId||!staffId)throw new Error('草稿身份信息不完整');
    identity={userId:userId,staffId:staffId,sessionVersion:Math.max(0,Number(next.sessionVersion||0))};
    return Object.assign({},identity);
  }

  function requireIdentity(){
    if(!identity)throw new Error('草稿身份尚未初始化');
    return identity;
  }

  function safePart(value,name,maxLength){
    var part=String(value||'').trim();
    if(!part||part.length>(maxLength||128)||/[\x00-\x1F\x7F]/.test(part))throw new Error(name+'格式无效');
    return part;
  }

  function getDeviceId(){
    var current='';
    try{current=window.localStorage.getItem(DEVICE_KEY)||''}catch(err){}
    if(current)return current;
    current=window.crypto&&typeof window.crypto.randomUUID==='function'
      ?window.crypto.randomUUID()
      :'pwa-'+Date.now().toString(36)+'-'+Math.random().toString(36).slice(2,12);
    try{window.localStorage.setItem(DEVICE_KEY,current)}catch(err){}
    return current;
  }

  function approvedPayload(payload,allowedFields){
    var source=payload&&typeof payload==='object'&&!Array.isArray(payload)?payload:{};
    var approved={};
    allowedFields.forEach(function(field){
      if(Object.prototype.hasOwnProperty.call(source,field))approved[field]=source[field];
    });
    return approved;
  }

  function clearSensitive(){
    var keys=[];
    try{
      for(var i=0;i<window.localStorage.length;i++){
        var key=window.localStorage.key(i);
        if(key&&key.indexOf(DRAFT_PREFIX)===0)keys.push(key);
      }
      keys.forEach(function(key){window.localStorage.removeItem(key)});
    }catch(err){}
    return keys.length;
  }

  function create(options){
    options=options||{};
    var currentIdentity=requireIdentity();
    var domain=safePart(options.domain,'domain',63);
    var objectType=safePart(options.objectType,'objectType',63);
    var objectId=safePart(options.objectId,'objectId',128);
    var schemaVersion=safePart(options.schemaVersion,'schemaVersion',40);
    var allowedFields=Array.isArray(options.allowedFields)?options.allowedFields.map(function(field){return safePart(field,'allowedField',63)}):[];
    if(!allowedFields.length)throw new Error('草稿批准字段不能为空');
    var key=DRAFT_PREFIX+[
      currentIdentity.userId,
      currentIdentity.staffId,
      encodeURIComponent(domain),
      encodeURIComponent(objectType),
      encodeURIComponent(objectId),
      encodeURIComponent(schemaVersion)
    ].join(':');

    function getLocal(){
      var raw='';
      try{raw=window.localStorage.getItem(key)||''}catch(err){return null}
      if(!raw)return null;
      try{
        var record=JSON.parse(raw);
        if(!record||Number(record.expires_at||0)<=Date.now()){
          window.localStorage.removeItem(key);
          return null;
        }
        if(record.user_id!==currentIdentity.userId||record.staff_id!==currentIdentity.staffId||record.schema_version!==schemaVersion){
          window.localStorage.removeItem(key);
          return null;
        }
        record.payload=approvedPayload(record.payload,allowedFields);
        return record;
      }catch(err){
        try{window.localStorage.removeItem(key)}catch(innerErr){}
        return null;
      }
    }

    function saveLocal(payload,metadata){
      metadata=metadata||{};
      var now=Date.now();
      var ttl=Math.max(60000,Math.min(MAX_TTL_MS,Number(metadata.ttlMs||MAX_TTL_MS)));
      var record={
        user_id:currentIdentity.userId,
        staff_id:currentIdentity.staffId,
        domain:domain,
        object_type:objectType,
        object_id:objectId,
        schema_version:schemaVersion,
        draft_version:Math.max(0,Number(metadata.draftVersion||0)),
        base_state_version:Math.max(0,Number(metadata.baseStateVersion||0)),
        source_device_id:getDeviceId(),
        payload:approvedPayload(payload,allowedFields),
        updated_at:now,
        expires_at:now+ttl
      };
      window.localStorage.setItem(key,JSON.stringify(record));
      return record;
    }

    function clearLocal(){
      try{window.localStorage.removeItem(key)}catch(err){}
    }

    function endpoint(){
      var query=new URLSearchParams({
        action:'draft',domain:domain,object_type:objectType,object_id:objectId
      });
      return '/api/platform/sync.php?'+query.toString();
    }

    async function getRemote(){
      var response=await window.ApiClient.get(endpoint(),{redirectOnUnauthorized:true});
      return response&&response.data?response.data.draft||null:null;
    }

    async function saveRemote(payload,metadata){
      metadata=metadata||{};
      var response=await window.ApiClient.put('/api/platform/sync.php?action=draft',{
        domain:domain,
        object_type:objectType,
        object_id:objectId,
        draft_version:Math.max(0,Number(metadata.draftVersion||0)),
        base_state_version:Math.max(0,Number(metadata.baseStateVersion||0)),
        payload:approvedPayload(payload,allowedFields),
        source_client:'pwa',
        source_device_id:getDeviceId(),
        ttl_seconds:86400
      },{redirectOnUnauthorized:true});
      return response&&response.data?response.data.draft||null:null;
    }

    async function deleteRemote(draftVersion){
      var response=await window.ApiClient.delete('/api/platform/sync.php?action=draft',{
        domain:domain,
        object_type:objectType,
        object_id:objectId,
        draft_version:Math.max(0,Number(draftVersion||0))
      },{redirectOnUnauthorized:true});
      return response&&response.data?response.data.tombstone||null:null;
    }

    return{
      getLocal:getLocal,
      saveLocal:saveLocal,
      clearLocal:clearLocal,
      getRemote:getRemote,
      saveRemote:saveRemote,
      deleteRemote:deleteRemote
    };
  }

  window.DraftStore={
    setIdentity:setIdentity,
    create:create,
    getDeviceId:getDeviceId,
    clearSensitive:clearSensitive
  };

  if(typeof window.addEventListener==='function'){
    window.addEventListener('app-auth:sensitive-clear',clearSensitive);
  }
})(window);
