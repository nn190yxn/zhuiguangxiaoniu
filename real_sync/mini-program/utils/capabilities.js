const CONSERVATIVE_FEATURES = Object.freeze({
  authentication: true,
  drill: true,
  knowledge: true,
  workload: true,
  profile: true,
});

function compareVersions(left, right) {
  const leftParts = String(left || '0').split('.').map(value => Number(value) || 0);
  const rightParts = String(right || '0').split('.').map(value => Number(value) || 0);
  const length = Math.max(leftParts.length, rightParts.length);
  for (let index = 0; index < length; index += 1) {
    const difference = (leftParts[index] || 0) - (rightParts[index] || 0);
    if (difference !== 0) return difference;
  }
  return 0;
}

function resolveFeatures(payload, clientVersion) {
  const featureDefinitions = payload && payload.mini_program && payload.mini_program.features;
  if (!featureDefinitions || typeof featureDefinitions !== 'object') {
    return Object.assign({}, CONSERVATIVE_FEATURES);
  }

  return Object.keys(featureDefinitions).reduce((features, key) => {
    const definition = featureDefinitions[key] || {};
    features[key] = definition.enabled === true
      && compareVersions(clientVersion, definition.minimum_client_version || '0.0.0') >= 0;
    return features;
  }, {});
}

module.exports = {
  CONSERVATIVE_FEATURES,
  compareVersions,
  resolveFeatures,
};
