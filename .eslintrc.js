module.exports = {
  env: {
    node: true,
    es2021: true
  },
  extends: ['standard'],
  parserOptions: {
    ecmaVersion: 'latest'
  },
  rules: {
    'semi': ['error', 'always'],
    'indent': ['error', 'tab'],
    'no-tabs': 'off'
  }
};