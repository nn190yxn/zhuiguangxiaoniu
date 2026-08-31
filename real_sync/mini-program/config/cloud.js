const CLOUD_CONFIG = {
  ENV_ID: 'zhuiguangxiaoniu-d6e-d0af953cc34',
  FUNCTIONS: {
    API_PROXY: 'api-proxy',
    AUTH_PROXY: 'auth-proxy',
    MEDIA_TICKET: 'media-ticket'
  },
  TRANSPORT: 'cloud',
  TRANSPORT_POLICY_VERSION: 1,
  TRANSPORT_MIN_CLIENT_VERSION: '1.0.0',
  TRANSPORT_EMERGENCY_MODE: 'direct',
  TRANSPORT_EMERGENCY_ACTIVE: false,
  SHADOW_SAMPLE_RATE: 0,
  UPSTREAM_ORIGIN: 'https://supercalf.com/api',
  GATEWAY_SIGNATURE_VERSION: 'v1',
  STORAGE: {
    ROOT_PREFIX: 'mini-program',
    UPLOAD_PREFIX: 'mini-program/uploads',
    MIRROR_PREFIX: 'mini-program/mirrors',
    RULE_MODE: 'cloud-function-only'
  }
};

module.exports = CLOUD_CONFIG;
